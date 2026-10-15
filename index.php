<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ALUMNICONNECT — Jembatan Alumni Sekolah</title>
  <link rel="stylesheet" href="<?= baseUrl('assets/css/style.css') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

<nav class="navbar">
  <div class="navbar-inner">
    <span class="navbar-brand">
      <span class="dot"></span>
      ALUMNI<em>CONNECT</em>
    </span>

    <button class="hamburger" aria-label="Menu">☰</button>

    <div class="navbar-menu">
      <a href="<?= baseUrl('auth/login.php') ?>">Masuk</a>
      <a href="<?= baseUrl('auth/register.php') ?>" class="btn-primary">Daftar</a>
    </div>
  </div>
</nav>

<section class="hero">
  <h1>Jembatan <em>Alumni</em> Sekolah<br>dalam Satu Platform</h1>
  <p>Terhubung kembali dengan teman seangkatan, temukan alumni di bidang yang sama, dan bangun jaringan karier profesionalmu.</p>
  <div class="hero-actions">
    <a href="<?= baseUrl('auth/register.php') ?>" class="btn btn-primary">Gabung Sekarang</a>
    <a href="<?= baseUrl('auth/login.php') ?>" class="btn btn-outline">Masuk</a>
  </div>
</section>

<section class="features">
  <div class="features-grid">
    <div class="feature-card">
      <div class="feature-icon">🔍</div>
      <h3>Direktori Alumni</h3>
      <p>Cari alumni berdasarkan angkatan, jurusan, atau profesi dengan cepat dan akurat.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">💬</div>
      <h3>Networking & Pesan</h3>
      <p>Follow dan kirim pesan langsung ke sesama alumni untuk memperluas jaringan.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon">⭐</div>
      <h3>Rekomendasi Cerdas</h3>
      <p>Dapatkan rekomendasi alumni relevan berdasarkan minat dan jurusanmu.</p>
    </div>
  </div>
</section>

<!-- INFORMASI SEKOLAH & KONTEN UNGGULAN (Sesuai Bab 5.1 KAK) -->
<section style="background:white; padding:48px 20px; border-top:1px solid var(--border); border-bottom:1px solid var(--border);">
  <div class="container" style="max-width:1000px; text-align:center;">
    <span style="font-size:12px; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:1px;">Ekosistem Digital Almamater</span>
    <h2 style="font-size:24px; font-weight:800; color:var(--navy); margin-top:6px; margin-bottom:12px;">Mendukung Kolaborasi Sekolah, Siswa & Alumni</h2>
    <p style="color:var(--text-muted); max-width:680px; margin:0 auto 32px; font-size:14px;">
      ALUMNICONNECT hadir sebagai wadah resmi SMK PPLG dalam merekam jejak karier lulusan, memfasilitasi bimbingan konseling dan peluang magang/kerja bagi siswa tingkat akhir.
    </p>

    <!-- Statistik Singkat -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
      <div style="background:var(--bg); padding:20px; border-radius:14px; border:1px solid var(--border);">
        <div style="font-size:32px; font-weight:800; color:var(--primary);">10+</div>
        <div style="font-size:13px; font-weight:600; color:var(--navy); margin-top:4px;">Alumni Profesional</div>
        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Bekerja di Gojek, Tokopedia, Traveloka</div>
      </div>

      <div style="background:var(--bg); padding:20px; border-radius:14px; border:1px solid var(--border);">
        <div style="font-size:32px; font-weight:800; color:var(--orange);">100%</div>
        <div style="font-size:13px; font-weight:600; color:var(--navy); margin-top:4px;">Jejaring Terverifikasi</div>
        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Data angkatan dan jurusan resmi</div>
      </div>

      <div style="background:var(--bg); padding:20px; border-radius:14px; border:1px solid var(--border);">
        <div style="font-size:32px; font-weight:800; color:#10B981;">30+</div>
        <div style="font-size:13px; font-weight:600; color:var(--navy); margin-top:4px;">Postingan & Lowongan</div>
        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Diskusi teknologi & informasi karier</div>
      </div>
    </div>
  </div>
</section>

<footer class="footer">
  <p>&copy; <?= date('Y') ?> <strong>ALUMNICONNECT</strong> — Finz The Dragon</p>
</footer>

<script src="<?= baseUrl('assets/js/main.js') ?>"></script>
</body>
</html>