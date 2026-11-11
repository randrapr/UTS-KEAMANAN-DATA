-- Skema database Kosan Aman (SQLite)
-- Kolom `payload` berisi BLOB TERENKRIPSI (AES-256-GCM + RSA-OAEP), bukan data asli.

CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Booking calon penghuni dari landing page (publik -> terenkripsi)
CREATE TABLE IF NOT EXISTS booking (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Data penghuni (nama, HP, NIK KTP, kontak darurat, kamar terenkripsi)
CREATE TABLE IF NOT EXISTS penghuni (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Unit kamar (nomor, tipe, lantai, catatan terenkripsi)
CREATE TABLE IF NOT EXISTS kamar (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Pembayaran sewa bulanan (penghuni, periode, jumlah, metode terenkripsi)
CREATE TABLE IF NOT EXISTS pembayaran (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Pembatasan percobaan login gagal (anti brute-force)
CREATE TABLE IF NOT EXISTS login_fail (
    ip      TEXT PRIMARY KEY,
    n       INTEGER NOT NULL,
    started INTEGER NOT NULL
);
