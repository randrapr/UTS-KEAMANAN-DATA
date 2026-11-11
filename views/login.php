<div class="wrap">
  <div class="lock">
    <h1>Masuk Admin</h1>
    <p style="color:var(--muted);margin-top:0">Tidak ada password. Autentikasi memakai
       <b>private key</b> (PEM) Anda. Key diverifikasi lalu hanya disimpan di sesi dalam
       bentuk terenkripsi (kuncinya ada di cookie browser Anda).</p>
    <form class="stack" method="post" action="<?= e(url('/admin/login')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div><label for="private_key">Private key (PEM)</label>
        <textarea id="private_key" name="private_key" class="key" required
          placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
          spellcheck="false" autocomplete="off"></textarea></div>
      <div><label for="keyfile">atau pilih file private_key.pem</label>
        <input id="keyfile" type="file" accept=".pem,.key,text/plain"></div>
      <button class="btn" type="submit">Buka brankas &amp; dekripsi</button>
    </form>
    <p class="fp" style="margin-top:18px">Sidik jari kunci sistem (SHA-256):<br><?= e($fingerprint) ?></p>
  </div>
</div>
<script>
  document.getElementById('keyfile').addEventListener('change', function (e) {
    var f = e.target.files[0]; if (!f) return;
    var r = new FileReader();
    r.onload = function () { document.getElementById('private_key').value = r.result; };
    r.readAsText(f);
  });
</script>
