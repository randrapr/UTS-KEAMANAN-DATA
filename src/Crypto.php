<?php
declare(strict_types=1);

/**
 * Utilitas kriptografi Bank Sampah Digital (PHP + ekstensi OpenSSL).
 *
 * Skema: ENKRIPSI HIBRIDA
 *  - Setiap record dienkripsi dengan kunci data acak AES-256-GCM (DEK).
 *  - DEK dibungkus (wrap) dengan RSA-3072 OAEP memakai PUBLIC KEY.
 *  - Hanya pemilik PRIVATE KEY (admin) yang dapat membuka DEK lalu mendekripsi record.
 *
 * Format blob yang disimpan di database:
 *     v1.<RSA-wrapped DEK>.<nonce 96-bit>.<ciphertext || tag 128-bit>   (base64url)
 *
 * AAD (Additional Authenticated Data) = nama tabel, sehingga blob tidak dapat
 * dipindahkan ke tabel lain tanpa terdeteksi (integritas dijamin oleh GCM tag).
 *
 * Catatan: openssl_public_encrypt() di PHP memakai OAEP dengan hash SHA-1
 * (MGF1-SHA1). Untuk membungkus kunci acak 256-bit ini tetap aman karena
 * keamanan OAEP tidak bergantung pada ketahanan tabrakan hash.
 */
final class Crypto
{
    public const VERSION = 'v1';
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LEN = 16;
    private const MAX_PEM_BYTES = 16384;

    // ----------------------------------------------------------- base64url
    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        if ($raw === false) {
            throw new RuntimeException('Base64 tidak valid');
        }
        return $raw;
    }

    // ---------------------------------------------------------------- kunci
    /** Hasilkan pasangan kunci RSA. Return [private_pem, public_pem]. */
    public static function generateKeypair(int $bits = 3072): array
    {
        $cfg = [];
        $opts = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = @openssl_pkey_new($opts);
        if ($res === false) {
            // Windows: openssl.cnf sering tidak ditemukan otomatis. Coba lokasi umum (Laragon/XAMPP).
            $bin = dirname(PHP_BINARY);
            foreach ([getenv('OPENSSL_CONF') ?: '', "$bin/extras/ssl/openssl.cnf", "$bin/extras/openssl/openssl.cnf",
                      "$bin/openssl.cnf", 'C:/xampp/php/extras/ssl/openssl.cnf'] as $path) {
                if ($path !== '' && is_file($path)) {
                    $cfg = ['config' => $path];
                    $res = @openssl_pkey_new($opts + $cfg);
                    if ($res !== false) {
                        break;
                    }
                }
            }
        }
        if ($res === false) {
            throw new RuntimeException('Gagal membuat kunci RSA: ' . (openssl_error_string()
                ?: 'konfigurasi OpenSSL tidak ditemukan (set OPENSSL_CONF ke berkas openssl.cnf)'));
        }
        openssl_pkey_export($res, $privPem, null, $cfg);
        $pubPem = openssl_pkey_get_details($res)['key'];
        return [$privPem, $pubPem];
    }

    public static function loadPublicKey(string $pem)
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Public key tidak valid');
        }
        return $key;
    }

    /**
     * Muat private key dari teks PEM. Lempar RuntimeException bila tidak valid.
     * PEM divalidasi ketat: openssl_pkey_get_private() menganggap string
     * "file://..." sebagai path berkas, sehingga input HARUS berupa blok PEM murni.
     */
    public static function loadPrivateKey(string $pem)
    {
        $pem = trim(str_replace("\r\n", "\n", $pem));
        if ($pem === '' || strlen($pem) > self::MAX_PEM_BYTES) {
            throw new RuntimeException('Private key kosong atau terlalu besar');
        }
        if (!preg_match('/\A-----BEGIN (RSA )?PRIVATE KEY-----\n[A-Za-z0-9+\/=\n]+\n-----END (RSA )?PRIVATE KEY-----\z/', $pem)) {
            throw new RuntimeException('Format private key tidak valid');
        }
        $key = @openssl_pkey_get_private($pem);
        while (openssl_error_string() !== false) { /* kuras antrean error */ }
        if ($key === false) {
            throw new RuntimeException('Private key tidak dapat dibaca');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('Hanya private key RSA yang didukung');
        }
        return $key;
    }

    /** Sidik jari SHA-256 dari public key (untuk identifikasi kunci). */
    public static function fingerprint(string $publicPem): string
    {
        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicPem);
        $hash = strtoupper(hash('sha256', base64_decode($body, true) ?: ''));
        return implode(':', str_split(substr($hash, 0, 32), 2));
    }

    // ------------------------------------------------ AES-256-GCM (simetris)
    private static function gcmEncrypt(string $plain, string $key, string $aad): array
    {
        $iv = random_bytes(12);               // nonce unik per enkripsi
        $tag = '';
        $ct = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('Enkripsi gagal');
        }
        return [$iv, $ct . $tag];
    }

    private static function gcmDecrypt(string $iv, string $ctTag, string $key, string $aad): string
    {
        if (strlen($ctTag) < self::TAG_LEN || strlen($iv) !== 12) {
            throw new RuntimeException('Data terenkripsi rusak');
        }
        $ct = substr($ctTag, 0, -self::TAG_LEN);
        $tag = substr($ctTag, -self::TAG_LEN);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            throw new RuntimeException('Dekripsi gagal (kunci salah / data diubah)');
        }
        return $plain;
    }

    /** Enkripsi simetris dengan kunci 32 byte (dipakai menyimpan private key di sesi). */
    public static function seal(string $plain, string $key, string $aad): string
    {
        [$iv, $ctTag] = self::gcmEncrypt($plain, $key, $aad);
        return self::b64($iv) . '.' . self::b64($ctTag);
    }

    public static function unseal(string $blob, string $key, string $aad): string
    {
        $parts = explode('.', $blob);
        if (count($parts) !== 2) {
            throw new RuntimeException('Format tidak valid');
        }
        return self::gcmDecrypt(self::unb64($parts[0]), self::unb64($parts[1]), $key, $aad);
    }

    // -------------------------------------------------- enkripsi hibrida
    public static function encryptText($publicKey, string $text, string $aad): string
    {
        $dek = random_bytes(32);              // kunci AES-256 acak
        [$iv, $ctTag] = self::gcmEncrypt($text, $dek, $aad);
        if (!openssl_public_encrypt($dek, $wrapped, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('Gagal membungkus kunci data');
        }
        return implode('.', [self::VERSION, self::b64($wrapped), self::b64($iv), self::b64($ctTag)]);
    }

    public static function decryptText($privateKey, string $blob, string $aad): string
    {
        $parts = explode('.', $blob);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Versi / format blob tidak dikenal');
        }
        if (!openssl_private_decrypt(self::unb64($parts[1]), $dek, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('Gagal membuka kunci data (private key salah)');
        }
        return self::gcmDecrypt(self::unb64($parts[2]), self::unb64($parts[3]), $dek, $aad);
    }

    public static function encryptRecord($publicKey, array $obj, string $aad): string
    {
        return self::encryptText($publicKey, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $aad);
    }

    public static function decryptRecord($privateKey, string $blob, string $aad): array
    {
        return json_decode(self::decryptText($privateKey, $blob, $aad), true, 512, JSON_THROW_ON_ERROR);
    }
}
