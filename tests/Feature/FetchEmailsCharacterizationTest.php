<?php

namespace Tests\Feature;

use App\Conversation;
use App\Console\Commands\FetchEmails;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Characterization (golden master) test for FetchEmails::processMessage().
 *
 * Tugas 3 EPL - Refactoring dan Testing.
 *
 * The test feeds a fixed sequence of e-mail scenarios into processMessage()
 * and records every observable effect: rows written to the database
 * (conversations, threads, customers, emails, send logs, activity logs),
 * console output and which messages were flagged as "Seen".
 *
 * The snapshot is recorded once on the PRE-refactoring code
 * (UPDATE_GOLDEN=1 vendor/bin/phpunit --filter FetchEmailsCharacterization)
 * and every later run (POST-refactoring) must reproduce it byte for byte.
 */
class FetchEmailsCharacterizationTest extends TestCase
{
    const GOLDEN = __DIR__.'/../Fixtures/golden/fetch_emails_process_message.json';

    /** @var Mailbox */
    protected $mailbox;
    /** @var Mailbox */
    protected $mailbox2;
    /** @var User */
    protected $user;
    /** @var Mailbox Mailbox without IMAP settings (inactive). */
    protected $mailbox3;

    protected $started_at;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed, valid APP_KEY: needed for encrypting mailbox password and
        // makes Message-ID hashes (MailHelper::getMessageIdHash) deterministic.
        config([
            'app.key'              => 'base64:'.base64_encode('tugas3-epl-kelompok8-golden-key!'),
            'mail.driver'          => 'log',
            'broadcasting.default' => 'log',
            'app.timezone'         => 'UTC',
            // Store the e-mail Date header as created_at (makes date handling observable).
            'app.use_mail_date_on_fetching' => true,
        ]);
        $this->started_at = date('Y-m-d H:i:s', time() - 120);
        app()->forgetInstance('encrypter');

        $this->resetDatabase();

        $this->user = new User();
        $this->user->first_name = 'Agen';
        $this->user->last_name = 'Satu';
        $this->user->email = 'agent@agents.test';
        $this->user->password = bcrypt('secret-password');
        $this->user->role = User::ROLE_ADMIN;
        $this->user->save();

