<?php
declare(strict_types=1);

/** Konfigurasi, konstanta, dan fungsi bantu aplikasi Kosan Aman. */

// Cek ekstensi PHP dulu supaya pesan errornya jelas (bukan "500" polos).
$missingExt = array_values(array_filter(['openssl', 'pdo_sqlite', 'mbstring'], fn($x) => !extension_loaded($x)));
if ($missingExt) {
    $msg = 'Ekstensi PHP belum aktif: ' . implode(', ', $missingExt)
        . ". Aktifkan di php.ini (hapus tanda ';' pada baris extension=...) lalu restart server.";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

define('BASE_DIR', dirname(__DIR__));
define('DB_PATH', getenv('KA_DB') ?: BASE_DIR . '/database/kosan.db');
define('SESSION_DIR', getenv('KA_SESSION_DIR') ?: BASE_DIR . '/storage/sessions');

const SESSION_TTL = 15 * 60;            // sesi admin berakhir setelah 15 menit tidak aktif
const SENTINEL = 'KOSAN-AMAN-KEY-OK';   // teks uji untuk memverifikasi private key
const TABLES = ['booking', 'penghuni', 'kamar', 'pembayaran'];
const MAX_FAILS = 5;                    // maks 5 gagal login ...
const FAIL_WINDOW = 300;                // ... per 5 menit per IP
const METODE = ['Tunai', 'Transfer', 'QRIS'];

/** Tipe kamar & harga sewa per bulan: informasi publik yang tampil di landing page. */
const TIPE_KAMAR = [
    'Standar' => ['harga' => 850000, 'ukuran' => '3 x 3 m',
                  'fasilitas' => ['Kasur & lemari', 'Kamar mandi luar', 'Kipas angin', 'Wi-Fi']],
    'Deluxe'  => ['harga' => 1250000, 'ukuran' => '3 x 4 m',
                  'fasilitas' => ['Kasur & lemari', 'Kamar mandi dalam', 'AC', 'Wi-Fi', 'Meja kerja']],
    'VIP'     => ['harga' => 1750000, 'ukuran' => '4 x 4 m',
                  'fasilitas' => ['Kasur & lemari', 'Kamar mandi dalam + water heater', 'AC', 'Wi-Fi',
                                  'Meja kerja', 'Balkon pribadi']],
];

require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

// ------------------------------------------------------------------ helper
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rp(int|float $n): string
{
    return 'Rp ' . number_format((float) round($n), 0, ',', '.');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function url(string $path = ''): string
{
    return (defined('BASE_URL') ? BASE_URL : '') . $path;
}

function now_str(): string
{
    return date('Y-m-d H:i');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function abort(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code . ' - ' . $msg;
    exit;
}

function start_session(): void
{
    if (!is_dir(SESSION_DIR)) {
        mkdir(SESSION_DIR, 0700, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_save_path(SESSION_DIR);
    session_name('KASESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(),
                               'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

/** Semua POST wajib membawa token CSRF yang cocok dengan sesi (token sesi tidak boleh kosong). */
function csrf_check(): void
{
    $expected = $_SESSION['csrf'] ?? '';
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $sent)) {
        abort(400, 'Token CSRF tidak valid');
    }
}

function flash(string $cat, string $msg): void
{
    $_SESSION['flash'][] = [$cat, $msg];
}

function security_headers(string $path): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
    if (str_starts_with($path, '/admin')) {
        header('Cache-Control: no-store');
    }
}

function render(string $view, array $vars = [], bool $admin = false, string $title = 'Kosan Aman'): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require BASE_DIR . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require BASE_DIR . '/views/layout.php';
}

/** Tombol hapus kecil (form POST + CSRF) untuk tabel admin. */
function hapus_form(string $tabel, int $id): string
{
    return '<form class="inline" method="post" action="' . e(url("/admin/hapus/$tabel/$id")) . '"'
        . ' onsubmit="return confirm(\'Hapus data ini?\')">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<button class="btn sm danger" type="submit">Hapus</button></form>';
}

// -------------------------------------------------------------- validasi
function valid_hp(string $hp): bool
{
    return (bool) preg_match('/\A[0-9+\- ]{8,20}\z/', $hp);
}

/** NIK KTP: kosong (opsional) atau tepat 16 digit. */
function valid_ktp(string $nik): bool
{
    return $nik === '' || (bool) preg_match('/\A[0-9]{16}\z/', $nik);
}

function valid_date(string $s): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

function valid_periode(string $s): bool
{
    return (bool) preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $s);
}

function len_between(string $s, int $min, int $max): bool
{
    $n = mb_strlen($s);
    return $n >= $min && $n <= $max;
}

/** Kamar yang belum dihuni siapa pun (dari data yang sudah didekripsi). */
function kamar_kosong(array $kamar, array $penghuni): array
{
    $dipakai = [];
    foreach ($penghuni as $p) {
        if (empty($p['_error']) && isset($p['kamar_id'])) {
            $dipakai[$p['kamar_id']] = true;
        }
    }
    return array_values(array_filter($kamar, fn($k) => empty($k['_error']) && !isset($dipakai[$k['id']])));
}
