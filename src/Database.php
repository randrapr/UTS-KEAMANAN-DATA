<?php
declare(strict_types=1);

/** Dilempar bila database belum dibuat / belum berisi tabel (butuh menjalankan seed). */
final class SetupException extends RuntimeException
{
}

/** Akses database SQLite (PDO). Semua data sensitif disimpan sebagai blob terenkripsi. */
final class Database
{
    private static ?PDO $pdo = null;

    private static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            // SQLite membuat berkas kosong sendiri bila tidak ada; deteksi dulu agar pesannya jelas.
            if (!is_file(DB_PATH) || filesize(DB_PATH) === 0) {
                throw new SetupException('Database belum dibuat. Jalankan di terminal: php tools/seed.php --reset');
            }
            self::$pdo = self::connect(DB_PATH);
        }
        return self::$pdo;
    }

    /** Buat skema dan simpan public key + blob uji kunci ke tabel meta. */
    public static function init(string $path, string $publicPem): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = self::connect($path);
        $pdo->exec((string) file_get_contents(BASE_DIR . '/database/schema.sql'));
        $pub = Crypto::loadPublicKey($publicPem);
        $meta = [
            'public_key' => $publicPem,
            'fingerprint' => Crypto::fingerprint($publicPem),
            'key_check' => Crypto::encryptText($pub, SENTINEL, 'meta'),
        ];
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO meta(key, value) VALUES (?, ?)');
        foreach ($meta as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }

    public static function meta(string $key, ?PDO $pdo = null): ?string
    {
        try {
            $stmt = ($pdo ?? self::pdo())->prepare('SELECT value FROM meta WHERE key = ?');
            $stmt->execute([$key]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table')) {
                throw new SetupException('Tabel database belum ada. Jalankan di terminal: php tools/seed.php --reset');
            }
            throw $e;
        }
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public static function publicKey()
    {
        static $key = null;
        return $key ??= Crypto::loadPublicKey((string) self::meta('public_key'));
    }

    /** Enkripsi $obj lalu simpan ke tabel (dipakai aplikasi dan seed). */
    public static function insertRecord(string $table, array $obj, $publicKey, ?PDO $pdo = null): int
    {
        self::assertTable($table);
        $pdo ??= self::pdo();
        $blob = Crypto::encryptRecord($publicKey, $obj, $table);
        $pdo->prepare("INSERT INTO $table(payload) VALUES (?)")->execute([$blob]);
        return (int) $pdo->lastInsertId();
    }

    /** Ambil semua baris tabel lalu DEKRIPSI. Baris rusak ditandai _error. */
    public static function loadAll(string $table, $privateKey): array
    {
        self::assertTable($table);
        $out = [];
        foreach (self::pdo()->query("SELECT id, payload, created_at FROM $table ORDER BY id DESC") as $r) {
            try {
                $rec = Crypto::decryptRecord($privateKey, $r['payload'], $table);
            } catch (Throwable $e) {
                $rec = ['_error' => true];
            }
            $rec['id'] = (int) $r['id'];
            $rec['created_at'] = $r['created_at'];
            $out[] = $rec;
        }
        return $out;
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, TABLES, true)) {
            throw new InvalidArgumentException('Tabel tidak dikenal');
        }
    }
}
