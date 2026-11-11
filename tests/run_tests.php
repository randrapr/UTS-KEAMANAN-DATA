<?php
declare(strict_types=1);

/**
 * Pengujian otomatis (unit kriptografi + uji HTTP nyata ke server bawaan PHP).
 *   php tests/run_tests.php
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/TestServer.php';

$passed = 0;
$failed = 0;

function ok(bool $cond, string $msg = 'asersi gagal'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function throws(callable $fn, string $msg): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException($msg);
}

function t(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "$name ... ok\n";
        $passed++;
    } catch (Throwable $e) {
        echo "$name ... FAIL\n    " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ------------------------------------------------------------------ setup
$tmp = sys_get_temp_dir() . '/ka_test_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$dbPath = "$tmp/test.db";
$sessDir = "$tmp/sessions";
[$privPem, $pubPem] = Crypto::generateKeypair();
[$otherPem] = Crypto::generateKeypair();      // kunci "salah"
Database::init($dbPath, $pubPem);
$testDb = function () use ($dbPath): PDO {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
};
// dua kamar Standar kosong (id 1 = A1, id 2 = A2)
$pubKey = Crypto::loadPublicKey($pubPem);
Database::insertRecord('kamar', ['nomor' => 'A1', 'tipe' => 'Standar', 'lantai' => 1, 'catatan' => ''], $pubKey, $testDb());
Database::insertRecord('kamar', ['nomor' => 'A2', 'tipe' => 'Standar', 'lantai' => 1, 'catatan' => ''], $pubKey, $testDb());

$server = TestServer::start($dbPath, $sessDir);
$http = fn() => new HttpClient($server->base);
$rawDb = function () use ($testDb): string {
    $out = [];
    foreach (TABLES as $t) {
        foreach ($testDb()->query("SELECT payload FROM $t") as $r) {
            $out[] = $r['payload'];
        }
    }
    return implode("\n", $out);
};
$login = function (HttpClient $c, string $pem) {
    return $c->post('/admin/login', ['csrf' => $c->csrf('/admin/login'), 'private_key' => $pem]);
};
$tglDepan = date('Y-m-d', strtotime('+10 days'));

// -------------------------------------------------------------- kriptografi
t('test_01_enkripsi_dekripsi_roundtrip', function () use ($pubPem, $privPem) {
    $pub = Crypto::loadPublicKey($pubPem);
    $priv = Crypto::loadPrivateKey($privPem);
    $blob = Crypto::encryptRecord($pub, ['nama' => 'Siti', 'n' => 5], 'penghuni');
    ok(!str_contains($blob, 'Siti'), 'plaintext bocor di blob');
    ok(Crypto::decryptRecord($priv, $blob, 'penghuni') === ['nama' => 'Siti', 'n' => 5], 'hasil dekripsi beda');
});

t('test_02_nonce_acak_ciphertext_berbeda', function () use ($pubPem) {
    $pub = Crypto::loadPublicKey($pubPem);
    ok(Crypto::encryptText($pub, 'sama', 't') !== Crypto::encryptText($pub, 'sama', 't'), 'blob identik');
});

t('test_03_kunci_salah_gagal', function () use ($pubPem, $otherPem) {
    $blob = Crypto::encryptText(Crypto::loadPublicKey($pubPem), 'rahasia', 't');
    throws(fn() => Crypto::decryptText(Crypto::loadPrivateKey($otherPem), $blob, 't'), 'kunci salah bisa dekripsi');
});

t('test_04_tamper_dan_aad_terdeteksi', function () use ($pubPem, $privPem) {
    $priv = Crypto::loadPrivateKey($privPem);
    $blob = Crypto::encryptText(Crypto::loadPublicKey($pubPem), 'rahasia', 'penghuni');
    throws(fn() => Crypto::decryptText($priv, $blob, 'pembayaran'), 'dipindah ke tabel lain lolos');
    [$v, $w, $n, $ct] = explode('.', $blob);
    $bad = implode('.', [$v, $w, $n, ($ct[0] === 'A' ? 'B' : 'A') . substr($ct, 1)]);
    throws(fn() => Crypto::decryptText($priv, $bad, 'penghuni'), 'ciphertext diubah lolos');
});

t('test_05_loadPrivateKey_menolak_input_berbahaya', function () use ($privPem) {
    foreach (['file:///etc/passwd', 'bukan-pem', '', "-----BEGIN PRIVATE KEY-----\nfile:///etc/passwd\n-----END PRIVATE KEY-----"] as $bad) {
        throws(fn() => Crypto::loadPrivateKey($bad), "input diterima: $bad");
    }
    ok(Crypto::loadPrivateKey(str_replace("\n", "\r\n", $privPem)) !== null, 'PEM sah (CRLF) ditolak');
});

// ---------------------------------------------------------------- aplikasi
t('test_06_landing_admin_terlindungi_dan_alias_login', function () use ($http) {
    $c = $http();
    ok($c->get('/')['status'] === 200, 'landing bukan 200');
    $r = $c->get('/admin');
    ok($r['status'] === 302 && str_contains((string) $r['location'], '/admin/login'), 'admin tidak diarahkan ke login');
    $r = $c->get('/login');
    ok($r['status'] === 302 && str_contains((string) $r['location'], '/admin/login'), '/login tidak diarahkan');
});

t('test_07_login_private_key_salah_ditolak', function () use ($http, $login, $otherPem) {
    $c = $http();
    ok($login($c, $otherPem)['status'] === 401, 'kunci orang lain diterima');
    ok($login($c, 'bukan-pem')['status'] === 401, 'teks acak diterima');
    ok($c->get('/admin')['status'] === 302, 'admin terbuka tanpa login');
});

t('test_08_csrf_wajib', function () use ($http) {
    ok($http()->post('/booking', ['nama' => 'x'])['status'] === 400, 'POST tanpa CSRF lolos');
    $c = $http();
    $c->get('/');   // sesi punya token, tapi kiriman salah
    ok($c->post('/booking', ['csrf' => 'salah', 'nama' => 'x'])['status'] === 400, 'token salah lolos');
});

t('test_09_booking_publik_tersimpan_terenkripsi', function () use ($http, $rawDb, $dbPath, $tglDepan) {
    $c = $http();
    $r = $c->post('/booking', ['csrf' => $c->csrf('/'), 'nama' => 'Rina Marlina', 'hp' => '0812-0000-0010',
                               'tipe' => 'Standar', 'tgl_masuk' => $tglDepan, 'pesan' => 'Halo kosan aman']);
    ok($r['status'] === 302, 'booking tidak diproses');
    $c2 = $http();   // input tidak sah ditolak (tipe tak dikenal / tanggal lampau)
    $c2->post('/booking', ['csrf' => $c2->csrf('/'), 'nama' => 'Budi', 'hp' => '0812-0000-0011',
                           'tipe' => 'Presidential', 'tgl_masuk' => $tglDepan, 'pesan' => '']);
    $c2->post('/booking', ['csrf' => $c2->csrf('/'), 'nama' => 'Budi', 'hp' => '0812-0000-0011',
                           'tipe' => 'Standar', 'tgl_masuk' => '2020-01-01', 'pesan' => '']);
    $raw = $rawDb();
    foreach (['Rina Marlina', '0812-0000-0010', 'Halo kosan', 'Standar'] as $plain) {
        ok(!str_contains($raw, $plain), "plaintext '$plain' ada di kolom DB");
    }
    ok(!str_contains((string) file_get_contents($dbPath), 'Rina Marlina'), 'plaintext ada di file DB mentah');
    $n = (int) (new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM booking')->fetchColumn();
    ok($n === 1, "booking tidak sah ikut tersimpan (jumlah=$n, seharusnya 1)");
});

t('test_10_alur_admin_dekripsi_terima_booking_dan_pembayaran', function () use ($http, $login, $privPem, $rawDb) {
    $c = $http();
    ok($login($c, $privPem)['status'] === 302, 'login private key sah gagal');
    ok(str_contains($c->get('/admin')['body'], 'Rina Marlina'), 'booking tidak terdekripsi');
    $tok = $c->csrf('/admin');
    $c->post('/admin/booking/1/terima', ['csrf' => $tok, 'kamar_id' => '1', 'ktp' => '3201010101010099']);
    $page = $c->get('/admin')['body'];
    ok(str_contains($page, 'Rina Marlina') && str_contains($page, '3201010101010099'), 'penghuni baru tidak tampil');
    ok(str_contains($page, 'Terisi'), 'status kamar tidak berubah jadi Terisi');
    $c->post('/admin/pembayaran', ['csrf' => $tok, 'penghuni_id' => '1', 'periode' => date('Y-m'),
                                   'jumlah' => '900000', 'metode' => 'Tunai', 'catatan' => '']);
    $page = $c->get('/admin')['body'];
    ok(str_contains($page, 'Rp 900.000'), 'pembayaran tidak tampil');
    ok(str_contains($page, 'Lunas'), 'status Lunas tidak muncul (900.000 >= 850.000)');
    $raw = $rawDb();
    foreach (['Rina Marlina', '3201010101010099', 'Tunai'] as $plain) {
        ok(!str_contains($raw, $plain), "plaintext '$plain' muncul di DB");
    }
});

t('test_11_private_key_tidak_tersimpan_plaintext_di_disk', function () use ($http, $login, $privPem, $sessDir) {
    $c = $http();
    $login($c, $privPem);
    $c->get('/admin');
    $body = trim(preg_replace('/-----[A-Z ]+-----|\s+/', '', $privPem));
    $files = glob("$sessDir/sess_*");
    ok(count($files) > 0, 'berkas sesi tidak ditemukan');
    foreach ($files as $f) {
        $data = (string) file_get_contents($f);
        ok(!str_contains($data, 'BEGIN PRIVATE KEY') && !str_contains($data, substr($body, 20, 40)),
           'private key terbaca di berkas sesi: ' . basename($f));
    }
});

t('test_12_logout_menghapus_akses', function () use ($http, $login, $privPem) {
    $c = $http();
    $login($c, $privPem);
    ok($c->get('/admin')['status'] === 200, 'tidak bisa masuk admin');
    $c->post('/admin/logout', ['csrf' => $c->csrf('/admin')]);
    ok($c->get('/admin')['status'] === 302, 'admin masih terbuka setelah logout');
});

t('test_13_cookie_kunci_wajib_untuk_buka_sesi', function () use ($http, $login, $privPem) {
    // Berkas sesi saja tanpa cookie ka_k (kunci sesi) tidak cukup untuk memulihkan private key.
    $c = $http();
    $login($c, $privPem);
    ok($c->get('/admin')['status'] === 200, 'login dasar gagal');
    $ref = new ReflectionProperty($c, 'cookies');
    $cookies = $ref->getValue($c);
    unset($cookies['ka_k']);
    $ref->setValue($c, $cookies);
    ok($c->get('/admin')['status'] === 302, 'admin terbuka tanpa cookie kunci sesi');
});

t('test_14_kamar_terisi_tidak_bisa_dipakai_dua_kali_atau_dihapus', function () use ($http, $login, $privPem) {
    $c = $http();
    $login($c, $privPem);
    $tok = $c->csrf('/admin');
    $c->post('/admin/penghuni', ['csrf' => $tok, 'nama' => 'Tamu Kedua', 'hp' => '0812-1111-2222', 'ktp' => '',
                                 'tgl_masuk' => date('Y-m-d'), 'darurat_nama' => '', 'darurat_hp' => '', 'kamar_id' => '1']);
    $page = $c->get('/admin')['body'];
    ok(str_contains($page, 'Kamar tidak tersedia'), 'kamar terisi bisa dipakai lagi');
    ok(!str_contains($page, 'Tamu Kedua'), 'penghuni kedua tersimpan di kamar yang sama');
    $c->post('/admin/hapus/kamar/1', ['csrf' => $tok]);
    $page = $c->get('/admin')['body'];
    ok(str_contains($page, 'Kamar masih dihuni'), 'kamar terisi bisa dihapus');
});

t('test_15_data_rusak_ditandai_gagal_dekripsi', function () use ($http, $login, $privPem, $testDb) {
    $testDb()->exec("UPDATE kamar SET payload = substr(payload, 1, length(payload) - 3) || 'AAA' WHERE id = 2");
    $c = $http();
    $login($c, $privPem);
    ok(str_contains($c->get('/admin')['body'], 'Gagal dekripsi'), 'data rusak tidak ditandai');
});

t('test_16_database_belum_dibuat_tampil_pesan_ramah', function () use ($tmp) {
    $s2 = TestServer::start("$tmp/tidak_ada.db", "$tmp/sess2");
    try {
        $c = new HttpClient($s2->base);
        ok($c->get('/')['status'] === 200, 'landing harus tetap terbuka tanpa database');
        $r = $c->get('/admin/login');
        ok($r['status'] === 503, 'harusnya 503 bila database belum ada, dapat ' . $r['status']);
        ok(str_contains($r['body'], 'seed.php'), 'pesan tidak menyebut seed.php');
        ok(!is_file("$tmp/tidak_ada.db") || filesize("$tmp/tidak_ada.db") === 0, 'berkas DB kosong terbuat diam-diam');
    } finally {
        $s2->stop();
    }
});

t('test_17_batas_percobaan_login_gagal', function () use ($http, $login, $otherPem, $privPem) {
    $c = $http();
    $last = 0;
    for ($i = 0; $i < 7; $i++) {
        $last = $login($c, $otherPem)['status'];
    }
    ok($last === 429, "setelah banyak gagal harusnya 429, dapat $last");
    ok($login($c, $privPem)['status'] === 429, 'login sah ikut diblok sementara (diharapkan)');
});

$server->stop();
echo "\n----------------------------------------------------------------------\n";
echo 'Ran ' . ($passed + $failed) . " tests\n\n" . ($failed === 0 ? 'OK' : "FAILED (failures=$failed)") . "\n";
exit($failed === 0 ? 0 : 1);
