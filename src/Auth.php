<?php
declare(strict_types=1);

/**
 * Autentikasi admin dengan PRIVATE KEY (bukan password).
 *
 * PHP tidak punya memori bersama antar-request, jadi private key tidak bisa
 * "disimpan di RAM" seperti pada server long-running. Solusinya:
 *   - Saat login, dibuat kunci sesi acak K (32 byte).
 *   - Private key dienkripsi AES-256-GCM dengan K dan disimpan di berkas sesi server.
 *   - K hanya ada di cookie HttpOnly milik browser admin (tidak pernah di server disk).
 * Jadi berkas sesi di server saja TIDAK cukup untuk memulihkan private key.
 * Logout / kedaluwarsa menghapus kedua bagian.
 */
final class Auth
{
    private const COOKIE = 'ka_k';

    private static function cookieOptions(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => is_https(),
                'httponly' => true, 'samesite' => 'Lax'];
    }

    /** Coba login. Return true bila private key sah dan cocok dengan sistem. */
    public static function attempt(string $pem): bool
    {
        try {
            $key = Crypto::loadPrivateKey($pem);
            // Bukti kepemilikan: private key harus mampu membuka blob uji.
            $ok = Crypto::decryptText($key, (string) Database::meta('key_check'), 'meta') === SENTINEL;
        } catch (Throwable $e) {
            return false;
        }
        if (!$ok) {
            return false;
        }
        session_regenerate_id(true);
        $k = random_bytes(32);
        $_SESSION['pk'] = Crypto::seal(trim(str_replace("\r\n", "\n", $pem)), $k, 'session');
        $_SESSION['last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        setcookie(self::COOKIE, Crypto::b64($k), self::cookieOptions(0));
        return true;
    }

    /** Private key sesi aktif (dan perpanjang), atau null bila belum login / kedaluwarsa. */
    public static function key()
    {
        if (empty($_SESSION['pk']) || empty($_COOKIE[self::COOKIE])) {
            return null;
        }
        if (time() - (int) ($_SESSION['last'] ?? 0) > SESSION_TTL) {
            self::logout();
            return null;
        }
        try {
            $pem = Crypto::unseal($_SESSION['pk'], Crypto::unb64((string) $_COOKIE[self::COOKIE]), 'session');
            $key = Crypto::loadPrivateKey($pem);
        } catch (Throwable $e) {
            return null;
        }
        $_SESSION['last'] = time();   // sesi bergeser (sliding)
        return $key;
    }

    public static function ttlLeft(): int
    {
        return max(0, SESSION_TTL - (time() - (int) ($_SESSION['last'] ?? 0)));
    }

    public static function logout(): void
    {
        unset($_SESSION['pk'], $_SESSION['last']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 3600));
        unset($_COOKIE[self::COOKIE]);
    }

    // --------------------------------------------- anti brute-force login
    public static function tooManyFails(string $ip): bool
    {
        $stmt = Database::pdo()->prepare('SELECT n, started FROM login_fail WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        if (time() - (int) $row['started'] > FAIL_WINDOW) {
            self::clearFails($ip);
            return false;
        }
        return (int) $row['n'] >= MAX_FAILS;
    }

    public static function recordFail(string $ip): void
    {
        Database::pdo()->prepare(
            'INSERT INTO login_fail(ip, n, started) VALUES (?, 1, ?)
             ON CONFLICT(ip) DO UPDATE SET n = n + 1'
        )->execute([$ip, time()]);
    }

    public static function clearFails(string $ip): void
    {
        Database::pdo()->prepare('DELETE FROM login_fail WHERE ip = ?')->execute([$ip]);
    }
}
