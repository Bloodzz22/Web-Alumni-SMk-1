<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

// Hanya role Admin (1) dan Guru (2) yang boleh mengakses dashboard
$userRole = (int)($_SESSION['role_id'] ?? 0);
if ($userRole !== 1 && $userRole !== 2) {
    setFlash('danger', 'Halaman ini khusus untuk Guru dan Administrator.');
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

// 1. STATISTIK UTAMA
$totalUsers = $conn->query("SELECT COUNT(*) AS total FROM users")->fetch_assoc()['total'];
$totalAlumni = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role_id = 4")->fetch_assoc()['total'];
$totalSiswa  = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role_id = 3")->fetch_assoc()['total'];
$totalGuru   = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role_id = 2")->fetch_assoc()['total'];
$totalPosts  = $conn->query("SELECT COUNT(*) AS total FROM posts")->fetch_assoc()['total'];
$totalInteractions = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM likes) + 
        (SELECT COUNT(*) FROM comments) + 
        (SELECT COUNT(*) FROM shares) AS total
")->fetch_assoc()['total'];

// 2. SEBARAN ALUMNI PER ANGKATAN
$alumniPerAngkatan = $conn->query("
    SELECT angkatan, COUNT(*) AS jumlah 
    FROM alumni_profiles 
    WHERE angkatan IS NOT NULL AND angkatan <> ''
    GROUP BY angkatan 
    ORDER BY angkatan DESC
")->fetch_all(MYSQLI_ASSOC);

// 3. SEBARAN ALUMNI PER JURUSAN
$alumniPerJurusan = $conn->query("
    SELECT jurusan, COUNT(*) AS jumlah 
    FROM alumni_profiles 
    WHERE jurusan IS NOT NULL AND jurusan <> ''
    GROUP BY jurusan 
    ORDER BY jumlah DESC
")->fetch_all(MYSQLI_ASSOC);

// 4. DAFTAR ALUMNI UNTUK LAPORAN / MONITORING
$alumniTable = $conn->query("
    SELECT 
        u.id, u.username, u.email,
        p.nama,
        ap.angkatan, ap.jurusan, ap.profesi, ap.perusahaan
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE r.nama_role = 'alumni'
    ORDER BY ap.angkatan DESC, p.nama ASC
")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Dashboard Sekolah & Data Alumni';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:1100px; margin-top:24px;">

  <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="font-size:20px; font-weight:800; color:var(--navy);">📊 Dashboard Pemantauan Alumni & Sekolah</h2>
      <p style="font-size:13px; color:var(--text-muted); margin-top:2px;">
        Panel resmi untuk Guru BK dan Administrator dalam memantau jejak karier serta data alumni.
      </p>
    </div>
    <div style="display:flex; gap:8px;">
      <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Cetak / Unduh Laporan</button>
      <a href="<?= baseUrl('pages/feed.php') ?>" class="btn btn-outline btn-sm">Kembali ke Feed</a>
    </div>
  </div>

  <!-- Kartu Statistik (4 Kotak) -->
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
    
    <div class="card" style="padding:18px;">
      <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Total Alumni Terdata</div>
      <div style="font-size:28px; font-weight:800; color:var(--primary); margin-top:6px;"><?= $totalAlumni ?></div>
      <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Lulusan terhubung di sistem</div>
    </div>

    <div class="card" style="padding:18px;">
      <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Total Siswa Aktif</div>
      <div style="font-size:28px; font-weight:800; color:#10B981; margin-top:6px;"><?= $totalSiswa ?></div>
      <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Siswa tingkat akhir</div>
    </div>

    <div class="card" style="padding:18px;">
      <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Total Postingan & Informasi</div>
      <div style="font-size:28px; font-weight:800; color:var(--navy); margin-top:6px;"><?= $totalPosts ?></div>
      <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Berita, karya, & karier</div>
    </div>

    <div class="card" style="padding:18px;">
      <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Total Interaksi Sosial</div>
      <div style="font-size:28px; font-weight:800; color:var(--orange); margin-top:6px;"><?= $totalInteractions ?></div>
      <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Likes, komentar, & share</div>
    </div>

  </div>

  <!-- Grafik / Distribusi Alumni -->
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:24px;">
    
    <!-- Distribusi Angkatan -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">📅 Sebaran Alumni per Angkatan</span>
      </div>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($alumniPerAngkatan as $a): ?>
          <div>
            <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; margin-bottom:4px;">
              <span>Angkatan <?= e($a['angkatan']) ?></span>
              <span style="color:var(--primary);"><?= $a['jumlah'] ?> alumni</span>
            </div>
            <div style="background:#E2E8F0; border-radius:10px; height:8px; overflow:hidden;">
              <div style="background:var(--primary); height:100%; width: <?= min(100, $a['jumlah'] * 25) ?>%;"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Distribusi Jurusan -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">🎓 Sebaran Alumni per Jurusan</span>
      </div>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($alumniPerJurusan as $j): ?>
          <div>
            <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; margin-bottom:4px;">
              <span>Jurusan <?= e($j['jurusan']) ?></span>
              <span style="color:var(--orange);"><?= $j['jumlah'] ?> alumni</span>
            </div>
            <div style="background:#E2E8F0; border-radius:10px; height:8px; overflow:hidden;">
              <div style="background:var(--orange); height:100%; width: <?= min(100, $j['jumlah'] * 20) ?>%;"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Tabel Data Lengkap Alumni Sesuai Flowchart Guru BK -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">📋 Laporan Direktori Alumni Lengkap</span>
      <span style="font-size:12px; color:var(--text-muted);">Data real-time dari sistem</span>
    </div>

    <div style="overflow-x:auto;">
      <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
        <thead>
          <tr style="border-bottom:2px solid var(--border); color:var(--navy); font-weight:700;">
            <th style="padding:10px;">No</th>
            <th style="padding:10px;">Nama Alumni</th>
            <th style="padding:10px;">Email</th>
            <th style="padding:10px;">Angkatan</th>
            <th style="padding:10px;">Jurusan</th>
            <th style="padding:10px;">Profesi Terkini</th>
            <th style="padding:10px;">Perusahaan</th>
            <th style="padding:10px; text-align:center;">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php $no = 1; foreach ($alumniTable as $row): ?>
            <tr style="border-bottom:1px solid var(--border);">
              <td style="padding:10px;"><?= $no++ ?></td>
              <td style="padding:10px; font-weight:600; color:var(--navy);"><?= e($row['nama'] ?? $row['username']) ?></td>
              <td style="padding:10px; color:var(--text-muted);"><?= e($row['email']) ?></td>
              <td style="padding:10px;"><?= e($row['angkatan'] ?? '-') ?></td>
              <td style="padding:10px;"><?= e($row['jurusan'] ?? '-') ?></td>
              <td style="padding:10px; font-weight:500; color:var(--primary);"><?= e($row['profesi'] ?? '-') ?></td>
              <td style="padding:10px;"><?= e($row['perusahaan'] ?? '-') ?></td>
              <td style="padding:10px; text-align:center;">
                <a href="<?= baseUrl('pages/profile.php?id=' . $row['id']) ?>" class="btn btn-outline btn-sm" style="padding:4px 10px; font-size:11px;">
                  Detail
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
