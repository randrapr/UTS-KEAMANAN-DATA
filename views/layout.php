<?php
/** @var string $title @var string $content @var bool $admin */
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
</head>
<body>
<nav class="top">
  <div class="wrap">
    <a class="brand" href="<?= e(url('/')) ?>">Kosan<b>Aman</b></a>
    <div>
      <?php if ($admin): ?>
        <a href="#booking">Booking</a><a href="#penghuni">Penghuni</a><a href="#kamar">Kamar</a>
        <a href="#pembayaran">Pembayaran</a><a href="#inspektur">Database</a>
        <form class="inline" method="post" action="<?= e(url('/admin/logout')) ?>">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn sm alt" style="margin-left:20px" type="submit">Keluar</button>
        </form>
      <?php else: ?>
        <a href="<?= e(url('/')) ?>#kamar">Kamar</a>
        <a href="<?= e(url('/')) ?>#cara">Cara Pesan</a>
        <a href="<?= e(url('/')) ?>#booking">Booking</a>
        <a href="<?= e(url('/admin/login')) ?>">Admin</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<div class="wrap">
  <?php foreach ($flashes as [$cat, $msg]): ?>
    <div class="flash <?= e($cat) ?>"><?= e($msg) ?></div>
  <?php endforeach; ?>
</div>
<?= $content ?>
<footer><div class="wrap">
  &copy; 2026 Kosan Aman. Data penghuni (KTP, kontak, pembayaran) disimpan terenkripsi
  (AES-256-GCM + RSA-3072). <a href="<?= e(url('/admin/login')) ?>">Masuk Admin</a>
</div></footer>
</body>
</html>
