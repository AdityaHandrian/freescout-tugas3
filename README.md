# Tugas 3 EPL · Kelompok 8: Refactoring dan Testing FreeScout

Repo ini berisi kode FreeScout v1.8.240 yang dipakai Kelompok 8 untuk Tugas 3 Evolusi Perangkat Lunak: refactoring `FetchEmails::processMessage()` beserta pengujiannya. README ini memuat semua yang perlu didemokan dan dipresentasikan. README asli FreeScout dipindah ke [README.freescout.md](README.freescout.md).

## Tentang repo ini

| Branch | Isi | Commit kode (sama dengan laporan) |
|---|---|---|
| [`pre-refactoring`](../../tree/pre-refactoring) | Kode asli + golden master characterization test | `88bac7c6` |
| [`post-refactoring`](../../tree/post-refactoring) | Hasil refactoring R1–R8 + 17 unit test + script data demo | `d01a48cb` |

Tag `pre-refactoring` dan `post-refactoring` menunjuk ke kedua commit itu. README dan pembungkus demo (`tugas3/demo.sh`, `tugas3/demo.cmd`) ditambahkan dengan isi yang sama di kedua branch, jadi perbandingan pre dan post hanya menunjukkan perubahan kode.

**File yang diubah kelompok:**

| File | Keterangan |
|---|---|
| `app/Console/Commands/FetchEmails.php` | Method yang di-refactor (R1–R8) |
| `tests/Feature/FetchEmailsCharacterizationTest.php` | Golden master, 37 skenario email |
| `tests/Fixtures/golden/fetch_emails_process_message.json` | Rekaman perilaku kode asli |
| `tests/Unit/FetchEmailsExtractedMethodsTest.php` | 17 unit test untuk method hasil ekstraksi (hanya di post) |
| `tugas3/demo_seed.php`, `tugas3/demo.sh`, `tugas3/demo.cmd` | Data demo dan pembungkus perintah demo |

Perbandingan lengkap: [pre-refactoring...post-refactoring](../../compare/pre-refactoring...post-refactoring)

**Ringkasan hasil:**

| Indikator | Sebelum | Sesudah |
|---|---|---|
| CC `processMessage()` | 139 | 65 (−53%) |
| Panjang `processMessage()` | 727 baris | 371 baris (−49%) |
| Nesting maksimum | 8 | 5 |
| Duplikasi validasi hash Message-ID | 3 salinan | 1 |
| Test / assertion (seluruh suite) | 33 / 84 | 50 / 118 |
| Golden master (37 skenario) | lulus | lulus, rekaman sama |
| Mutation score (16 mutan) | 68,8% → 93,8% | 93,8% |
| UI (inbox + 5 percakapan) | — | tidak berubah |

---

