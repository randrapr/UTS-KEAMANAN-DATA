<?php
declare(strict_types=1);

/**
 * Front controller Kosan Aman.
 *   php -S 127.0.0.1:8000 -t public public/index.php
 * (di Apache/Laragon/XAMPP, .htaccess mengarahkan semua permintaan ke berkas ini)
 */

// Server bawaan PHP: biarkan berkas statis (css) dilayani langsung.
if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . $reqPath);
    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && basename($file) !== 'index.php') {
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';

// Basis URL otomatis (mis. /kosan_aman/public pada Apache); kosong untuk server bawaan.
$base = PHP_SAPI === 'cli-server' ? '' : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
define('BASE_URL', $base);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

security_headers($path);
start_session();
if ($method === 'POST') {
    csrf_check();
}

/** Halaman yang butuh login admin: kembalikan private key sesi atau alihkan ke login. */
function require_admin()
{
    $key = Auth::key();
    if ($key === null) {
        flash('warn', 'Sesi berakhir atau belum login. Masukkan private key.');
        redirect(url('/admin/login'));
    }
    return $key;
}

try {
    // ============================================================ landing (publik)
    if ($path === '/' && $method === 'GET') {
        render('landing', ['tipe' => TIPE_KAMAR, 'today' => date('Y-m-d')]);
        exit;
    }

    if ($path === '/login' && $method === 'GET') {
        redirect(url('/admin/login'));
    }

    if ($path === '/booking' && $method === 'POST') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $hp = trim((string) ($_POST['hp'] ?? ''));
        $tipe = (string) ($_POST['tipe'] ?? '');
        $tgl = trim((string) ($_POST['tgl_masuk'] ?? ''));
        $pesan = trim((string) ($_POST['pesan'] ?? ''));
        if (!len_between($nama, 2, 80) || !valid_hp($hp) || !isset(TIPE_KAMAR[$tipe]) || !valid_date($tgl)
            || $tgl < date('Y-m-d') || $tgl > date('Y-m-d', strtotime('+1 year')) || mb_strlen($pesan) > 300) {
            flash('err', 'Data booking tidak valid. Periksa kembali isian Anda.');
            redirect(url('/') . '#booking');
        }
        // Dienkripsi dengan PUBLIC KEY sebelum menyentuh database
        Database::insertRecord('booking', [
            'nama' => $nama, 'hp' => $hp, 'tipe' => $tipe, 'tgl_masuk' => $tgl,
            'pesan' => $pesan, 'tanggal' => now_str(),
        ], Database::publicKey());
        flash('ok', 'Terima kasih! Booking Anda tersimpan terenkripsi dan akan dikonfirmasi pengelola.');
        redirect(url('/') . '#booking');
    }

    // ===================================================== login admin (PRIVATE KEY)
    if ($path === '/admin/login') {
        $fp = Database::meta('fingerprint');
        if ($method === 'GET') {
            render('login', ['fingerprint' => $fp], false, 'Masuk Admin - Kosan Aman');
            exit;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (Auth::tooManyFails($ip)) {
            http_response_code(429);
            flash('err', 'Terlalu banyak percobaan gagal. Coba lagi beberapa menit lagi.');
            render('login', ['fingerprint' => $fp], false, 'Masuk Admin - Kosan Aman');
            exit;
        }
        if (!Auth::attempt((string) ($_POST['private_key'] ?? ''))) {
            Auth::recordFail($ip);
            http_response_code(401);
            flash('err', 'Private key tidak valid atau tidak cocok dengan sistem.');
            render('login', ['fingerprint' => $fp], false, 'Masuk Admin - Kosan Aman');
            exit;
        }
        Auth::clearFails($ip);
        redirect(url('/admin'));
    }

    if ($path === '/admin/logout' && $method === 'POST') {
        Auth::logout();   // buang kunci sesi + private key terenkripsi
        flash('ok', 'Anda telah keluar. Private key dihapus dari sesi.');
        redirect(url('/admin/login'));
    }

    // ================================ halaman admin (semua konten didekripsi)
    if ($path === '/admin' && $method === 'GET') {
        $priv = require_admin();
        $booking = Database::loadAll('booking', $priv);
        $penghuni = Database::loadAll('penghuni', $priv);
        $kamar = Database::loadAll('kamar', $priv);
        $bayar = Database::loadAll('pembayaran', $priv);
        $periode = date('Y-m');

        $kamarById = [];
        foreach ($kamar as $k) {
            if (empty($k['_error'])) {
                $kamarById[$k['id']] = $k;
            }
        }
        $penghuniById = [];
        $hunian = [];   // kamar_id => nama penghuni
        foreach ($penghuni as $p) {
            if (empty($p['_error'])) {
                $penghuniById[$p['id']] = $p;
                $hunian[$p['kamar_id']] = $p['nama'];
            }
        }

        // pembayaran: nama penghuni + total dibayar pada periode berjalan
        $dibayar = [];
        $pemasukan = 0;
        foreach ($bayar as $i => $b) {
            if (!empty($b['_error'])) {
                continue;
            }
            $bayar[$i]['penghuni_nama'] = $penghuniById[$b['penghuni_id']]['nama'] ?? '(dihapus)';
            if ($b['periode'] === $periode) {
                $dibayar[$b['penghuni_id']] = ($dibayar[$b['penghuni_id']] ?? 0) + $b['jumlah'];
                $pemasukan += $b['jumlah'];
            }
        }
        foreach ($kamar as $i => $k) {
            if (empty($k['_error'])) {
                $kamar[$i]['harga'] = TIPE_KAMAR[$k['tipe']]['harga'] ?? 0;
                $kamar[$i]['penghuni'] = $hunian[$k['id']] ?? null;
            }
        }
        $tunggakan = 0;
        foreach ($penghuni as $i => $p) {
            if (!empty($p['_error'])) {
                continue;
            }
            $k = $kamarById[$p['kamar_id']] ?? null;
            $harga = $k ? (TIPE_KAMAR[$k['tipe']]['harga'] ?? 0) : 0;
            $penghuni[$i]['kamar_nomor'] = $k['nomor'] ?? '(dihapus)';
            $lunas = isset($dibayar[$p['id']]) && $dibayar[$p['id']] >= $harga;
            $penghuni[$i]['lunas'] = $lunas;
            $tunggakan += $lunas ? 0 : 1;
        }

        $terisi = count(array_filter($kamarById, fn($k) => isset($hunian[$k['id']])));
        $stats = [
            'kamar' => count($kamarById), 'terisi' => $terisi, 'kosong' => count($kamarById) - $terisi,
            'penghuni' => count($penghuniById), 'booking' => count($booking),
            'pemasukan' => $pemasukan, 'tunggakan' => $tunggakan,
        ];
        $inspector = [];
        foreach (TABLES as $t) {
            foreach (Database::pdo()->query("SELECT id, payload FROM $t ORDER BY id LIMIT 3") as $r) {
                $inspector[] = ['tabel' => $t, 'id' => $r['id'], 'panjang' => strlen($r['payload']),
                                'cipher' => substr($r['payload'], 0, 96) . '…'];
            }
        }
        render('admin', [
            'booking' => $booking, 'penghuni' => $penghuni, 'kamar' => $kamar, 'bayar' => $bayar,
            'kosong' => kamar_kosong($kamar, $penghuni), 'stats' => $stats, 'periode' => $periode,
            'inspector' => $inspector, 'fingerprint' => Database::meta('fingerprint'),
            'ttl' => intdiv(Auth::ttlLeft(), 60), 'today' => date('Y-m-d'),
        ], true, 'Dashboard Admin - Kosan Aman');
        exit;
    }

    // ---- tambah kamar
    if ($path === '/admin/kamar' && $method === 'POST') {
        $priv = require_admin();
        $nomor = strtoupper(trim((string) ($_POST['nomor'] ?? '')));
        $tipe = (string) ($_POST['tipe'] ?? '');
        $lantai = filter_var($_POST['lantai'] ?? null, FILTER_VALIDATE_INT);
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $nomorAda = array_column(array_filter(Database::loadAll('kamar', $priv), fn($k) => empty($k['_error'])), 'nomor');
        if (!preg_match('/\A[A-Z0-9\- ]{1,10}\z/', $nomor) || !isset(TIPE_KAMAR[$tipe])
            || $lantai === false || $lantai < 1 || $lantai > 20 || mb_strlen($catatan) > 100) {
            flash('err', 'Data kamar tidak valid.');
        } elseif (in_array($nomor, $nomorAda, true)) {
            flash('err', "Nomor kamar $nomor sudah ada.");
        } else {
            Database::insertRecord('kamar', ['nomor' => $nomor, 'tipe' => $tipe, 'lantai' => $lantai,
                                             'catatan' => $catatan], Database::publicKey());
            flash('ok', 'Kamar ditambahkan (tersimpan terenkripsi).');
        }
        redirect(url('/admin') . '#kamar');
    }

    // ---- tambah penghuni manual
    if ($path === '/admin/penghuni' && $method === 'POST') {
        $priv = require_admin();
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $hp = trim((string) ($_POST['hp'] ?? ''));
        $ktp = trim((string) ($_POST['ktp'] ?? ''));
        $tgl = trim((string) ($_POST['tgl_masuk'] ?? ''));
        $dNama = trim((string) ($_POST['darurat_nama'] ?? ''));
        $dHp = trim((string) ($_POST['darurat_hp'] ?? ''));
        $kid = filter_var($_POST['kamar_id'] ?? null, FILTER_VALIDATE_INT);
        $kosongIds = array_column(kamar_kosong(Database::loadAll('kamar', $priv), Database::loadAll('penghuni', $priv)), 'id');
        if ($kid === false || !in_array($kid, $kosongIds, true)) {
            flash('err', 'Kamar tidak tersedia (sudah dihuni atau tidak ada).');
        } elseif (!len_between($nama, 2, 80) || !valid_hp($hp) || !valid_ktp($ktp) || !valid_date($tgl)
            || mb_strlen($dNama) > 80 || ($dHp !== '' && !valid_hp($dHp))) {
            flash('err', 'Data penghuni tidak valid.');
        } else {
            Database::insertRecord('penghuni', [
                'nama' => $nama, 'hp' => $hp, 'ktp' => $ktp, 'darurat_nama' => $dNama, 'darurat_hp' => $dHp,
                'kamar_id' => $kid, 'tgl_masuk' => $tgl, 'tanggal' => now_str(),
            ], Database::publicKey());
            flash('ok', 'Penghuni ditambahkan (tersimpan terenkripsi).');
        }
        redirect(url('/admin') . '#penghuni');
    }

    // ---- catat pembayaran
    if ($path === '/admin/pembayaran' && $method === 'POST') {
        $priv = require_admin();
        $pid = filter_var($_POST['penghuni_id'] ?? null, FILTER_VALIDATE_INT);
        $periode = trim((string) ($_POST['periode'] ?? ''));
        $jumlah = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
        $metode = (string) ($_POST['metode'] ?? '');
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $ids = array_column(array_filter(Database::loadAll('penghuni', $priv), fn($p) => empty($p['_error'])), 'id');
        if ($pid === false || !in_array($pid, $ids, true) || !valid_periode($periode) || $jumlah === false
            || $jumlah < 1 || $jumlah > 100000000 || !in_array($metode, METODE, true) || mb_strlen($catatan) > 100) {
            flash('err', 'Data pembayaran tidak valid.');
        } else {
            Database::insertRecord('pembayaran', [
                'penghuni_id' => $pid, 'periode' => $periode, 'jumlah' => $jumlah, 'metode' => $metode,
                'catatan' => $catatan, 'tanggal' => now_str(),
            ], Database::publicKey());
            flash('ok', 'Pembayaran dicatat (tersimpan terenkripsi).');
        }
        redirect(url('/admin') . '#pembayaran');
    }

    // ---- terima booking -> jadi penghuni di kamar kosong
    if (preg_match('#\A/admin/booking/(\d+)/terima\z#', $path, $m) && $method === 'POST') {
        $priv = require_admin();
        $stmt = Database::pdo()->prepare('SELECT payload FROM booking WHERE id = ?');
        $stmt->execute([(int) $m[1]]);
        $payload = $stmt->fetchColumn();
        if ($payload === false) {
            abort(404, 'Booking tidak ditemukan');
        }
        $kid = filter_var($_POST['kamar_id'] ?? null, FILTER_VALIDATE_INT);
        $ktp = trim((string) ($_POST['ktp'] ?? ''));
        $kosongIds = array_column(kamar_kosong(Database::loadAll('kamar', $priv), Database::loadAll('penghuni', $priv)), 'id');
        if ($kid === false || !in_array($kid, $kosongIds, true)) {
            flash('err', 'Kamar tidak tersedia (sudah dihuni atau tidak ada).');
            redirect(url('/admin') . '#booking');
        }
        if (!valid_ktp($ktp)) {
            flash('err', 'NIK harus 16 digit angka (atau dikosongkan).');
            redirect(url('/admin') . '#booking');
        }
        $d = Crypto::decryptRecord($priv, (string) $payload, 'booking');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        Database::insertRecord('penghuni', [
            'nama' => $d['nama'], 'hp' => $d['hp'], 'ktp' => $ktp, 'darurat_nama' => '', 'darurat_hp' => '',
            'kamar_id' => $kid, 'tgl_masuk' => $d['tgl_masuk'], 'tanggal' => now_str(),
        ], Database::publicKey());
        $pdo->prepare('DELETE FROM booking WHERE id = ?')->execute([(int) $m[1]]);
        $pdo->commit();
        flash('ok', 'Booking diterima: calon penghuni kini terdaftar di kamar terpilih.');
        redirect(url('/admin') . '#penghuni');
    }

    // ---- hapus data
    if (preg_match('#\A/admin/hapus/([a-z]+)/(\d+)\z#', $path, $m) && $method === 'POST') {
        $priv = require_admin();
        if (!in_array($m[1], TABLES, true)) {
            abort(404, 'Tabel tidak ditemukan');
        }
        if ($m[1] === 'kamar') {
            foreach (Database::loadAll('penghuni', $priv) as $p) {
                if (empty($p['_error']) && $p['kamar_id'] === (int) $m[2]) {
                    flash('err', 'Kamar masih dihuni. Hapus penghuninya dulu (check-out).');
                    redirect(url('/admin') . '#kamar');
                }
            }
        }
        Database::pdo()->prepare("DELETE FROM {$m[1]} WHERE id = ?")->execute([(int) $m[2]]);
        flash('ok', 'Data dihapus.');
        redirect(url('/admin'));
    }

    abort(404, 'Halaman tidak ditemukan');
} catch (SetupException $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Setup diperlukan</title>'
        . '<body style="font:16px/1.6 system-ui;max-width:640px;margin:60px auto;padding:0 20px">'
        . '<h1>Setup diperlukan</h1><p>' . e($e->getMessage()) . '</p>'
        . '<p>Setelah itu muat ulang halaman ini.</p></body>';
    exit;
} catch (Throwable $e) {
    error_log('[KosanAman] ' . $e->getMessage());
    abort(500, getenv('KA_DEBUG') ? $e->getMessage() : 'Terjadi kesalahan pada server (set KA_DEBUG=1 untuk melihat detail)');
}
