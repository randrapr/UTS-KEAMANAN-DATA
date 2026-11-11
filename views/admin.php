<?php
/** @var array $booking @var array $penghuni @var array $kamar @var array $bayar @var array $kosong
 *  @var array $stats @var string $periode @var array $inspector @var string $fingerprint
 *  @var int $ttl @var string $today */
?>
<div class="wrap">
  <div class="adminhead">
    <div>
      <h1>Dashboard Admin</h1>
      <span class="pill">Terdekripsi dengan private key Anda</span>
      <span class="fp">&nbsp;Kunci: <?= e(substr($fingerprint, 0, 23)) ?>…</span>
    </div>
    <span class="fp">Sesi aktif ± <?= e($ttl) ?> menit (otomatis diperpanjang)</span>
  </div>

  <div class="stats">
    <div class="stat"><b><?= e($stats['terisi']) ?>/<?= e($stats['kamar']) ?></b><span>Kamar terisi</span></div>
    <div class="stat"><b><?= e($stats['kosong']) ?></b><span>Kamar kosong</span></div>
    <div class="stat"><b><?= e($stats['penghuni']) ?></b><span>Penghuni</span></div>
    <div class="stat"><b><?= e($stats['booking']) ?></b><span>Booking menunggu</span></div>
    <div class="stat"><b><?= e(rp($stats['pemasukan'])) ?></b><span>Pemasukan <?= e($periode) ?></span></div>
    <div class="stat"><b><?= e($stats['tunggakan']) ?></b><span>Belum bayar <?= e($periode) ?></span></div>
  </div>

  <!-- ===================== BOOKING ===================== -->
  <div class="panel" id="booking">
    <h2>Booking dari landing page <span class="pill lock-pill">dekripsi</span></h2>
    <div class="tablewrap"><table>
      <tr><th>#</th><th>Nama</th><th>No. HP</th><th>Tipe</th><th>Rencana masuk</th><th>Pesan</th><th>Proses</th></tr>
      <?php foreach ($booking as $b): ?>
        <?php if (!empty($b['_error'])): ?>
          <tr><td><?= e($b['id']) ?></td><td colspan="6" class="err-row">Gagal dekripsi (data rusak / kunci salah)</td></tr>
        <?php else: ?>
          <tr><td><?= e($b['id']) ?></td><td><?= e($b['nama']) ?></td><td><?= e($b['hp']) ?></td>
            <td><?= e($b['tipe']) ?></td><td><?= e($b['tgl_masuk']) ?></td>
            <td><?= e($b['pesan'] !== '' ? $b['pesan'] : '-') ?></td>
            <td>
              <?php if ($kosong): $sel = false; ?>
              <form class="cell-form" method="post" action="<?= e(url('/admin/booking/' . $b['id'] . '/terima')) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <select name="kamar_id" required>
                  <?php foreach ($kosong as $k): $pick = !$sel && $k['tipe'] === $b['tipe']; $sel = $sel || $pick; ?>
                    <option value="<?= e($k['id']) ?>"<?= $pick ? ' selected' : '' ?>><?= e($k['nomor']) ?> (<?= e($k['tipe']) ?>)</option>
                  <?php endforeach; ?>
                </select>
                <input name="ktp" maxlength="16" pattern="[0-9]{16}" inputmode="numeric" placeholder="NIK 16 digit (opsional)">
                <button class="btn sm" type="submit">Terima &amp; tempatkan</button>
              </form>
              <?php else: ?><span class="fp">Tidak ada kamar kosong</span><br><?php endif; ?>
              <?= hapus_form('booking', $b['id']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$booking): ?><tr><td colspan="7">Belum ada booking baru.</td></tr><?php endif; ?>
    </table></div>
  </div>

  <!-- ===================== PENGHUNI ===================== -->
  <div class="panel" id="penghuni">
    <h2>Data penghuni <span class="pill lock-pill">dekripsi</span></h2>
    <div class="tablewrap"><table>
      <tr><th>ID</th><th>Nama</th><th>No. HP</th><th>NIK KTP</th><th>Kamar</th><th>Masuk</th>
          <th>Kontak darurat</th><th>Sewa <?= e($periode) ?></th><th></th></tr>
      <?php foreach ($penghuni as $p): ?>
        <?php if (!empty($p['_error'])): ?>
          <tr><td><?= e($p['id']) ?></td><td colspan="8" class="err-row">Gagal dekripsi</td></tr>
        <?php else: ?>
          <tr><td><?= e($p['id']) ?></td><td><?= e($p['nama']) ?></td><td><?= e($p['hp']) ?></td>
            <td><?= e($p['ktp'] !== '' ? $p['ktp'] : '-') ?></td><td><?= e($p['kamar_nomor']) ?></td>
            <td><?= e($p['tgl_masuk']) ?></td>
            <td><?= e($p['darurat_nama'] !== '' ? $p['darurat_nama'] . ' (' . $p['darurat_hp'] . ')' : '-') ?></td>
            <td><?= $p['lunas'] ? '<span class="badge ok">Lunas</span>' : '<span class="badge no">Belum bayar</span>' ?></td>
            <td><?= hapus_form('penghuni', $p['id']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$penghuni): ?><tr><td colspan="9">Belum ada penghuni.</td></tr><?php endif; ?>
    </table></div>
    <details class="add"><summary>+ Tambah penghuni</summary>
      <?php if (!$kosong): ?><p class="fp">Tidak ada kamar kosong. Tambahkan kamar dulu atau check-out penghuni.</p>
      <?php else: ?>
      <form class="stack" method="post" action="<?= e(url('/admin/penghuni')) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="two">
          <div><label>Nama</label><input name="nama" required minlength="2" maxlength="80"></div>
          <div><label>No. HP</label><input name="hp" required pattern="[0-9+\- ]{8,20}"></div>
        </div>
        <div class="two">
          <div><label>NIK KTP (16 digit, opsional)</label>
            <input name="ktp" maxlength="16" pattern="[0-9]{16}" inputmode="numeric"></div>
          <div><label>Tanggal masuk</label><input name="tgl_masuk" type="date" required value="<?= e($today) ?>"></div>
        </div>
        <div class="three">
          <div><label>Kamar</label>
            <select name="kamar_id" required>
              <?php foreach ($kosong as $k): ?>
                <option value="<?= e($k['id']) ?>"><?= e($k['nomor']) ?> (<?= e($k['tipe']) ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Kontak darurat (nama)</label><input name="darurat_nama" maxlength="80"></div>
          <div><label>Kontak darurat (HP)</label><input name="darurat_hp" pattern="[0-9+\- ]{8,20}"></div>
        </div>
        <div><button class="btn" type="submit">Simpan (terenkripsi)</button></div>
      </form>
      <?php endif; ?>
    </details>
  </div>

  <!-- ===================== KAMAR ===================== -->
  <div class="panel" id="kamar">
    <h2>Data kamar <span class="pill lock-pill">dekripsi</span></h2>
    <div class="tablewrap"><table>
      <tr><th>ID</th><th>Nomor</th><th>Tipe</th><th>Lantai</th><th class="r">Harga / bln</th><th>Status</th><th>Catatan</th><th></th></tr>
      <?php foreach ($kamar as $k): ?>
        <?php if (!empty($k['_error'])): ?>
          <tr><td><?= e($k['id']) ?></td><td colspan="7" class="err-row">Gagal dekripsi</td></tr>
        <?php else: ?>
          <tr><td><?= e($k['id']) ?></td><td><strong><?= e($k['nomor']) ?></strong></td><td><?= e($k['tipe']) ?></td>
            <td><?= e($k['lantai']) ?></td><td class="r"><?= e(rp($k['harga'])) ?></td>
            <td><?= $k['penghuni'] !== null
                ? '<span class="badge no">Terisi</span> ' . e($k['penghuni'])
                : '<span class="badge free">Kosong</span>' ?></td>
            <td><?= e($k['catatan'] !== '' ? $k['catatan'] : '-') ?></td>
            <td><?= hapus_form('kamar', $k['id']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$kamar): ?><tr><td colspan="8">Belum ada kamar.</td></tr><?php endif; ?>
    </table></div>
    <details class="add"><summary>+ Tambah kamar</summary>
      <form class="stack" method="post" action="<?= e(url('/admin/kamar')) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="three">
          <div><label>Nomor kamar</label><input name="nomor" required maxlength="10" placeholder="mis. A4"></div>
          <div><label>Tipe</label>
            <select name="tipe" required>
              <?php foreach (TIPE_KAMAR as $nama => $d): ?>
                <option value="<?= e($nama) ?>"><?= e($nama) ?> (<?= e(rp($d['harga'])) ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Lantai</label><input name="lantai" type="number" min="1" max="20" value="1" required></div>
        </div>
        <div><label>Catatan</label><input name="catatan" maxlength="100"></div>
        <div><button class="btn" type="submit">Simpan (terenkripsi)</button></div>
      </form></details>
  </div>

  <!-- ===================== PEMBAYARAN ===================== -->
  <div class="panel" id="pembayaran">
    <h2>Riwayat pembayaran sewa <span class="pill lock-pill">dekripsi</span></h2>
    <div class="tablewrap"><table>
      <tr><th>#</th><th>Tanggal</th><th>Penghuni</th><th>Periode</th><th class="r">Jumlah</th><th>Metode</th><th>Catatan</th><th></th></tr>
      <?php foreach ($bayar as $b): ?>
        <?php if (!empty($b['_error'])): ?>
          <tr><td><?= e($b['id']) ?></td><td colspan="7" class="err-row">Gagal dekripsi</td></tr>
        <?php else: ?>
          <tr><td><?= e($b['id']) ?></td><td><?= e($b['tanggal']) ?></td><td><?= e($b['penghuni_nama']) ?></td>
            <td><?= e($b['periode']) ?></td><td class="r"><?= e(rp($b['jumlah'])) ?></td><td><?= e($b['metode']) ?></td>
            <td><?= e($b['catatan'] !== '' ? $b['catatan'] : '-') ?></td>
            <td><?= hapus_form('pembayaran', $b['id']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$bayar): ?><tr><td colspan="8">Belum ada pembayaran.</td></tr><?php endif; ?>
    </table></div>
    <details class="add"><summary>+ Catat pembayaran</summary>
      <form class="stack" method="post" action="<?= e(url('/admin/pembayaran')) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="two">
          <div><label>Penghuni</label>
            <select name="penghuni_id" required>
              <?php foreach ($penghuni as $p): if (!empty($p['_error'])) continue; ?>
                <option value="<?= e($p['id']) ?>"><?= e($p['nama']) ?> (<?= e($p['kamar_nomor']) ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Periode (YYYY-MM)</label>
            <input name="periode" required pattern="[0-9]{4}-(0[1-9]|1[0-2])" value="<?= e($periode) ?>"></div>
        </div>
        <div class="three">
          <div><label>Jumlah (Rp)</label><input name="jumlah" type="number" min="1" max="100000000" required></div>
          <div><label>Metode</label>
            <select name="metode" required>
              <?php foreach (METODE as $m): ?><option><?= e($m) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Catatan</label><input name="catatan" maxlength="100"></div>
        </div>
        <div><button class="btn" type="submit">Simpan (terenkripsi)</button></div>
      </form></details>
  </div>

  <!-- ===================== INSPEKTUR DB ===================== -->
  <div class="panel" id="inspektur">
    <h2>Isi database sebenarnya <span class="pill">ciphertext</span></h2>
    <p class="fp" style="margin-top:0">Inilah yang tersimpan di SQLite: tanpa private key, isinya tidak dapat dibaca.</p>
    <div class="tablewrap"><table>
      <tr><th>Tabel</th><th>ID</th><th class="r">Panjang</th><th>Blob terenkripsi (potongan)</th></tr>
      <?php foreach ($inspector as $r): ?>
      <tr><td><?= e($r['tabel']) ?></td><td><?= e($r['id']) ?></td><td class="r"><?= e($r['panjang']) ?></td>
          <td class="cipher"><?= e($r['cipher']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
</div>
