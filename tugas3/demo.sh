#!/usr/bin/env bash
# Skrip bantu demo Tugas 3 EPL (Kelompok 8).
# Jalankan dari root repo FreeScout:
#   PowerShell / CMD :  .\tugas3\demo <perintah>     (memanggil Git Bash, bukan WSL)
#   Git Bash         :  bash tugas3/demo.sh <perintah>
#
# Perintah:
#   siap      cek MySQL, config:clear, pakai versi post, isi data demo
#   server    nyalakan web server di http://127.0.0.1:8000 (biarkan terminal ini terbuka)
#   versi     tampilkan FetchEmails.php yang sedang aktif (pre / post)
#   pre       pakai FetchEmails.php versi asli + isi ulang data demo
#   post      pakai FetchEmails.php versi hasil refactor + isi ulang data demo
#   log       daftar commit dari pre-refactoring sampai post-refactoring
#   langkah N tampilkan diff langkah refactoring RN (N = 1..8)
#   golden    jalankan golden master (37 skenario, sekitar 6 detik)
#   unit      jalankan 17 unit test baru
#   semua     jalankan seluruh test suite (sekitar 25 detik)
#   mutan     suntikkan mutan "== 16" -> "!= 16", jalankan golden master, lalu pulihkan
#
# Semua perintah memakai database freescout-test. Database utama freescout_db tidak disentuh.

set -e
CMD="${1:-}"
cd "$(dirname "$0")/.."
export DB_DATABASE=freescout-test DB_USERNAME=freescout-test DB_PASSWORD=freescout-test
export MSYS_NO_PATHCONV=1
F=app/Console/Commands/FetchEmails.php
MYSQLADMIN=/c/dgszdrhat/mysql/bin/mysqladmin.exe
COMMITS=(d059c13c bdc95513 e3667d89 d2a4f1ff 0fc8fe5b 11044b4e 41b36d4c d88ef041)

judul() { printf '\n\033[1;33m== %s ==\033[0m\n' "$*"; }

cek_mysql() {
  if [ ! -x "$MYSQLADMIN" ]; then
    echo "Skrip ini sedang dijalankan dari WSL, bukan Git Bash."
    echo "Dari PowerShell pakai:  .\\tugas3\\demo $CMD"
    exit 1
  fi
  if ! "$MYSQLADMIN" -u freescout-test -pfreescout-test ping >/dev/null 2>&1; then
    echo "MySQL belum menyala. Buka XAMPP Control Panel, klik Start pada MySQL, lalu ulangi."
    exit 1
  fi
}

versi() {
  if git diff --quiet post-refactoring -- "$F"; then echo "post (hasil refactor)"
  elif git diff --quiet pre-refactoring -- "$F"; then echo "pre (kode asli)"
  else echo "DIUBAH, bukan pre maupun post. Pulihkan dengan: bash tugas3/demo.sh post"
  fi
}

# Test memakai database freescout-test yang sama dengan UI dan mengosongkannya.
# Setelah test selesai, data demo diisi ulang supaya akun admin dan inbox kembali.
isi_ulang() {
  php tugas3/demo_seed.php >/dev/null
  echo "(Data demo diisi ulang. Kalau browser minta login: admin@demo.test / demo12345)"
}
phpunit() { # jalankan phpunit tanpa menghentikan skrip saat ada test gagal
  php vendor/bin/phpunit "$@" || true
  isi_ulang
}

pakai() { # $1 = pre | post
  git checkout "$1-refactoring" -- "$F"
  git reset -q -- "$F"            # jangan biarkan versi pre tertinggal di staging area
  php tugas3/demo_seed.php
  judul "FetchEmails.php sekarang: $(versi). Refresh browser."
}

case "$1" in
  siap)
    cek_mysql
    php artisan config:clear >/dev/null
    pakai post
    judul "Siap. Buka terminal kedua: .\tugas3\demo server"
    ;;
  server)
    cek_mysql
    judul "Server: http://127.0.0.1:8000/login  (admin@demo.test / demo12345). Ctrl+C untuk berhenti."
    php -S 127.0.0.1:8000 -t public server.php
    ;;
  versi) versi ;;
  pre|post) cek_mysql; pakai "$1" ;;
  log)
    judul "Commit dari pre-refactoring sampai post-refactoring"
    git --no-pager log --oneline --reverse pre-refactoring..post-refactoring
    ;;
  langkah)
    n="${2:-}"
    if ! [[ "$n" =~ ^[1-8]$ ]]; then echo "Contoh: bash tugas3/demo.sh langkah 1"; exit 1; fi
    git show --color=always --format='%C(yellow)%h%Creset %s%n%n%b' --stat --patch "${COMMITS[$((n-1))]}" -- "$F" | less -R
    ;;
  golden)
    cek_mysql
    judul "Golden master, FetchEmails.php: $(versi)"
    phpunit tests/Feature/FetchEmailsCharacterizationTest.php
    ;;
  unit)
    cek_mysql
    judul "17 unit test untuk method hasil ekstraksi"
    phpunit tests/Unit/FetchEmailsExtractedMethodsTest.php
    ;;
  semua)
    cek_mysql
    judul "Seluruh test suite (harusnya 50 test, 118 assertion, 1 failure SSRF bawaan)"
    phpunit
    ;;
  mutan)
    if ! git diff --quiet post-refactoring -- "$F"; then
      echo "Jalankan dulu: bash tugas3/demo.sh post"; exit 1
    fi
    cek_mysql
    trap 'git checkout post-refactoring -- "$F"; git reset -q -- "$F"; judul "Mutan dicabut, FetchEmails.php kembali: $(versi)"; isi_ulang' EXIT
    sed -i 's/strlen(\$hash) == 16/strlen($hash) != 16/' "$F"
    judul "Mutan disuntikkan di getVerifiedThreadId():"
    git --no-pager diff --color=always -U1 -- "$F"
    judul "Golden master dengan mutan (harusnya GAGAL)"
    php vendor/bin/phpunit tests/Feature/FetchEmailsCharacterizationTest.php | grep -E "Failed asserting|FAILURES|^OK|^Tests:" || true
    ;;
  *)
    sed -n '2,20p' "$0"
    ;;
esac