        $this->mailbox = $this->makeMailbox('Support', 'support@toko.test');
        $this->mailbox2 = $this->makeMailbox('Sales', 'sales@toko.test');
        $this->mailbox3 = $this->makeMailbox('Billing', 'billing@toko.test', false);
    }

    protected function resetDatabase()
    {
        $db = \DB::connection();
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->select('SHOW TABLES') as $row) {
            $table = array_values((array)$row)[0];
            if (in_array($table, ['migrations', 'ltm_translations'])) {
                continue;
            }
            $db->statement('TRUNCATE TABLE `'.$table.'`');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        \Cache::flush();
    }

    protected function makeMailbox($name, $email, $imap_active = true)
    {
        $mailbox = new Mailbox();
        $mailbox->name = $name;
        $mailbox->email = $email;
        if ($imap_active) {
            $mailbox->in_server = 'imap.toko.test';
            $mailbox->in_protocol = Mailbox::IN_PROTOCOL_IMAP;
            $mailbox->in_port = 993;
            $mailbox->in_username = $email;
            $mailbox->in_password = 'imap-password';
        }
        $mailbox->save();
        $mailbox->users()->sync([$this->user->id]);
        $mailbox->syncPersonalFolders();

        return $mailbox;
    }

    /**
     * FetchEmails with IMAP side effects (setFlag) and console output captured.
     */
    protected function makeCommand()
    {
        return new class extends FetchEmails {
            public $seen = [];
            public $buffer;

            public function __construct()
            {
                parent::__construct();
                $this->buffer = new BufferedOutput();
                $this->output = new OutputStyle(new ArrayInput([]), $this->buffer);
            }

            public function setSeen($message, $mailbox)
            {
                $this->seen[] = (string)$message->getMessageId();
            }
        };
    }

    /**
     * Build RFC 822 message.
     */
    protected function eml(array $headers, $body, $content_type = 'text/plain; charset=UTF-8')
    {
        $first = [];
        if (array_key_exists('Return-Path', $headers)) {
            // MTA puts Return-Path as the very first header.
            $first = ['Return-Path' => $headers['Return-Path']];
            unset($headers['Return-Path']);
        }
        $headers = array_merge($first, [
            'Date'         => 'Mon, 02 Nov 2026 10:00:00 +0000',
            'MIME-Version' => '1.0',
            'Content-Type' => $content_type,
        ], $headers);
        $raw = '';
        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }
            $raw .= $name.': '.$value."\r\n";
        }

        return $raw."\r\n".$body."\r\n";
    }

    protected function process(FetchEmails $cmd, $raw, Mailbox $mailbox, $extra = false)
    {
        $message = Message::fromString($raw);
        // Message::fromString() does not keep raw body (normally loaded from IMAP).
        $raw_body = substr($raw, strpos($raw, "\r\n\r\n") + 4);
        \Closure::bind(function () use ($raw_body) {
            $this->raw_body = $raw_body;
        }, $message, Message::class)();
        $cmd->mailbox = $mailbox;
        $message_id = trim((string)$message->getMessageId(), '<>');
        $cmd->processMessage($message, $message_id, $mailbox, collect([$this->mailbox, $this->mailbox2, $this->mailbox3]), $extra);

        return $message;
    }

    protected function replyHash($thread_id)
    {
        return \MailHelper::getMessageIdHash($thread_id);
    }

    /**
     * The scenarios. Each entry: [label, closure returning [raw, mailbox, extra]].
     */
    protected function scenarios()
    {
        $s = [];

        // S01 New conversation from a customer (plain text).
        $s['S01_new_customer_plain'] = function () {
            return [$this->eml([
                'From'       => 'Budi Santoso <budi@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Pesanan belum sampai',
                'Message-ID' => '<s01@pelanggan.test>',
            ], "Halo, pesanan saya #123 belum sampai.\r\nTerima kasih."), $this->mailbox];
        };

        // S02 New conversation, HTML body, CC to the second mailbox (extra import queued).
        $s['S02_new_customer_html_cc_other_mailbox'] = function () {
            return [$this->eml([
                'From'       => 'Citra <citra@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Cc'         => 'sales@toko.test, billing@toko.test, dewi@pelanggan.test',
                'Subject'    => 'Minta penawaran',
                'Message-ID' => '<s02@pelanggan.test>',
            ], '<html><body><p>Mohon <b>penawaran</b> harga.</p></body></html>', 'text/html; charset=UTF-8'), $this->mailbox];
        };

        // S03 Same e-mail imported into the second mailbox (extra = true).
        $s['S03_extra_import_into_second_mailbox'] = function () {
            return [$this->eml([
                'From'       => 'Citra <citra@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Cc'         => 'sales@toko.test, billing@toko.test, dewi@pelanggan.test',
                'Subject'    => 'Minta penawaran',
                'Message-ID' => '<s02@pelanggan.test>',
            ], '<html><body><p>Mohon <b>penawaran</b> harga.</p></body></html>', 'text/html; charset=UTF-8'), $this->mailbox2, true];
        };

        // S04 Exact duplicate of S01 (same Message-ID and headers) -> skipped.
        $s['S04_duplicate_message'] = function () {
            return [$this->eml([
                'From'       => 'Budi Santoso <budi@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Pesanan belum sampai',
                'Message-ID' => '<s01@pelanggan.test>',
            ], "Halo, pesanan saya #123 belum sampai.\r\nTerima kasih."), $this->mailbox];
        };

        // S05 Same Message-ID as S01 but different headers -> artificial Message-ID.
        $s['S05_same_message_id_different_headers'] = function () {
            return [$this->eml([
                'From'       => 'Budi Santoso <budi@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Pesanan belum sampai (kirim ulang)',
                'Message-ID' => '<s01@pelanggan.test>',
            ], 'Kirim ulang.'), $this->mailbox];
        };

        // S06 Missing Message-ID -> generated from body.
        $s['S06_missing_message_id'] = function () {
            return [$this->eml([
                'From'    => 'eko@pelanggan.test',
                'To'      => 'support@toko.test',
                'Subject' => 'Tanpa message id',
            ], 'Email tanpa Message-ID.'), $this->mailbox];
        };

        // S07 Reply-To header used as the sender.
        $s['S07_reply_to_header'] = function () {
            return [$this->eml([
                'From'       => 'noreply@sistem.test',
                'Reply-To'   => 'Fajar <fajar@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Form kontak',
                'Message-ID' => '<s07@sistem.test>',
            ], 'Pesan dari form kontak.'), $this->mailbox];
        };

        // S08 Reply-To "unknown" -> From is used.
        $s['S08_reply_to_unknown'] = function () {
            return [$this->eml([
                'From'       => 'gita@pelanggan.test',
                'Reply-To'   => 'someone@unknown',
                'To'         => 'support@toko.test',
                'Subject'    => 'Reply to unknown',
                'Message-ID' => '<s08@pelanggan.test>',
            ], 'Isi.'), $this->mailbox];
        };

        // S09 MIME encoded subject.
        $s['S09_mime_encoded_subject'] = function () {
            return [$this->eml([
                'From'       => 'hana@pelanggan.test',
                'To'         => 'support@toko.test',
                'Subject'    => '=?UTF-8?B?'.base64_encode('Pertanyaan tentang garansi ✓').'?=',
                'Message-ID' => '<s09@pelanggan.test>',
            ], 'Garansi berapa lama?'), $this->mailbox];
        };

        // S10 Customer replies to own message via References.
        $s['S10_customer_reply_via_references'] = function () {
            return [$this->eml([
                'From'       => 'Budi Santoso <budi@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Re: Pesanan belum sampai',
                'Message-ID' => '<s10@pelanggan.test>',
                'References' => '<s01@pelanggan.test>',
            ], 'Tambahan info: alamat sudah benar.'), $this->mailbox];
        };

        // S11 Agent reply in conversation #1 is created directly in DB (setup step).
        $s['S11_setup_agent_reply'] = 'setup_agent_reply';

        // S12 Customer replies to agent reply with valid hash (In-Reply-To).
        $s['S12_customer_reply_to_agent_valid_hash'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'Budi Santoso <budi@pelanggan.test>',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: Pesanan belum sampai',
                'Message-ID'  => '<s12@pelanggan.test>',
                'In-Reply-To' => '<FS_reply-'.$t->id.'-'.$this->replyHash($t->id).'@toko.test>',
            ], "Terima kasih, sudah saya terima.\r\n\r\nOn Mon, Agent wrote:\r\n> balasan agen"), $this->mailbox];
        };

        // S13 Customer reply with an invalid hash -> not linked by thread id.
        $s['S13_customer_reply_invalid_hash'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'Budi Santoso <budi@pelanggan.test>',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: Pesanan belum sampai',
                'Message-ID'  => '<s13@pelanggan.test>',
                'In-Reply-To' => '<FS_reply-'.$t->id.'-0000000000000000@toko.test>',
            ], 'Hash palsu.'), $this->mailbox];
        };

        // S14 Customer reply to auto-reply with valid hash.
        $s['S14_customer_reply_to_autoreply'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'Budi Santoso <budi@pelanggan.test>',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: Auto reply',
                'Message-ID'  => '<s14@pelanggan.test>',
                'In-Reply-To' => '<FS_autoreply-'.$t->id.'-'.$this->replyHash($t->id).'@toko.test>',
            ], 'Membalas auto reply.'), $this->mailbox];
        };

        // S15 Customer reply found by marker in the body.
        $s['S15_reply_found_by_body_marker'] = function () {
            $t = $this->agentThread();
            $marker = \MailHelper::getMessageMarker('FS_reply-'.$t->id.'-'.$this->replyHash($t->id).'@toko.test');
            return [$this->eml([
                'From'       => 'Budi Santoso <budi@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Re: Pesanan',
                'Message-ID' => '<s15@pelanggan.test>',
            ], '<div>Balasan via marker</div><div style="display:none">'.$marker.'</div>', 'text/html; charset=UTF-8'), $this->mailbox];
        };

        // S16 Agent replies to the e-mail notification (valid hash).
        $s['S16_agent_reply_to_notification'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'Agen Satu <agent@agents.test>',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: [#1] Pesanan belum sampai',
                'Message-ID'  => '<s16@agents.test>',
                'In-Reply-To' => '<FS_notify-'.$t->id.'-'.$this->user->id.'-'.$this->replyHash($t->id).'@toko.test>',
            ], 'Balasan agen lewat email notifikasi.'), $this->mailbox];
        };

        // S17 Notification reply with invalid hash -> rejected.
        $s['S17_notification_invalid_hash'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'agent@agents.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: notif',
                'Message-ID'  => '<s17@agents.test>',
                'In-Reply-To' => '<FS_notify-'.$t->id.'-'.$this->user->id.'-abcdefabcdefabcd@toko.test>',
            ], 'Hash notifikasi salah.'), $this->mailbox];
        };

        // S18 Notification reply for non existing user.
        $s['S18_notification_unknown_user'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'agent@agents.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: notif',
                'Message-ID'  => '<s18@agents.test>',
                'In-Reply-To' => '<FS_notify-'.$t->id.'-999-'.$this->replyHash($t->id).'@toko.test>',
            ], 'User tidak ada.'), $this->mailbox];
        };

        // S19 Auto-responder replying to the notification -> skipped.
        $s['S19_notification_auto_responder'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'           => 'agent@agents.test',
                'To'             => 'support@toko.test',
                'Subject'        => 'Out of office',
                'Message-ID'     => '<s19@agents.test>',
                'Auto-Submitted' => 'auto-replied',
                'In-Reply-To'    => '<FS_notify-'.$t->id.'-'.$this->user->id.'-'.$this->replyHash($t->id).'@toko.test>',
            ], 'Saya sedang cuti.'), $this->mailbox];
        };

        // S20 Notification reply from a different address than the user's e-mail.
        $s['S20_notification_sender_mismatch'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'other@agents.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: notif',
                'Message-ID'  => '<s20@agents.test>',
                'In-Reply-To' => '<FS_notify-'.$t->id.'-'.$this->user->id.'-'.$this->replyHash($t->id).'@toko.test>',
            ], 'Alamat pengirim lain.'), $this->mailbox];
        };

        // S21 Agent forwards notification ("Fwd:") into another mailbox -> new conversation.
        $s['S21_forwarded_notification_to_other_mailbox'] = function () {
            $t = $this->agentThread();
            return [$this->eml([
                'From'        => 'agent@agents.test',
                'To'          => 'sales@toko.test',
                'Subject'     => 'Fwd: [#1] Pesanan belum sampai',
                'Message-ID'  => '<s21@agents.test>',
                'In-Reply-To' => '<FS_notify-'.$t->id.'-'.$this->user->id.'-'.$this->replyHash($t->id).'@toko.test>',
            ], 'Tolong dibantu tim sales.'), $this->mailbox2];
        };

        // S22 Reply to the agent's own e-mail reply (prev thread found by message_id
        // and created by a user). Exercises FetchEmails.php line ~746.
        $s['S22_reply_to_user_thread_by_message_id'] = function () {
            return [$this->eml([
                'From'        => 'agent@agents.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: Pesanan belum sampai',
                'Message-ID'  => '<s22@agents.test>',
                'In-Reply-To' => '<s16@agents.test>',
            ], 'Agen membalas balasannya sendiri.'), $this->mailbox];
        };

        // S23 Existing user forwards customer's e-mail with @fwd command.
        $s['S23_forward_as_customer'] = function () {
            return [$this->eml([
                'From'       => 'Agen Satu <agent@agents.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Fwd: Komplain barang rusak',
                'Message-ID' => '<s23@agents.test>',
            ], "@fwd\r\n\r\n---------- Forwarded message ---------\r\nFrom: Indra <indra@pelanggan.test>\r\nSubject: Komplain barang rusak\r\n\r\nBarang yang saya terima rusak."), $this->mailbox];
        };

        // S24 Bounce detected by delivery-status attachment, references agent reply.
        $s['S24_bounce_delivery_status'] = function () {
            $t = $this->agentThread();
            $orig = 'FS_reply-'.$t->id.'-'.$this->replyHash($t->id).'@toko.test';
            $b = 'BOUNDARY-S24';
            $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nDelivery to the following recipient failed permanently: budi@pelanggan.test\r\n"
                ."--$b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.pelanggan.test\r\n\r\nFinal-Recipient: rfc822; budi@pelanggan.test\r\nAction: failed\r\nStatus: 5.1.1\r\n"
                ."--$b\r\nContent-Type: message/rfc822\r\n\r\nMessage-ID: <$orig>\r\nFrom: support@toko.test\r\nTo: budi@pelanggan.test\r\nSubject: Re: Pesanan belum sampai\r\n\r\nIsi asli\r\n"
                ."--$b--";
            return [$this->eml([
                'From'       => 'Mail Delivery Subsystem <postmaster@pelanggan.test>',
                'To'         => 'support@toko.test',
                'Subject'    => 'Delivery Status Notification (Failure)',
                'Message-ID' => '<s24@pelanggan.test>',
            ], $body, 'multipart/report; report-type=delivery-status; boundary="'.$b.'"'), $this->mailbox];
        };

        // S25 Bounce detected by MAILER-DAEMON sender.
        $s['S25_bounce_mailer_daemon'] = function () {
            return [$this->eml([
                'From'       => 'MAILER-DAEMON@mx.pelanggan.test',
                'To'         => 'support@toko.test',
                'Subject'    => 'Undelivered Mail Returned to Sender',
                'Message-ID' => '<s25@mx.pelanggan.test>',
            ], 'This is the mail system. Your message could not be delivered.'), $this->mailbox];
        };

        // S26 Bounce detected by empty Return-Path.
        $s['S26_bounce_return_path'] = function () {
            return [$this->eml([
                'Return-Path' => '<>',
                'From'        => 'robot@mx.pelanggan.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'Gagal kirim',
                'Message-ID'  => '<s26@mx.pelanggan.test>',
            ], 'Gagal.'), $this->mailbox];
        };

        // S27 Jira style Message-ID (first message keeps the shortened id).
        $s['S27_jira_message_id'] = function () {
            return [$this->eml([
                'From'       => 'jira@perusahaan.test',
                'To'         => 'support@toko.test',
                'Subject'    => '[JIRA] (PRJ-1) Bug',
                'Message-ID' => '<JIRA.10001.1700000000000.123.456@Atlassian.JIRA>',
            ], 'Issue dibuat.'), $this->mailbox];
        };

        // S28 Jira follow-up threads into the same conversation.
        $s['S28_jira_follow_up'] = function () {
            return [$this->eml([
                'From'        => 'jira@perusahaan.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'RE: [JIRA] (PRJ-1) Bug',
                'Message-ID'  => '<JIRA.10001.1700000000000.789.999@Atlassian.JIRA>',
                'In-Reply-To' => '<JIRA.10001.1700000000000@Atlassian.JIRA>',
            ], 'Komentar baru.'), $this->mailbox];
        };

        // S29 Customer reply whose prev thread belongs to another mailbox, no In-Reply-To.
        $s['S29_prev_thread_other_mailbox_without_in_reply_to'] = function () {
            return [$this->eml([
                'From'       => 'citra@pelanggan.test',
                'To'         => 'sales@toko.test',
                'Subject'    => 'Re: Minta penawaran',
                'Message-ID' => '<s29@pelanggan.test>',
                'References' => '<s01@pelanggan.test>',
            ], 'Balasan ke sales.'), $this->mailbox2];
        };

        // S31 Latent defect probe (FetchEmails.php line ~746, "$user_id = $user->id").
        // A thread created by a user exists with message_id = FS_reply-<id>-<hash>,
        // but thread <id> itself does not exist anymore. The branch
        // "Reply from user to his reply to the notification" is entered while
        // $user is still null -> "Attempt to read property id on null".
        $s['S31_latent_null_user_defect'] = function () {
            $ghost_id = 99999;
            $ghost_message_id = 'FS_reply-'.$ghost_id.'-'.$this->replyHash($ghost_id).'@toko.test';
            $t = $this->agentThread()->replicate();
            $t->message_id = $ghost_message_id;
            $t->created_at = '2026-11-02 11:30:00';
            $t->save();
            return [$this->eml([
                'From'        => 'agent@agents.test',
                'To'          => 'support@toko.test',
                'Subject'     => 'Re: Pesanan belum sampai',
                'Message-ID'  => '<s31@agents.test>',
                'In-Reply-To' => '<'.$ghost_message_id.'>',
            ], 'Probe null user.'), $this->mailbox];
        };

        // S32 @fwd command but e-mail has CC -> NOT treated as forwarded customer e-mail.
        $s['S32_forward_as_customer_with_cc_ignored'] = function () {
            return [$this->eml([
                'From'       => 'Agen Satu <agent@agents.test>',
                'To'         => 'support@toko.test',
                'Cc'         => 'atasan@agents.test',
                'Subject'    => 'Fwd: Komplain kedua',
                'Message-ID' => '<s32@agents.test>',
            ], "@fwd



---------- Forwarded message ---------

From: Joko <joko@pelanggan.test>



Komplain kedua."), $this->mailbox];
        };

        // S33 Date header in the future -> replaced with current time.
        $s['S33_future_date_is_corrected'] = function () {
            return [$this->eml([
                'Date'       => 'Fri, 01 Feb 2030 08:00:00 +0000',
                'From'       => 'kiki@pelanggan.test',
                'To'         => 'support@toko.test',
                'Subject'    => 'Jam komputer salah',
                'Message-ID' => '<s33@pelanggan.test>',
            ], 'Tanggal email di masa depan.'), $this->mailbox];
        };

        // S30 Empty From -> error.
        $s['S30_empty_from'] = function () {
            return [$this->eml([
                'From'       => 'not-an-email',
                'To'         => 'support@toko.test',
                'Subject'    => 'Tanpa pengirim',
                'Message-ID' => '<s30@pelanggan.test>',
            ], 'Isi.'), $this->mailbox];
        };

        return $s;
    }

    protected function agentThread()
    {
        return Thread::where('type', Thread::TYPE_MESSAGE)->orderBy('id')->first();
    }

    protected function setupAgentReply()
    {
        $conversation = Conversation::orderBy('id')->first();
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $this->user->id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = 'Halo Budi, paket sedang dikirim.';
        $thread->to = json_encode(['budi@pelanggan.test']);
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $this->user->id;
        $thread->created_at = '2026-11-02 11:00:00';
        $thread->save();
    }

    /**
     * Collect a deterministic snapshot of the database.
     */
    protected function snapshotDb()
    {
        $ignore = ['updated_at', 'last_reply_at', 'last_customer_reply_at', 'user_updated_at', 'closed_at', 'opened_at', 'read_by_user', 'password', 'remember_token', 'invite_hash', 'file_dir'];
        $tables = [
            'conversations' => 'id', 'threads' => 'id', 'customers' => 'id', 'emails' => 'id',
            'send_logs' => 'id', 'activity_logs' => 'id', 'conversation_folder' => 'conversation_id',
            'attachments' => 'id', 'followers' => 'id',
        ];
        $snap = [];
        foreach ($tables as $table => $order) {
            $rows = \DB::table($table)->orderBy($order)->get()->map(function ($r) use ($ignore, $table) {
                $r = (array)$r;
                foreach ($ignore as $col) {
                    unset($r[$col]);
                }
                // created_at equal to the fetch time ("now") is not deterministic.
                if (!empty($r['created_at']) && $r['created_at'] >= $this->started_at
                    && $r['created_at'] <= date('Y-m-d H:i:s', time() + 120)
                ) {
                    $r['created_at'] = '<now>';
                }
                if ($table == 'activity_logs' && isset($r['properties'])) {
                    $r['properties'] = preg_replace('/\\\\\\/[^"]*?\.php:\d+/', '<path>', $r['properties']);
                }
                return $r;
            })->toArray();
            $snap[$table] = $rows;
        }

        return $snap;
    }

    protected function normalizeOutput($out)
    {
        $out = preg_replace('/\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\]/', '[TS]', $out);
        $out = str_replace("\r\n", "\n", $out);
        $out = preg_replace('/\S+\.php(:\d+)?/', '<file>', $out);
        return $out;
    }

    public function testProcessMessageMatchesGoldenMaster()
    {
        $result = [];
        foreach ($this->scenarios() as $label => $scenario) {
            if ($scenario === 'setup_agent_reply') {
                $this->setupAgentReply();
                $result[$label] = ['db' => $this->snapshotDb()];
                continue;
            }
            $args = $scenario();
            $raw = $args[0];
            $mailbox = $args[1];
            $extra = $args[2] ?? false;
            $cmd = $this->makeCommand();
            $this->process($cmd, $raw, $mailbox, $extra);
            $result[$label] = [
                'output'       => $this->normalizeOutput($cmd->buffer->fetch()),
                'seen'         => $cmd->seen,
                'extra_import' => array_map(function ($e) {
                    return ['mailbox' => $e['mailbox']->email, 'message_id' => $e['message_id']];
                }, $cmd->extra_import),
                'db'           => $this->snapshotDb(),
            ];
        }

        // Keep only the database diff per step to keep the snapshot readable.
        $prev = [];
        foreach ($result as $label => $step) {
            $db = $step['db'];
            $diff = [];
            foreach ($db as $table => $rows) {
                $before = $prev[$table] ?? [];
                $added = array_values(array_filter($rows, function ($r) use ($before) {
                    return !in_array($r, $before);
                }));
                if ($added) {
                    $diff[$table] = $added;
                }
            }
            $prev = $db;
            $result[$label]['db'] = $diff;
        }

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (getenv('UPDATE_GOLDEN')) {
            @mkdir(dirname(self::GOLDEN), 0777, true);
            file_put_contents(self::GOLDEN, $json);
            $this->markTestSkipped('Golden master recorded to '.self::GOLDEN);
        }

        $this->assertFileExists(self::GOLDEN, 'Run once with UPDATE_GOLDEN=1 on pre-refactoring code.');
        // Normalize line endings (git may check the file out with CRLF on Windows).
        $golden = str_replace("\r\n", "\n", file_get_contents(self::GOLDEN));
        $this->assertSame($golden, str_replace("\r\n", "\n", $json));
    }
}
