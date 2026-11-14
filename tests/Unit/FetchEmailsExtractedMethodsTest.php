<?php

namespace Tests\Unit;

use App\Console\Commands\FetchEmails;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Unit tests for the methods extracted from FetchEmails::processMessage()
 * during refactoring (Tugas 3 EPL). Before refactoring this logic was
 * buried inside a 727-line method and could only be tested end-to-end.
 */
class FetchEmailsExtractedMethodsTest extends TestCase
{
    /** @var FetchEmails */
    protected $cmd;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode('tugas3-epl-kelompok8-golden-key!')]);
        $this->cmd = new class extends FetchEmails {
            public function line($string, $style = null, $verbosity = null)
            {
                // Silence console output.
            }
        };
    }

    protected function message($headers, $body = 'Body')
    {
        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= $name.': '.$value."\r\n";
        }
        return Message::fromString($raw."\r\n".$body."\r\n");
    }

    // --- getVerifiedThreadId(): boundary values of hash length ---

    public function testVerifiedThreadIdAcceptsValid16CharHash()
    {
        $hash = \MailHelper::getMessageIdHash(15);
        $this->assertSame(16, strlen($hash));
        $this->assertSame('15', $this->cmd->getVerifiedThreadId('15', $hash));
    }

    public function testVerifiedThreadIdRejectsHashOf15And17Chars()
    {
        $hash = \MailHelper::getMessageIdHash(15);
        $this->assertSame('', $this->cmd->getVerifiedThreadId('15', substr($hash, 0, 15)));
        $this->assertSame('', $this->cmd->getVerifiedThreadId('15', $hash.'a'));
    }

    public function testVerifiedThreadIdRejectsHashOfAnotherThread()
    {
        $this->assertSame('', $this->cmd->getVerifiedThreadId('16', \MailHelper::getMessageIdHash(15)));
    }

    public function testVerifiedThreadIdRejectsEmptyValues()
    {
        $this->assertSame('', $this->cmd->getVerifiedThreadId('', \MailHelper::getMessageIdHash(15)));
        $this->assertSame('', $this->cmd->getVerifiedThreadId('15', ''));
        $this->assertSame('', $this->cmd->getVerifiedThreadId('0', \MailHelper::getMessageIdHash(0)));
    }

    // --- getThreadIdFromMessageId() ---

    public function testThreadIdFromReplyMessageIdWithAndWithoutFsPrefix()
    {
        $hash = \MailHelper::getMessageIdHash(42);
        $this->assertSame('42', $this->cmd->getThreadIdFromMessageId(\MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER, 'FS_reply-42-'.$hash.'@toko.test'));
        // Backward compatible prefix without "FS_".
        $this->assertSame('42', $this->cmd->getThreadIdFromMessageId(\MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER, 'reply-42-'.$hash.'@toko.test'));
        // Prefix of another type does not match.
        $this->assertSame('', $this->cmd->getThreadIdFromMessageId(\MailHelper::MESSAGE_ID_PREFIX_AUTO_REPLY, 'FS_reply-42-'.$hash.'@toko.test'));
        // Missing "@" part.
        $this->assertSame('', $this->cmd->getThreadIdFromMessageId(\MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER, 'FS_reply-42-'.$hash));
    }

    // --- getPrevMessageIds() ---

    public function testPrevMessageIdsOrderAndGeneratedIds()
    {
        $mailbox = new \App\Mailbox();
        $mailbox->id = 7;
        $marker = \MailHelper::getMessageMarker('marker@toko.test');

        $ids = $this->cmd->getPrevMessageIds('a@x.test', '<b@x.test> <a@x.test>', '<div>'.$marker.'</div>', $mailbox);

        $this->assertSame(['a@x.test', 'b@x.test', 'marker@toko.test'], array_slice($ids, 0, 3));
        // Artificial Message-IDs for the current mailbox follow, without duplicates.
        $this->assertContains(\MailHelper::generateMessageId('a@x.test', '7a@x.test'), $ids);
        $this->assertCount(count(array_unique($ids)), $ids);
        $this->assertSame(range(0, count($ids) - 1), array_keys($ids));
    }

    public function testPrevMessageIdsEmpty()
    {
        $mailbox = new \App\Mailbox();
        $mailbox->id = 7;
        $this->assertSame([], $this->cmd->getPrevMessageIds('', null, '', $mailbox));
    }

    // --- getSenderEmail() ---

    public function testSenderEmailPrefersValidReplyTo()
    {
        $m = $this->message(['From' => 'a@x.test', 'Reply-To' => 'B <B@X.test>', 'Subject' => 's']);
        $this->assertSame('b@x.test', $this->cmd->getSenderEmail($m));
    }

    public function testSenderEmailIgnoresUnknownReplyTo()
    {
        $m = $this->message(['From' => 'a@x.test', 'Reply-To' => 'z@unknown', 'Subject' => 's']);
        $this->assertSame('a@x.test', $this->cmd->getSenderEmail($m));
    }

    public function testSenderEmailEmptyWhenNoValidAddress()
    {
        $m = $this->message(['From' => 'not-an-email', 'Subject' => 's']);
        $this->assertSame('', $this->cmd->getSenderEmail($m));
    }

    // --- detectBounce() ---

    public function testDetectBounceByMailerDaemon()
    {
        $m = $this->message(['From' => 'MAILER-DAEMON@mx.test', 'Subject' => 'Undelivered']);
        [$is_bounce, $bounced_id] = $this->cmd->detectBounce($m, $m->getAttachments(), $this->cmd->headerToStr($m->getHeader()));
        $this->assertTrue((bool)$is_bounce);
        $this->assertNull($bounced_id);
    }

    public function testDetectBounceByEmptyReturnPath()
    {
        $m = $this->message(['Return-Path' => '<>', 'From' => 'robot@mx.test', 'Subject' => 'x']);
        [$is_bounce] = $this->cmd->detectBounce($m, $m->getAttachments(), $this->cmd->headerToStr($m->getHeader()));
        $this->assertTrue((bool)$is_bounce);
    }

    public function testRegularMessageIsNotBounce()
    {
        $m = $this->message(['From' => 'budi@x.test', 'Subject' => 'Halo']);
        [$is_bounce, $bounced_id] = $this->cmd->detectBounce($m, $m->getAttachments(), $this->cmd->headerToStr($m->getHeader()));
        $this->assertFalse((bool)$is_bounce);
        $this->assertNull($bounced_id);
    }

    // --- getMessageBody() ---

    public function testPlainTextBodyIsEscaped()
    {
        $m = $this->message(['From' => 'a@x.test', 'Subject' => 's', 'Content-Type' => 'text/plain; charset=UTF-8'], '<script>alert(1)</script>');
        [$body, $is_html] = $this->cmd->getMessageBody($m, '');
        $this->assertFalse($is_html);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    // --- getMessageDate() ---

    public function testFutureDateIsReplacedWithNow()
    {
        $m = $this->message(['From' => 'a@x.test', 'Subject' => 's', 'Date' => 'Fri, 01 Feb 2030 08:00:00 +0000']);
        $date = $this->cmd->getMessageDate($m);
        $this->assertTrue($date->lessThanOrEqualTo(now()));
    }

    public function testPastDateIsKept()
    {
        $m = $this->message(['From' => 'a@x.test', 'Subject' => 's', 'Date' => 'Mon, 02 Nov 2026 10:00:00 +0000']);
        $this->assertSame('2026-11-02', $this->cmd->getMessageDate($m)->format('Y-m-d'));
    }

    // --- getForwardedAsCustomer(): guard clauses (no DB needed) ---

    public function testForwardRequiresFwdSubjectSingleRecipientAndCommand()
    {
        $body = "@fwd\nFrom: Indra <indra@pelanggan.test>\nIsi";
        $this->assertNull($this->cmd->getForwardedAsCustomer('agent@x.test', 'Komplain', $body, ['s@t.test'], []));
        $this->assertNull($this->cmd->getForwardedAsCustomer('agent@x.test', 'Fwd: Komplain', $body, ['s@t.test', 'b@t.test'], []));
        $this->assertNull($this->cmd->getForwardedAsCustomer('agent@x.test', 'Fwd: Komplain', $body, ['s@t.test'], ['c@t.test']));
        $this->assertNull($this->cmd->getForwardedAsCustomer('agent@x.test', 'Fwd: Komplain', "Tanpa perintah\nFrom: indra@pelanggan.test", ['s@t.test'], []));
        $this->assertNull($this->cmd->getForwardedAsCustomer('agent@x.test', 'Fwd: Komplain', '', ['s@t.test'], []));
    }
}
