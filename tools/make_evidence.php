<?php
declare(strict_types=1);

/**
 * Hasilkan bukti hasil untuk laporan: screenshot halaman + dump database.
 *   php tools/make_evidence.php      (screenshot butuh wkhtmltoimage; opsional)
 * Database asli tidak diubah (server memakai salinan sementara).
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../tests/TestServer.php';

$out = BASE_DIR . '/docs';
$privPem = (string) file_get_contents(BASE_DIR . '/keys/private_key.pem');
$css = (string) file_get_contents(BASE_DIR . '/public/assets/style.css');

$tmp = sys_get_temp_dir() . '/ka_ev_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
copy(DB_PATH, "$tmp/demo.db");
$server = TestServer::start("$tmp/demo.db", "$tmp/sessions");
$c = new HttpClient($server->base);

function shot(string $html, string $name, string $css, string $out, int $width = 1100): void
{
    $html = preg_replace('#<link rel="stylesheet"[^>]*>#', "<style>$css</style>", $html);
    $html = preg_replace('#<script>.*?</script>#s', '', $html);
    $file = sys_get_temp_dir() . "/$name.html";
    file_put_contents($file, $html);
    $png = "$out/$name.png";
    shell_exec('wkhtmltoimage --quiet --width ' . $width . ' --quality 85 --enable-local-file-access '
        . escapeshellarg($file) . ' ' . escapeshellarg($png) . ' 2>/dev/null');
    echo "screenshot: $png " . (file_exists($png) ? 'True' : 'False') . "\n";
}

shot($c->get('/')['body'], 'ss_1_landing', $css, $out);
shot($c->get('/admin/login')['body'], 'ss_2_login', $css, $out);

[$wrong] = Crypto::generateKeypair();   // login gagal dengan private key milik orang lain
$r = $c->post('/admin/login', ['csrf' => $c->csrf('/admin/login'), 'private_key' => $wrong]);
shot($r['body'], 'ss_3_login_gagal', $css, $out);

$c->post('/admin/login', ['csrf' => $c->csrf('/admin/login'), 'private_key' => $privPem]);
shot($c->get('/admin')['body'], 'ss_4_admin', $css, $out, 1250);
$server->stop();

// ----- dump: ciphertext di DB vs hasil dekripsi
$priv = Crypto::loadPrivateKey($privPem);
$pdo = new PDO('sqlite:' . DB_PATH);
$lines = [];
foreach (TABLES as $t) {
    foreach ($pdo->query("SELECT id, payload FROM $t ORDER BY id LIMIT 2") as $row) {
        $dec = Crypto::decryptRecord($priv, $row['payload'], $t);
        $lines[] = "[$t #{$row['id']}]";
        $lines[] = '  DB (terenkripsi): ' . substr($row['payload'], 0, 110) . '...';
        $lines[] = '  panjang blob    : ' . strlen($row['payload']) . ' karakter';
        $lines[] = '  Dekripsi        : ' . json_encode($dec, JSON_UNESCAPED_UNICODE);
        $lines[] = '';
    }
}
file_put_contents("$out/db_dump.txt", implode("\n", $lines));
echo implode("\n", $lines), "\n";

// ----- bukti: tidak ada plaintext di file database mentah
$raw = (string) file_get_contents(DB_PATH);
$res = ['Pencarian plaintext pada file kosan.db (byte mentah):'];
foreach (['Rizky Pratama', 'Anisa Putri', '3201010101010001', '0812-5550', 'Deluxe', 'Transfer', 'Fajar Nugroho'] as $kata) {
    $res[] = "  '$kata': " . (str_contains($raw, $kata) ? 'DITEMUKAN' : 'tidak ditemukan');
}
$res[] = 'Ukuran file DB: ' . strlen($raw) . ' byte';
file_put_contents("$out/cek_plaintext.txt", implode("\n", $res));
echo implode("\n", $res), "\n";
