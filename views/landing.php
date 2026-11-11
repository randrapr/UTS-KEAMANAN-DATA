<?php /** @var array $tipe @var string $today */ ?>
<header class="hero"><div class="wrap">
  <h1>Kos nyaman, <span>data penghuni</span> tetap aman.</h1>
  <p>Pesan kamar secara online. KTP, nomor HP, dan riwayat pembayaran Anda disimpan
     terenkripsi: hanya pengelola pemegang <i>private key</i> yang bisa membukanya.</p>
  <div class="cta">
    <a class="btn alt" href="#booking">Booking kamar</a>
    <a class="btn ghost" style="color:#fff;border-color:#fff" href="#kamar">Lihat tipe kamar</a>
  </div>
</div></header>

<section class="block" id="kamar"><div class="wrap">
  <h2>Tipe kamar &amp; harga</h2>
  <p class="sub">Harga sewa per bulan. Ketersediaan kamar dikonfirmasi pengelola setelah booking.</p>
  <div class="grid">
    <?php foreach ($tipe as $nama => $d): ?>
    <div class="card">
      <h3><?= e($nama) ?> <span class="fp">&middot; <?= e($d['ukuran']) ?></span></h3>
      <div class="price"><?= e(rp($d['harga'])) ?><span class="fp"> / bulan</span></div>
      <ul><?php foreach ($d['fasilitas'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endforeach; ?>
  </div>
</div></section>

<section class="block" id="cara" style="background:#ece9da"><div class="wrap">
  <h2>Cara pesan</h2>
  <p class="sub">Empat langkah dari booking sampai masuk kamar.</p>
  <div class="grid">
    <div class="card"><span class="n">1</span><h3>Pilih tipe</h3>
      <p>Tentukan tipe kamar dan tanggal rencana masuk.</p></div>
    <div class="card"><span class="n">2</span><h3>Kirim booking</h3>
      <p>Isi formulir di bawah. Data langsung dienkripsi sebelum disimpan.</p></div>
    <div class="card"><span class="n">3</span><h3>Konfirmasi</h3>
      <p>Pengelola menghubungi Anda dan menetapkan nomor kamar.</p></div>
    <div class="card"><span class="n">4</span><h3>Masuk &amp; bayar</h3>
      <p>Pembayaran bulanan dicatat pengelola dan tersimpan terenkripsi.</p></div>
  </div>
</div></section>

<section class="block" id="booking"><div class="wrap" style="max-width:640px">
  <h2>Booking kamar</h2>
  <p class="sub">Formulir ini dienkripsi dengan <i>public key</i> sistem
     <span class="pill lock-pill">AES-256-GCM + RSA-OAEP</span></p>
  <form class="stack card" method="post" action="<?= e(url('/booking')) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div><label for="nama">Nama lengkap</label>
      <input id="nama" name="nama" required minlength="2" maxlength="80"></div>
    <div class="two">
      <div><label for="hp">No. HP / WhatsApp</label>
        <input id="hp" name="hp" required pattern="[0-9+\- ]{8,20}" placeholder="0812-xxxx-xxxx"></div>
      <div><label for="tipe">Tipe kamar</label>
        <select id="tipe" name="tipe" required>
          <?php foreach ($tipe as $nama => $d): ?>
            <option value="<?= e($nama) ?>"><?= e($nama) ?> (<?= e(rp($d['harga'])) ?>/bln)</option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div><label for="tgl">Rencana tanggal masuk</label>
      <input id="tgl" name="tgl_masuk" type="date" required min="<?= e($today) ?>" value="<?= e($today) ?>"></div>
    <div><label for="pesan">Pesan (opsional)</label>
      <textarea id="pesan" name="pesan" rows="3" maxlength="300"></textarea></div>
    <button class="btn" type="submit">Kirim booking</button>
  </form>
</div></section>
