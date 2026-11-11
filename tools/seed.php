<?php
declare(strict_types=1);

/**
 * Inisialisasi: buat pasangan kunci RSA, database, dan data contoh terenkripsi.
 *
 *   php tools/seed.php            # buat kunci + database + data demo
 *   php tools/seed.php --reset    # hapus database lama lalu buat ulang (kunci lama dipakai lagi)
 *
 * PRIVATE KEY (keys/private_key.pem) = "password" admin. Simpan di tempat aman,
 * JANGAN di-commit ke GitHub / dibagikan. Database hanya berisi public key.
 * Catatan: bila keys/private_key.pem TIDAK ada, pasangan kunci BARU dibuat, sehingga
 * kunci lama (mis. DEMO_private_key.pem) tidak lagi cocok dengan database baru.
 */

require __DIR__ . '/../src/bootstrap.php';

$keyDir = BASE_DIR . '/keys';
$privPath = $keyDir . '/private_key.pem';
$pubPath = $keyDir . '/public_key.pem';

$now = date('Y-m');
$prev = date('Y-m', strtotime('first day of last month'));

$kamar = [   // [nomor, tipe, lantai, catatan]
    ['A1', 'Standar', 1, ''], ['A2', 'Standar', 1, ''], ['A3', 'Standar', 1, 'Dekat tangga'],
    ['B1', 'Deluxe', 2, ''], ['B2', 'Deluxe', 2, 'Ada balkon'], ['C1', 'VIP', 3, ''],
];
$penghuni = [   // [nama, hp, nik, darurat_nama, darurat_hp, index kamar, tgl masuk]
    ['Rizky Pratama', '0812-5550-0001', '3201010101010001', 'Hendra Pratama', '0813-5550-1001', 0, '2026-01-05'],
    ['Anisa Putri', '0812-5550-0002', '3201010101010002', 'Sri Wahyuni', '0813-5550-1002', 3, '2026-03-01'],
    ['Dimas Saputra', '0812-5550-0003', '3201010101010003', 'Agus Saputra', '0813-5550-1003', 1, '2026-06-10'],
    ['Maya Sari', '0812-5550-0004', '3201010101010004', 'Rina Sari', '0813-5550-1004', 5, '2026-08-15'],
];
$metode = ['Transfer', 'Tunai', 'QRIS', 'Transfer'];
$booking = [
    ['Fajar Nugroho', '0812-5550-0010', 'Deluxe', date('Y-m-d', strtotime('+10 days')), 'Ingin kamar lantai 2 bila ada.'],
    ['Lia Kusuma', '0812-5550-0011', 'Standar', date('Y-m-d', strtotime('+20 days')), 'Apakah boleh membawa kulkas kecil?'],
];

$reset = in_array('--reset', $argv ?? [], true);
if (file_exists(DB_PATH)) {
    if (filesize(DB_PATH) > 0 && !$reset) {
        fwrite(STDERR, "Database sudah ada. Gunakan --reset untuk membuat ulang.\n");
        exit(1);
    }
    unlink(DB_PATH);   // berkas kosong / reset
}
if (!is_dir($keyDir)) {
    mkdir($keyDir, 0700, true);
}
if (!file_exists($privPath)) {
    [$privPem, $pubPem] = Crypto::generateKeypair();
    file_put_contents($privPath, $privPem);
    @chmod($privPath, 0600);
    file_put_contents($pubPath, $pubPem);
    echo "Kunci baru dibuat: $privPath\n";
}
$pubPem = (string) file_get_contents($pubPath);
Database::init(DB_PATH, $pubPem);

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pub = Crypto::loadPublicKey($pubPem);

$kamarIds = [];
foreach ($kamar as [$nomor, $tipe, $lantai, $catatan]) {
    $kamarIds[] = Database::insertRecord('kamar', ['nomor' => $nomor, 'tipe' => $tipe, 'lantai' => $lantai,
                                                   'catatan' => $catatan], $pub, $pdo);
}
foreach ($penghuni as $i => [$nama, $hp, $nik, $dNama, $dHp, $ik, $masuk]) {
    $pid = Database::insertRecord('penghuni', [
        'nama' => $nama, 'hp' => $hp, 'ktp' => $nik, 'darurat_nama' => $dNama, 'darurat_hp' => $dHp,
        'kamar_id' => $kamarIds[$ik], 'tgl_masuk' => $masuk, 'tanggal' => "$masuk 09:00",
    ], $pub, $pdo);
    $harga = TIPE_KAMAR[$kamar[$ik][1]]['harga'];
    // bulan lalu: semua lunas; bulan ini: hanya dua penghuni pertama (sisanya jadi "belum bayar")
    $periods = $i < 2 ? [$prev, $now] : [$prev];
    foreach ($periods as $per) {
        Database::insertRecord('pembayaran', [
            'penghuni_id' => $pid, 'periode' => $per, 'jumlah' => $harga, 'metode' => $metode[$i],
            'catatan' => '', 'tanggal' => sprintf('%s-%02d 10:00', $per, 2 + $i),
        ], $pub, $pdo);
    }
}
foreach ($booking as [$nama, $hp, $tipe, $tgl, $pesan]) {
    Database::insertRecord('booking', ['nama' => $nama, 'hp' => $hp, 'tipe' => $tipe, 'tgl_masuk' => $tgl,
                                       'pesan' => $pesan, 'tanggal' => date('Y-m-d H:i')], $pub, $pdo);
}
echo 'Database dibuat: ' . DB_PATH . "\n";
echo 'Fingerprint kunci: ' . Crypto::fingerprint($pubPem) . "\n";
echo "Login admin dengan private key: $privPath\n";
