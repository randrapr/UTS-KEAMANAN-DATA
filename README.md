# Kosan Aman (PHP)

Sistem informasi kos-kosan (landing page + halaman admin) dengan **login memakai private key**
dan seluruh data tersimpan **terenkripsi** (AES-256-GCM + RSA-3072 OAEP). Tanpa framework.

Data yang dienkripsi di database: booking calon penghuni, data penghuni (nama, HP, NIK KTP,
kontak darurat, kamar), data kamar, dan pembayaran sewa.

## Kebutuhan
PHP 8.1+ dengan ekstensi `openssl`, `pdo_sqlite`, `mbstring`.
Laragon/XAMPP: aktifkan `extension=pdo_sqlite`, `extension=sqlite3`, `extension=openssl`,
`extension=mbstring` di `php.ini`, lalu restart server. Bila ada yang kurang, aplikasi menampilkan
pesan yang menyebut ekstensi apa yang belum aktif.

## Menjalankan (paket ZIP sudah berisi database contoh)
```bash
php -S 127.0.0.1:8000 -t public public/index.php     # buka http://127.0.0.1:8000
```
Login admin: buka `/admin/login` (atau `/login`), lalu pilih file **`DEMO_private_key.pem`**
(kunci demo yang cocok dengan database contoh di ZIP).

Mulai dari nol dengan data contoh baru:
```bash
php tools/seed.php --reset    # membuat kunci baru (keys/private_key.pem) + database baru
```
Setelah `--reset` tanpa berkas `keys/private_key.pem`, kunci lama (termasuk `DEMO_private_key.pem`)
**tidak lagi cocok**; login dengan `keys/private_key.pem` yang baru dibuat.

Uji otomatis (17 tes, termasuk uji HTTP nyata): `php tests/run_tests.php`

**Laragon/XAMPP (Apache):** taruh folder ini di `www/` atau `htdocs/`, buka
`http://localhost/<nama-folder>/`. Pastikan `database/` dan `storage/sessions/` dapat ditulis.

## Alur keamanan
1. Landing page: booking dienkripsi dengan **public key** sebelum masuk database.
2. Admin login: memasukkan **private key** (bukan password); divalidasi dengan membuka blob uji.
3. PHP tidak punya memori bersama antar-request, sehingga private key disimpan di berkas sesi
   **dalam bentuk terenkripsi AES-256-GCM**; kuncinya hanya ada di cookie HttpOnly browser admin.
   Berkas sesi saja tidak cukup. Logout / 15 menit tidak aktif menghapus keduanya.
4. Dashboard admin mendekripsi seluruh record (booking, penghuni, kamar, pembayaran).

## Fitur admin
Terima booking dan tempatkan ke kamar kosong, kelola penghuni, kamar, dan pembayaran sewa,
status Lunas / Belum bayar per bulan, pemasukan bulan berjalan, serta "Isi database sebenarnya"
yang menampilkan ciphertext asli di SQLite.

## Struktur
- `public/index.php` router, `src/Crypto.php`, `src/Database.php`, `src/Auth.php`, `src/bootstrap.php`
- `views/` tampilan, `database/schema.sql`, `database/kosan.db` (isi: ciphertext)
- `tools/seed.php`, `tools/make_evidence.php`, `tests/run_tests.php`, `docs/` (hasil tes + screenshot)

## Troubleshooting
- **"Setup diperlukan" (503):** database belum dibuat, jalankan `php tools/seed.php --reset`.
- **"500 - Terjadi kesalahan":** jalankan server dengan variabel `KA_DEBUG=1` untuk melihat detail
  (PowerShell: `$env:KA_DEBUG=1; php -S 127.0.0.1:8000 -t public public/index.php`).
- **`seed.php` gagal membuat kunci RSA di Windows:** set `OPENSSL_CONF` ke berkas `openssl.cnf`
  milik PHP/Laragon. Aplikasi mencoba lokasi umum secara otomatis.

> Jangan commit `keys/private_key.pem` ke repositori publik (sudah di `.gitignore`).
> Tanpa private key, data tidak dapat dipulihkan. Gunakan HTTPS pada penggunaan nyata.
> Catatan teknis: `openssl_public_encrypt()` PHP memakai OAEP-SHA1 untuk membungkus kunci AES
> acak 256-bit; ini tetap aman untuk tujuan tersebut.
