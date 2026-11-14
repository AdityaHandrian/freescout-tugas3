<?php
/**
 * Tugas 3 EPL - demo seed for UI comparison (pre vs post refactoring).
 *
 * Resets the FreeScout database (freescout_db from .env!), creates an admin
 * user + "Support" mailbox and feeds sample e-mails through
 * FetchEmails::processMessage() - the method that was refactored.
 * Run the same script on the pre-refactoring and post-refactoring code:
 * the inbox in the web UI must look the same.
 *
 * Usage (from the FreeScout root):
 *   php tugas3/demo_seed.php [admin_email] [admin_password] [--reset-database]
 *
 * SAFETY: all tables of the configured database are TRUNCATED. The script
 * refuses to run unless the database name contains "test" or the
 * --reset-database flag is given explicitly. Recommended: point it to a
 * separate demo database, e.g.
 *   DB_DATABASE=freescout-test DB_USERNAME=freescout-test DB_PASSWORD=freescout-test php tugas3/demo_seed.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$force = in_array('--reset-database', $argv);
$argv = array_values(array_filter($argv, function ($a) { return $a !== '--reset-database'; }));
$db_name = config('database.connections.'.config('database.default').'.database');
if (stripos($db_name, 'test') === false && !$force) {
    fwrite(STDERR, "Refusing to reset database '$db_name'. Use a *test* database or pass --reset-database.\n");
    exit(1);
}

use App\Console\Commands\FetchEmails;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Webklex\PHPIMAP\Message;

$admin_email = $argv[1] ?? 'admin@demo.test';
$admin_password = $argv[2] ?? 'demo12345';

config(['mail.driver' => 'log', 'app.use_mail_date_on_fetching' => true]);
// Initialise static Webklex options used by Message::fromString().
new \Webklex\PHPIMAP\ClientManager();

// 1. Reset database (keep migrations).
$db = \DB::connection();
$db->statement('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->select('SHOW TABLES') as $row) {
    $table = array_values((array)$row)[0];
    if (!in_array($table, ['migrations', 'ltm_translations'])) {
        $db->statement('TRUNCATE TABLE `'.$table.'`');
    }
}
$db->statement('SET FOREIGN_KEY_CHECKS=1');
\Cache::flush();

// 2. Admin user and mailbox.
$user = new User();
$user->first_name = 'Admin';
$user->last_name = 'Demo';
$user->email = $admin_email;
$user->password = \Hash::make($admin_password);
$user->role = User::ROLE_ADMIN;
$user->save();

$mailbox = new Mailbox();
$mailbox->name = 'Support';
$mailbox->email = 'support@toko.test';
$mailbox->save();
$mailbox->users()->sync([$user->id]);
$mailbox->syncPersonalFolders();

// 3. FetchEmails without IMAP flagging.
$cmd = new class extends FetchEmails {
    public function __construct()
    {
        parent::__construct();
        $this->output = new OutputStyle(new ArrayInput([]), new ConsoleOutput());
    }
    public function setSeen($message, $mailbox)
    {
    }
};
$cmd->mailbox = $mailbox;

$process = function ($headers, $body, $content_type = 'text/plain; charset=UTF-8') use ($cmd, $mailbox) {
    $raw = '';
    foreach (array_merge(['MIME-Version' => '1.0', 'Content-Type' => $content_type], $headers) as $k => $v) {
        $raw .= "$k: $v\r\n";
    }
    $raw .= "\r\n".$body."\r\n";
    $message = Message::fromString($raw);
    $raw_body = substr($raw, strpos($raw, "\r\n\r\n") + 4);
    \Closure::bind(function () use ($raw_body) { $this->raw_body = $raw_body; }, $message, Message::class)();
    $cmd->processMessage($message, trim((string)$message->getMessageId(), '<>'), $mailbox, collect([$mailbox]));
};

$process(['Date' => 'Mon, 02 Nov 2026 08:15:00 +0000', 'From' => 'Budi Santoso <budi@pelanggan.test>', 'To' => 'support@toko.test',
    'Subject' => 'Pesanan #123 belum sampai', 'Message-ID' => '<d1@pelanggan.test>'],
    "Halo tim support,\r\npesanan saya #123 belum sampai padahal sudah 5 hari.\r\nTerima kasih.");
$process(['Date' => 'Mon, 02 Nov 2026 09:30:00 +0000', 'From' => 'Citra Lestari <citra@pelanggan.test>', 'To' => 'support@toko.test',
    'Subject' => 'Minta penawaran harga grosir', 'Message-ID' => '<d2@pelanggan.test>'],
    '<p>Selamat pagi, mohon <b>penawaran harga grosir</b> untuk 100 unit.</p>', 'text/html; charset=UTF-8');
$process(['Date' => 'Mon, 02 Nov 2026 10:05:00 +0000', 'From' => 'Budi Santoso <budi@pelanggan.test>', 'To' => 'support@toko.test',
    'Subject' => 'Re: Pesanan #123 belum sampai', 'Message-ID' => '<d3@pelanggan.test>', 'In-Reply-To' => '<d1@pelanggan.test>'],
    'Tambahan: nomor resi saya JNE123456.');
$process(['Date' => 'Mon, 02 Nov 2026 11:00:00 +0000', 'From' => 'MAILER-DAEMON@mx.pelanggan.test', 'To' => 'support@toko.test',
    'Subject' => 'Undelivered Mail Returned to Sender', 'Message-ID' => '<d4@mx.pelanggan.test>'],
    'This is the mail system. Your message could not be delivered.');
$process(['Date' => 'Mon, 02 Nov 2026 12:45:00 +0000', 'From' => 'noreply@form.toko.test', 'Reply-To' => 'Dewi <dewi@pelanggan.test>',
    'To' => 'support@toko.test', 'Subject' => 'Form kontak: garansi', 'Message-ID' => '<d5@form.toko.test>'],
    'Berapa lama garansi untuk produk elektronik?');
$process(['Date' => 'Mon, 02 Nov 2026 13:20:00 +0000', 'From' => 'Admin Demo <'.$admin_email.'>', 'To' => 'support@toko.test',
    'Subject' => 'Fwd: Komplain barang rusak', 'Message-ID' => '<d6@demo.test>'],
    "@fwd\r\n\r\n---------- Forwarded message ---------\r\nFrom: Eko <eko@pelanggan.test>\r\n\r\nBarang yang saya terima rusak.");

echo PHP_EOL.'Conversations: '.\App\Conversation::count().', threads: '.Thread::count().PHP_EOL;
echo 'Login: '.config('app.url').'/login  ('.$admin_email.')'.PHP_EOL;