**Daftar isi**
0. [Tentang repo ini](#tentang-repo-ini)
1. [Aturan demo](#1-aturan-demo)
2. [Pembagian peran](#2-pembagian-peran)
3. [Persiapan sebelum kelas](#3-persiapan-sebelum-kelas)
4. [Alur demo per orang](#4-alur-demo-per-orang)
5. [Tanya jawab](#5-tanya-jawab)
6. [Kalau waktu mepet atau ada yang macet](#6-kalau-waktu-mepet-atau-ada-yang-macet)

---

## 1. Aturan demo

| Ketentuan | Sumber |
|---|---|
| Selasa, 17 November 2026, mulai 12.30, urutan maju diacak dengan spinwheel | Pengumuman asisten |
| **12 menit** per kelompok | Pengumuman asisten (lembar tugas lama menulis 15 menit) |
| **Tanpa PPT**: langsung dari aplikasi studi kasus | Pengumuman asisten |
| Yang harus ditunjukkan: **source code pre**, **source code post**, **UI pre dan post (tidak boleh berbeda)** | Lembar tugas |
| Laporan PDF diunggah ke Classroom oleh **1 orang**: `Kelompok8_Tugas3_EPL.pdf` | Lembar tugas + pengumuman dosen |

Yang terbuka di laptop saat maju hanya **Antigravity** (`FetchEmails.php` + 2 terminal) dan **browser** FreeScout. PDF laporan boleh dibuka sebagai cadangan, karena bukan PPT.

---

## 2. Pembagian peran

Setiap orang bicara sekitar **3,5 menit**, mengetik perintahnya sendiri, dan memegang satu materi wajib demo.

| | Orang A | Orang B | Orang C |
|---|---|---|---|
| **Waktu bicara** | 0:00 – 3:30 | 3:30 – 7:00 | 7:00 – 10:30 |
| **Materi** | Pembukaan + **source code pre** | **Source code post** + **UI pre/post** | **Bukti testing** + kesimpulan |
| **Bab laporan** | Bab 1 | Bab 2, Bab 3, §4.6 | Bab 4, Bab 5 |
| **Sebelum kelas** | Menyiapkan laptop ([bagian 3](#3-persiapan-sebelum-kelas)) | **Mengunggah PDF ke Classroom** | Latihan dengan stopwatch |
| **Penjaga waktu untuk** | B (beri kode di menit 7:00) | C (beri kode di menit 10:30) | A (beri kode di menit 3:30) |
| **Tanya jawab** | Pemilihan method, code smell, Tugas 2, bug 746 | Teknik refactoring, metrik, UI | Golden master, mutation, SSRF, peran AI |

---

## 3. Persiapan sebelum kelas

**Penanggung jawab: Orang A**, sekitar 5 menit, selesai sebelum 12.30.

Semua perintah diketik di terminal **PowerShell** di Antigravity, dari folder `C:\Users\DURIAN\freescout`. Selalu pakai `.\tugas3\demo <perintah>`. Jangan mengetik `bash tugas3/demo.sh`, karena di PowerShell itu membuka WSL dan MySQL XAMPP tidak terdeteksi. Semua perintah demo memakai database `freescout-test`, jadi database utama tidak tersentuh.

1. **XAMPP Control Panel** → **Start** pada MySQL. Kalau XAMPP menulis "MySQL is already running", itu tidak masalah.
2. **Antigravity**: buka folder `C:\Users\DURIAN\freescout`, lalu buka terminal.
3. Terminal 1: `.\tugas3\demo siap`, tunggu sampai muncul `Siap. Buka terminal kedua ...`.
4. Klik **+** untuk membuka terminal 2: `.\tugas3\demo server`. Biarkan terbuka sampai demo selesai.
5. **Browser**: buka `http://127.0.0.1:8000/login`, login dengan `admin@demo.test` / `demo12345`, lalu buka inbox **Support**.
6. **Antigravity**: buka `app/Console/Commands/FetchEmails.php`, lalu tekan `Ctrl` + `=` beberapa kali agar terbaca dari belakang kelas.
7. Cek: `.\tugas3\demo versi` harus menampilkan `post (hasil refactor)`.
8. Matikan notifikasi dan nyalakan mode jangan ganggu.

Setelah itu tinggal menunggu giliran. Jangan tutup browser maupun kedua terminal.

---

## 4. Alur demo per orang

Cara membaca: **Ketik** = perintah di terminal · **Buka** = `Ctrl+G` di editor lalu ketik nomor baris · teks dalam kutipan = yang diucapkan (cukup pegang poinnya, tidak perlu hafal kata per kata).

### Orang A · Pembukaan dan source code SEBELUM refactoring (0:00 – 3:30)

> Selamat siang. Kami Kelompok 8, dengan studi kasus FreeScout, aplikasi help desk open source berbasis Laravel. Di Tugas 2 kami menduga modul penarik email, FetchEmails, adalah bagian yang paling kompleks dan rawan bug. Di Tugas 3 kami menguji dugaan itu dengan me-refactor method utamanya, `processMessage()`.
>
> Method ini panjangnya 727 baris, cyclomatic complexity 139, nesting 8 level, dan belum punya test. Diukur per method, method ini peringkat kedua di FreeScout. Peringkat pertama, `ConversationsController::ajax` (CC 445), berisi sekitar 40 aksi AJAX yang bergantung pada UI dan sesi, jadi terlalu besar untuk tugas ini. `processMessage()` lebih cocok karena input-nya email dan output-nya baris database, sehingga perilakunya bisa direkam lalu dibandingkan.

**Ketik** `.\tugas3\demo pre`
> Sekarang file FetchEmails.php kami kembalikan ke versi asli.

**Buka baris 383** dan scroll sebentar.
> Ini awal `processMessage()`. Satu method ini menangani lebih dari sepuluh tanggung jawab: memilih pengirim, Message-ID, duplikat, bounce, mencari thread, sampai menyimpan.

**Buka baris 657, lalu 707, lalu 722.**
> Code smell pertama, *Duplicate Code*. Validasi hash Message-ID disalin tiga kali, padahal ini aturan keamanan. Kalau aturannya diubah, satu salinan bisa terlupa. jscpd tidak mendeteksinya karena indeks `$m`-nya berbeda; kami baru menemukannya saat membaca kode.

Kode yang sedang ditunjukkan (salinan pertama):
```php
// (1) notifikasi
$prev_thread_id = '';
if (!empty($m[1]) && !empty($m[3])) {
    $message_id_hash = $m[3];
    if (strlen($message_id_hash) == 16) {
        if ($message_id_hash == \MailHelper::getMessageIdHash($m[1])) {
            $prev_thread_id = $m[1];
        }
    } else {
        // No backward compatibility for security reasons.
    }
}
// (2) balasan ke customer: preg_match(... REPLY_TO_CUSTOMER ...)
//     + blok yang sama dengan $m[2]
// (3) auto reply: preg_match(... AUTO_REPLY ...)
//     + blok yang sama dengan $m[2]
```

**Buka baris 763.**
> Kedua, *Deeply Nested Conditional*: penanganan balasan customer ke thread milik mailbox lain bersarang sampai 8 level.

```php
if ($prev_thread && $message_from_customer) {
    if ($prev_thread->conversation->mailbox_id != $mailbox->id) {
        if ($in_reply_to) {
            ...
            if ($prev_thread_tmp) { ... }
            elseif (\MailHelper::isGeneratedMessageId(...)) {
                ...
                if ($original_id) { ... }
                if ($correct_thread) { $prev_thread = $correct_thread; }
                if (!$correct_thread) { $prev_thread = null; }
            } else {
                ...
                if (!in_array($mailbox->id, $prev_mailbox_ids)) {
                    $prev_thread = null;
                }
            }
            if (!$prev_thread) { $prev_thread = null; $is_reply = false; }
        } else { $prev_thread = null; $is_reply = false; }
    }
}
```

**Buka baris 914.**
> Ketiga, *Complex Conditional*: syarat fitur `@fwd` ditulis dalam satu `if` dengan delapan klausa aktif dan empat klausa yang dikomentari.

**Buka baris 746.**
> Dan ini defect yang kami temukan: `$user->id` dibaca padahal di jalur ini `$user` selalu null. Email yang masuk ke cabang ini gagal diimpor tanpa pesan error. Sengaja tidak kami perbaiki, karena refactoring tidak boleh mengubah perilaku; perbaikannya kami usulkan sebagai bug fix terpisah.
>
> Totalnya ada delapan jenis code smell, rinciannya di Bab 1 laporan. Selanjutnya hasil refactoringnya.

### Orang B · Source code SESUDAH refactoring dan UI (3:30 – 7:00)

**Ketik** `.\tugas3\demo post`, lalu `.\tugas3\demo log`
> Refactoring kami kerjakan dalam delapan langkah, R1 sampai R8. Setiap langkah memakai satu teknik dan menjadi satu commit, jadi kalau ada yang rusak, kami tahu langkah mana penyebabnya. Sebelum mengubah kode, test-nya sudah dibuat dulu; itu nanti dijelaskan Orang C.

**Ketik** `.\tugas3\demo langkah 1` (spasi untuk turun, `q` untuk keluar)
> Contohnya R1, *Extract Method*. Tiga blok duplikat tadi diganti satu method, `getVerifiedThreadId()`. Aturan keamanannya sekarang hanya ada di satu tempat.

```php
$prev_thread_id = $this->getVerifiedThreadId($m[1], $m[3] ?? '');      // (1)
...
$prev_thread_id = $this->getThreadIdFromMessageId(
    \MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER, $prev_message_id); // (2)
if (!$prev_thread_id) {
    $prev_thread_id = $this->getThreadIdFromMessageId(
        \MailHelper::MESSAGE_ID_PREFIX_AUTO_REPLY, $prev_message_id);   // (3)
}

public function getVerifiedThreadId($thread_id, $hash)
{
    if (!empty($thread_id) && !empty($hash)
        && strlen($hash) == 16
        && $hash == \MailHelper::getMessageIdHash($thread_id)
    ) {
        return $thread_id;
    }
    return '';
}
```

**Buka baris 383** (sekarang versi post).
> Ini `processMessage()` setelah refactoring. Sekarang terbaca sebagai urutan langkah, dan detail setiap langkah ada di method bernama.

```php
$from = $this->getSenderEmail($message);                          // R7
... Message-ID, Jira, duplikat ...
$prev_message_ids = $this->getPrevMessageIds(...);                // R3
list($is_bounce, $bounced_message_id) = $this->detectBounce(...); // R2
foreach ($prev_message_ids as ...) {         // loop thread sebelumnya
    ... $this->getVerifiedThreadId(...)                           // R1
    ... $this->getThreadIdFromMessageId(...)                      // R1
    ... $this->findPrevThreadInMailbox(...)                       // R4
}
list($body, $is_html) = $this->getMessageBody(...);               // R5
$forwarded = $this->getForwardedAsCustomer(...);                  // R8
$date = $this->getMessageDate($message);                          // R5
$this->queueExtraImports(...);                                    // R5
... saveCustomerThread / saveUserThread, skipMessage(...)         // R6
```

**Buka baris 948.**
> Untuk nesting delapan level tadi kami pakai *guard clause*: setiap kasus langsung `return`, dan nomor isu GitHub per kasus jadi kelihatan.

```php
if ($prev_thread && $message_from_customer
    && $prev_thread->conversation->mailbox_id != $mailbox->id
) {
    $prev_thread = $this->findPrevThreadInMailbox(
        $prev_thread, $in_reply_to, $mailbox);
    if (!$prev_thread) {
        $is_reply = false;
    }
}

public function findPrevThreadInMailbox($prev_thread, $in_reply_to, $mailbox)
{
    if (!$in_reply_to) {
        return null;                        // tanpa In-Reply-To
    }
    $thread = $this->findThreadInMailbox(
        [...generated..., $in_reply_to], $mailbox);
    if ($thread) {
        return $thread;                     // salinan di mailbox ini (#5308)
    }
    if (\MailHelper::isGeneratedMessageId($prev_thread->message_id)) {
        ...                                 // Message-ID asli dari header
        return $this->findThreadInMailbox([$original_id, ...], $mailbox);
    }
    ...                                     // pernah dipindah? (#5590)
    if (!in_array($mailbox->id, $prev_mailbox_ids)) {
        return null;                        // (#5350)
    }
    return $prev_thread;
}
```

> Hasilnya, CC turun dari 139 ke 65, panjang dari 727 ke 371 baris, nesting dari 8 ke 5, dengan 14 method baru. Di tingkat class angkanya hampir sama, karena Extract Method memindahkan kompleksitas, bukan menghilangkannya. Manfaatnya ada di keterbacaan dan karena sekarang bisa diuji per bagian.

**Pindah ke browser** dan refresh inbox Support.
> Sekarang dari sisi pengguna. Data demo ini diproses lewat `processMessage()` hasil refactor.

- Klik **Pesanan #123 belum sampai**: *"email Budi dan balasannya tergabung dalam satu percakapan."*
- Kembali ke inbox:
  - *"ini bounce yang terdeteksi;"*
  - *"form kontak, customer-nya Dewi, diambil dari Reply-To;"*
  - *"email @fwd dari admin, customer-nya Eko, pengirim aslinya."*

**Ketik** `.\tugas3\demo pre`, lalu refresh browser.
> Ini kode asli dengan data yang sama. Tampilannya sama persis.

**Ketik** `.\tugas3\demo post`
> Lalu bagaimana kami membuktikan perilakunya benar-benar sama? Itu bagian berikutnya.

Kalau ditanya langkah refactoring lain, kodenya ada di bawah. Klik untuk membuka.

<details>
<summary><b>R2 · Extract Method: detectBounce()</b></summary>

Sekitar 70 baris deteksi bounce dipindahkan ke method yang mengembalikan [$is_bounce, $bounced_message_id]. Ada empat strategi deteksi: lampiran delivery-status, header Content-Type, pengirim MAILER-DAEMON, dan Return-Path: <>. Urutan pemeriksaan serta nilai $is_bounce dipertahankan apa adanya, termasuk tipe int hasil preg_match, karena nilainya diteruskan ke hook fetch_emails.data_to_save.

**Sebelum**
```php
// Is it a bounce message
$is_bounce = false;
...                                   // ~70 baris: 2 foreach, 6 if, 3 log
$message_header = $this->headerToStr($message->getHeader());
if (!$is_bounce && $message_header) { ... }
...
```

**Sesudah**
```php
$message_header = $this->headerToStr($message->getHeader());

// Bounce detection.
list($is_bounce, $bounced_message_id) =
    $this->detectBounce($message, $attachments, $message_header);
```

</details>

<details>
<summary><b>R3 · Extract Method: getPrevMessageIds()</b></summary>

Logika pengumpulan Message-ID kandidat thread sebelumnya semula terpecah di dua blok yang dipisahkan oleh deteksi bounce dan dekode subjek. Logika ini disatukan. Sumbernya: In-Reply-To, References, marker {#FS:...#} di body, dan Message-ID buatan untuk mailbox saat ini. Dead store $html_body = '' (langsung ditimpa) dan blok komentar $reply_prefixes dihapus.

**Sebelum**
```php
$references = $message->getReferences();
$html_body = '';
$prev_message_ids = array();
if ($references && !is_array($references)) { ... }
if ($in_reply_to) { ... }
if ($references) { if (is_array($references)) { foreach (...) { ... } } }
$html_body = $message->getHTMLBody(false);
$marker_message_id = \MailHelper::fetchMessageMarkerValue($html_body);
...                                   // 75 baris kemudian:
foreach ($prev_message_ids as $prev_message_id) { /* generated IDs */ }
$prev_message_ids = array_values(array_unique($prev_message_ids));
```

**Sesudah**
```php
$html_body = $message->getHTMLBody(false);

// Determine previous Message-IDs.
$prev_message_ids = $this->getPrevMessageIds(
    $in_reply_to, $message->getReferences(), $html_body, $mailbox);
```

</details>

<details>
<summary><b>R5 · Extract Method: body, tanggal, dan import multi-mailbox</b></summary>

Tiga langkah independen dipindahkan ke method bernama:

**Sebelum**
```php
$date = $this->attrToDate($message->getDate());
if ($date) {
    $app_timezone = config('app.timezone');
    if ($app_timezone) { $date->setTimezone($app_timezone); }
}
$now = now();
if (!$date || $date->greaterThan($now)) { $date = $now; }
...
$recipient_emails = array_unique(...);
if (count($mailboxes) && count($recipient_emails) > 1) {
    foreach ($mailboxes as $check_mailbox) { ... foreach (...) { ... } }
}
```

**Sesudah**
```php
list($body, $is_html) = $this->getMessageBody($message, $html_body);
...
$date = $this->getMessageDate($message);
...
$this->queueExtraImports($message, $message_id, $mailbox, $mailboxes);
```

</details>

<details>
<summary><b>R6 · Extract Method: logging dan skip message</b></summary>



**Sebelum**
```php
// prefix ini muncul 26x (line(), info(), error())
$this->line('['.date('Y-m-d H:i:s').'] Message from: Customer');
...
// rangkaian berikut muncul 7x
$this->logError('User not found: '.$user_id);
$this->setSeen($message, $mailbox);
return;
...
$this->logError('Error: '.$e->getMessage().'; File: '.$e->getFile()
    .' ('.$e->getLine().')').')';
```

**Sesudah**
```php
$this->logInfo('Message from: Customer');
...
$this->skipMessage($message, $mailbox, 'User not found: '.$user_id);
return;
...
$this->logError('Error: '.$e->getMessage().'; File: '.$e->getFile()
    .' ('.$e->getLine().')');
```

</details>

<details>
<summary><b>R7 · Extract Method: getSenderEmail()</b></summary>

Pemilihan pengirim (Reply-To yang valid, atau From) dipindahkan ke method sendiri, dan blok kode terkomentari terkait isu #2833 dihapus. Bentuk if (!$from) {...; return;} else { $from = $from[0]; } disederhanakan karena else setelah return tidak diperlukan. Pemanggilan formatEmailList() dipertahankan dua kali dan dalam urutan yang sama, karena method itu mengubah objek alamat secara langsung (side effect sanitasi).

**Sebelum**
```php
$from = $message->getReplyTo();

if (!$from
    // https://github.com/freescout-helpdesk/freescout/issues/3101
    || !($reply_to = $this->formatEmailList($from))
    || empty($reply_to[0])
    || preg_match('/^.+@unknown$/', $reply_to[0])
) {
    $from = $message->getFrom();
}
// https://github.com/freescout-helpdesk/freescout/issues/2833
/*else {
    // If this is an auto-responder do not use Reply-To as sender email.
    ...
}*/

if ($from) {
    // Also sanitizes email address.
    $from = $this->formatEmailList($from);
}

if (!$from) {
    $this->skipMessage($message, $mailbox, 'From is empty');
    return;
} else {
    $from = $from[0];
}
```

**Sesudah**
```php
$from = $this->getSenderEmail($message);

if (!$from) {
    $this->skipMessage($message, $mailbox, 'From is empty');
    return;
}

public function getSenderEmail($message)
{
    $from = $message->getReplyTo();

    if (!$from
        || !($reply_to = $this->formatEmailList($from))
        || empty($reply_to[0])
        || preg_match('/^.+@unknown$/', $reply_to[0])
    ) {
        $from = $message->getFrom();
    }

    if ($from) {
        // Also sanitizes email address.
        $from = $this->formatEmailList($from);
    }

    return $from ? $from[0] : '';
}
```

</details>

<details>
<summary><b>R8 · Decompose Conditional: @fwd (forward sebagai customer)</b></summary>



**Sebelum**
```php
if (// Some mail clients to not add "In-Reply-To" header ...
    //$in_reply_to
    //&& ($fwd_body = $html_body ?: $message->getTextBody())
    $body
    //&& preg_match("/^(".implode('|', \MailHelper::$fwd_prefixes)."):(.*)/i", ...
    && preg_match("/^[[:alpha:]]{1,3}\s*:(.*)/i", $subject, $m)
    //&& !empty($m[1])
    && !$user_id && !$is_reply && !$prev_thread
    && count($to) == 1 && count($cc) == 0
    && preg_match("/^[\s]*".self::FWD_AS_CUSTOMER_COMMAND."/su", ...)
) {
    $original_sender = $this->getOriginalSenderFromFwd($body);
    if ($original_sender) {
        $sender_is_user = ...;
        if (!$sender_is_user) { ... }
        if ($sender_is_user) {
            $from = ...; $subject = ...; $body = ...;
            $message_from_customer = true;
        }
    }
}
```

**Sesudah**
```php
if (!$user_id && !$is_reply && !$prev_thread) {
    $forwarded = $this->getForwardedAsCustomer($from, $subject, $body, $to, $cc);
    if ($forwarded) {
        list($from, $subject, $body) = $forwarded;
        $message_from_customer = true;
    }
}
// getForwardedAsCustomer(): 4 guard clause bernama,
// mengembalikan [from, subject, body] atau null
```

</details>


### Orang C · Testing dan kesimpulan (7:00 – 10:30)

> Masalah awalnya, method ini belum punya test. Jadi kami memakai *characterization test*, atau *golden master*. Kami kirim 37 email berbeda ke `processMessage()`: email baru, balasan, bounce, duplikat, `@fwd`, Jira, sampai email yang memicu bug tadi. Efeknya direkam: baris baru dan berubah di 9 tabel database, output konsol, dan pesan yang ditandai sudah dibaca. Rekaman dari kode asli jadi patokan, dan kode hasil refactor harus menghasilkan rekaman yang sama.

**Ketik** `.\tugas3\demo golden` (±6 detik, hasil `OK`)
> Hasilnya OK. Ke-37 skenario menghasilkan rekaman yang sama. Tapi test yang lulus belum tentu test yang bagus, jadi test-nya kami uji dengan *mutation testing*.

**Ketik** `.\tugas3\demo mutan` (±8 detik, hasil `FAILURES`)
> Skrip ini sengaja merusak kode: "sama dengan 16" diubah menjadi "tidak sama dengan 16". Golden master langsung gagal, artinya test kami menangkap perubahan itu. File-nya lalu otomatis dikembalikan.
>
> Dari 16 mutan, awalnya hanya 11 yang tertangkap, 68,8 persen. Setelah skenario ditambah, 15 dari 16, atau 93,8 persen. Satu yang tersisa, M16, awalnya kami kira mutan ekuivalen, ternyata subjek email yang dikodekan dua lapis bisa membedakannya. Jadi itu celah skenario yang kami masukkan ke rekomendasi.

**Ketik** `.\tugas3\demo semua` (±25 detik, bicara sambil menunggu)
> Selain golden master, ada 17 unit test baru untuk method hasil ekstraksi, misalnya batas panjang hash 15, 16, dan 17 karakter. Unit test ini baru bisa ditulis setelah refactoring, karena sebelumnya logikanya tercampur di method 727 baris.
>
> Hasilnya 50 test, 118 assertion, 1 failure. Failure ini dari SsrfProtectionTest dan sudah ada sejak sebelum refactoring; di Tugas 2 juga muncul. Penyebabnya, di Windows fungsi `gethostbyname()` tidak menormalisasi alamat `127.0.1`.

**Penutup:**
> Kesimpulannya, dugaan Tugas 2 sebagian besar terbukti. Kompleksitas `processMessage()` turun sekitar separuh tanpa mengubah perilaku yang kami rekam, dibuktikan dengan golden master, regression test, mutation testing, dan perbandingan UI. Dua defect yang kami temukan kami catat sebagai rekomendasi, bukan diperbaiki diam-diam. Langkah berikutnya, FetchEmails masih perlu dipecah dengan Extract Class. Kodenya ada di GitHub, branch pre-refactoring dan post-refactoring. Terima kasih, kami siap menerima pertanyaan.

---

## 5. Tanya jawab

Waktu: 10:30 – 12:00. Yang menjawab adalah pemilik topiknya, tetapi anggota lain boleh menambahkan.

| Pertanyaan | Dijawab | Jawaban |
|---|---|---|
| Kenapa tidak memilih method dengan CC tertinggi? | A | `ConversationsController::ajax` (CC 445) berisi sekitar 40 aksi AJAX yang bergantung pada sesi, izin, dan UI, jadi butuh puluhan end-to-end test. Input/output `processMessage()` jelas, sehingga perilakunya bisa direkam dulu. |
| Apa hubungannya dengan Tugas 2? | A | Tugas 3 menindaklanjuti tiga temuan Tugas 2: hipotesis FetchEmails rawan bug (jadi objek refactoring), hipotesis test bergantung pada database fisik (database test dibuat, sehingga 3 error hilang), dan failure SSRF yang kini penyebabnya diketahui. |
| Tugas 2 bilang FetchEmails punya CC tertinggi, kenapa sekarang peringkat kedua? | A | Tugas 2 memakai metrik tingkat class dari PhpMetrics; Tugas 3 mengukur per method. Karena itu hipotesisnya ditulis "sebagian besar terbukti". |
| Kenapa bug baris 746 tidak diperbaiki? | A | Memperbaikinya mengubah perilaku. Kalau dicampur dengan refactoring, perubahan karena refactoring dan karena bug fix tidak bisa dibedakan. Perbaikannya diusulkan sebagai commit terpisah. |
| Kenapa WMC justru naik? | B | Extract Method memindahkan kompleksitas ke method baru, tidak menghilangkannya. Yang membaik: tidak ada lagi satu method dengan 139 jalur, dan logikanya bisa diuji per bagian. |
| Kenapa tidak sekalian Extract Class? | B | Extract Class mengubah struktur dependensi dan butuh dependency injection lewat Laravel. Lebih aman dikerjakan di iterasi berikutnya setelah method kecilnya stabil dan teruji. |
| Bagaimana kalian tahu UI tidak berubah? | B | Script seed yang sama dijalankan pada kode pre dan post. Teks inbox dan kelima percakapan sama persis, dan file screenshot-nya sama byte per byte. |
| Apa bedanya golden master dengan unit test biasa? | C | Golden master tidak menulis hasil yang "benar" secara manual. Ia merekam perilaku kode asli, termasuk yang salah, lalu memastikan kode baru sama. Cocok untuk kode lama yang belum punya test. |
| Kenapa mutation score tidak 100%? | C | M16 (menghapus `iconvMimeDecode()`) awalnya dikira ekuivalen, ternyata subjek yang dikodekan dua lapis bisa membedakannya. Itu celah skenario dan sudah masuk rekomendasi; skornya tidak kami ubah. |
| Kenapa ada 1 test yang gagal? | C | `SsrfProtectionTest` dengan input `127.0.1`. Di Windows, `gethostbyname()` tidak menormalisasi alamat singkat itu. Failure ini sudah ada sebelum refactoring. |
| Kenapa retest-all, bukan regression test selection? | C | Seluruh suite hanya sekitar 25 detik, jadi menjalankan semuanya setiap langkah lebih aman dan tetap murah. |
| Apa peran AI? | C | Membantu membaca kode, mengusulkan smell dan teknik, menulis draf kode dan test, dan menjalankan alat analisis. Setiap usulan baru diterima kalau golden master, suite, dan mutation testing lulus. Beberapa kesalahan justru ketahuan dari verifikasi ini. |

---

## 6. Kalau waktu mepet atau ada yang macet

**Waktu mepet:** penjaga waktu memberi kode, lalu bagian berikut boleh dilewati.

| Pergantian | Paling lambat | Yang dilewati kalau terlambat |
|---|---|---|
| A → B | 3:30 | Baris 763 dan 914; cukup sebut "ada juga nesting 8 level dan kondisi `@fwd` yang rumit" |
| B → C | 7:00 | `demo langkah 1`; langsung buka baris 383 versi post |
| C → tanya jawab | 10:30 | Jangan jalankan `demo semua`; cukup ucapkan hasilnya (50 test, 118 assertion, 1 failure SSRF bawaan) |

**Ada yang macet:**

| Masalah | Ditangani | Solusi |
|---|---|---|
| "MySQL belum menyala" | A | XAMPP → Start MySQL, ulangi perintah |
| Muncul "dijalankan dari WSL" | A | Salah ketik `bash tugas3/demo.sh`; pakai `.\tugas3\demo <perintah>` |
| Browser tidak bisa dibuka | A | Terminal 2 tertutup; jalankan lagi `.\tugas3\demo server` |
| Port 8000 dipakai | A | Tutup terminal server lama atau restart Antigravity |
| Inbox kosong atau diminta login lagi | B | Test memakai database yang sama dengan UI. Jalankan `.\tugas3\demo post`, lalu login ulang `admin@demo.test` / `demo12345`. Jangan menjalankan `php vendor/bin/phpunit` langsung |
| Ragu file sedang versi apa | yang sedang bicara | `.\tugas3\demo versi`; kembalikan dengan `.\tugas3\demo post` |
| Perintah error saat demo | yang sedang bicara | Ucapkan: *"Mohon maaf, ada kendala lokal. Hasilnya ada di laporan, Bagian 4.5 untuk test dan 4.6 untuk UI."* Lalu lanjut ke bagian berikutnya |

**Setelah demo:** Orang A menjalankan `.\tugas3\demo versi` dan memastikan hasilnya `post (hasil refactor)`.
