<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Ambil data user saat ini untuk perhitungan relevansi
$currStmt = $conn->prepare("
    SELECT ap.jurusan, ap.angkatan 
    FROM users u 
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id 
    WHERE u.id = ?
");
$currStmt->bind_param('i', $userId);
$currStmt->execute();
$currUser = $currStmt->get_result()->fetch_assoc();
$myJurusan  = $currUser['jurusan'] ?? 'PPLG';
$myAngkatan = $currUser['angkatan'] ?? '2020';

// Ambil filter dan keyword
$q         = trim($_GET['q'] ?? '');
$fAngkatan = trim($_GET['angkatan'] ?? '');
$fJurusan  = trim($_GET['jurusan'] ?? '');
$fProfesi  = trim($_GET['profesi'] ?? '');

// Opsi list filter unik dari database
$angkatanList = $conn->query("SELECT DISTINCT angkatan FROM alumni_profiles WHERE angkatan IS NOT NULL AND angkatan <> '' ORDER BY angkatan DESC")->fetch_all(MYSQLI_ASSOC);
$jurusanList  = $conn->query("SELECT DISTINCT jurusan FROM alumni_profiles WHERE jurusan IS NOT NULL AND jurusan <> '' ORDER BY jurusan ASC")->fetch_all(MYSQLI_ASSOC);
$profesiList  = $conn->query("SELECT DISTINCT profesi FROM alumni_profiles WHERE profesi IS NOT NULL AND profesi <> '' ORDER BY profesi ASC")->fetch_all(MYSQLI_ASSOC);

// Query semua alumni (kecuali user sendiri)
$baseSql = "
    SELECT 
        u.id, u.username, u.created_at as user_created,
        p.nama, p.bio, p.foto,
        ap.angkatan, ap.tahun_lulus, ap.jurusan, ap.profesi, ap.perusahaan,
        (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS total_followers,
        (SELECT COUNT(*) FROM posts WHERE user_id = u.id) AS total_posts,
        (SELECT MAX(created_at) FROM posts WHERE user_id = u.id) AS last_post_at,
        (SELECT COUNT(*) FROM followers WHERE follower_id = ? AND following_id = u.id) AS is_following
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE r.nama_role = 'alumni' AND u.id <> ?
";

$params = [$userId, $userId];
$types  = 'ii';

if ($fAngkatan !== '') {
    $baseSql .= " AND ap.angkatan = ?";
    $params[] = $fAngkatan;
    $types   .= 's';
}
if ($fJurusan !== '') {
    $baseSql .= " AND ap.jurusan = ?";
    $params[] = $fJurusan;
    $types   .= 's';
}
if ($fProfesi !== '') {
    $baseSql .= " AND ap.profesi = ?";
    $params[] = $fProfesi;
    $types   .= 's';
}

$stmt = $conn->prepare($baseSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$alumniList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ============================================================
// ALGORITMA SEARCH RANKING (Proposal Bab 17.1 & Bab 6.1)
// Search Score = (Keyword Match × 0.35) + (Relevance × 0.25) + (Popularity × 0.25) + (Recency × 0.15)
// ============================================================
function hitungSearchScore($alumni, $keyword, $myJurusan, $myAngkatan) {
    // 1. Keyword Match (0 - 100)
    $km = 0;
    if ($keyword !== '') {
        $kw = strtolower($keyword);
        $nama      = strtolower($alumni['nama'] ?? '');
        $profesi   = strtolower($alumni['profesi'] ?? '');
        $perusahaan= strtolower($alumni['perusahaan'] ?? '');
        $jurusan   = strtolower($alumni['jurusan'] ?? '');
        $angkatan  = strtolower($alumni['angkatan'] ?? '');

        if (strpos($nama, $kw) !== false)       $km += 50;
        if (strpos($profesi, $kw) !== false)    $km += 30;
        if (strpos($perusahaan, $kw) !== false) $km += 15;
        if (strpos($jurusan, $kw) !== false || strpos($angkatan, $kw) !== false) $km += 15;
        $km = min(100, $km);
    } else {
        $km = 50; // Skor netral jika tanpa keyword spesifik
    }

    // 2. Relevance (0 - 100)
    $rel = 20;
    if (!empty($alumni['jurusan']) && strcasecmp($alumni['jurusan'], $myJurusan) === 0) {
        $rel += 40;
    }
    if (!empty($alumni['angkatan']) && abs((int)$alumni['angkatan'] - (int)$myAngkatan) <= 2) {
        $rel += 40;
    }
    $rel = min(100, $rel);

    // 3. Popularity (0 - 100)
    $pop = min(100, ($alumni['total_followers'] * 15) + ($alumni['total_posts'] * 5));

    // 4. Recency (0 - 100)
    $recTime = $alumni['last_post_at'] ? strtotime($alumni['last_post_at']) : strtotime($alumni['user_created']);
    $diffDays = max(0, (time() - $recTime) / 86400);
    $rec = max(0, 100 - ($diffDays * 2));

    // Rumus Pembobotan
    $score = ($km * 0.35) + ($rel * 0.25) + ($pop * 0.25) + ($rec * 0.15);
    return round($score, 1);
}

// Hitung score untuk setiap alumni
foreach ($alumniList as &$a) {
    $a['score'] = hitungSearchScore($a, $q, $myJurusan, $myAngkatan);
}
unset($a);

// Sort berdasarkan Search Score Tertinggi (Ranking)
usort($alumniList, function ($a, $b) use ($q) {
    if ($q !== '') {
        return $b['score'] <=> $a['score'];
    }
    // Jika tidak ada kata kunci, urutkan skor ranking lalu nama
    if ($b['score'] == $a['score']) {
        return strcmp($a['nama'] ?? '', $b['nama'] ?? '');
    }
    return $b['score'] <=> $a['score'];
});

$pageTitle = 'Direktori Alumni';
include __DIR__ . '/../includes/header.php';
?>

<div class="directory-container">

  <!-- Header & Kotak Pencarian Sesuai Mockup Halaman 3 -->
  <div class="card" style="margin-bottom:16px;">
    <div style="margin-bottom:12px;">
      <h2 style="font-size:18px;font-weight:800;color:var(--navy);">🔍 Direktori Alumni</h2>
      <p style="font-size:13px;color:var(--text-muted);margin-top:2px;">
        Temukan alumni berdasarkan nama, angkatan, jurusan, atau bidang karier profesional.
      </p>
    </div>

    <form method="GET" action="<?= baseUrl('pages/directory.php') ?>">
      <div class="search-box-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" name="q" class="form-input" 
               placeholder="Cari nama / angkatan / profesi / keahlian..." 
               value="<?= e($q) ?>">
      </div>

      <!-- Filter Dropdown Bar Sesuai Wireframe & Mockup -->
      <div class="filter-bar">
        <span style="font-size:12px;font-weight:700;color:var(--navy);">Filter:</span>

        <!-- Filter Angkatan -->
        <select name="angkatan" class="form-select" style="width:auto;padding:6px 12px;font-size:12px;">
          <option value="">Semua Angkatan</option>
          <?php foreach ($angkatanList as $ak): ?>
            <option value="<?= e($ak['angkatan']) ?>" <?= $fAngkatan === $ak['angkatan'] ? 'selected' : '' ?>>
              Angkatan <?= e($ak['angkatan']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Filter Jurusan -->
        <select name="jurusan" class="form-select" style="width:auto;padding:6px 12px;font-size:12px;">
          <option value="">Semua Jurusan</option>
          <?php foreach ($jurusanList as $ju): ?>
            <option value="<?= e($ju['jurusan']) ?>" <?= $fJurusan === $ju['jurusan'] ? 'selected' : '' ?>>
              <?= e($ju['jurusan']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Filter Profesi -->
        <select name="profesi" class="form-select" style="width:auto;padding:6px 12px;font-size:12px;">
          <option value="">Semua Profesi</option>
          <?php foreach ($profesiList as $pr): ?>
            <option value="<?= e($pr['profesi']) ?>" <?= $fProfesi === $pr['profesi'] ? 'selected' : '' ?>>
              <?= e($pr['profesi']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-cyan btn-sm">Terapkan Filter</button>
        <?php if ($q || $fAngkatan || $fJurusan || $fProfesi): ?>
          <a href="<?= baseUrl('pages/directory.php') ?>" class="btn btn-outline btn-sm">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Status Algoritma Penjelasan -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:0 4px;">
    <span style="font-size:13px;color:var(--text-muted);">
      Menampilkan <strong><?= count($alumniList) ?></strong> alumni terdaftar
    </span>
    <span style="font-size:11px;background:#FEF3E2;color:#B45309;padding:4px 10px;border-radius:12px;font-weight:600;">
      ⚡ Search Ranking: (Match 35% + Rel 25% + Pop 25% + Rec 15%)
    </span>
  </div>

  <!-- List Kartu Alumni Sesuai Mockup Halaman 3 -->
  <?php if (empty($alumniList)): ?>
    <div class="card text-center" style="padding:40px 20px;">
      <div style="font-size:36px;margin-bottom:8px;">👥</div>
      <h3 style="font-size:16px;font-weight:700;color:var(--navy);">Tidak ada alumni yang sesuai kriteria</h3>
      <p style="font-size:13px;color:var(--text-muted);margin-top:4px;">
        Coba gunakan kata kunci lain atau reset filter pencarian.
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($alumniList as $alumni): ?>
      <div class="alumni-card">
        <div style="display:flex;gap:14px;align-items:center;min-width:0;">
          <a href="<?= baseUrl('pages/profile.php?id=' . $alumni['id']) ?>">
            <?= renderAvatar($alumni['nama'] ?? $alumni['username'], $alumni['foto'] ?? null, '', 'width:48px;height:48px;font-size:16px;') ?>
          </a>
          <div style="min-width:0;">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <a href="<?= baseUrl('pages/profile.php?id=' . $alumni['id']) ?>" 
                 style="font-weight:700;font-size:15px;color:var(--navy);">
                <?= e($alumni['nama'] ?? $alumni['username']) ?>
              </a>
              <span class="post-badge">
                Skor <?= $alumni['score'] ?>
              </span>
            </div>

            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
              Angkatan <?= e($alumni['angkatan'] ?? '-') ?> · <?= e($alumni['jurusan'] ?? '-') ?> · 
              <strong style="color:var(--text);"><?= e($alumni['profesi'] ?? 'Alumni') ?></strong>
              <?php if (!empty($alumni['perusahaan'])): ?>
                di <em><?= e($alumni['perusahaan']) ?></em>
              <?php endif; ?>
            </div>
            
            <div style="font-size:11px;color:#94A3B8;margin-top:4px;">
              👥 <?= (int)$alumni['total_followers'] ?> Followers · 📝 <?= (int)$alumni['total_posts'] ?> Postingan
            </div>
          </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
          <a href="<?= baseUrl('pages/messages.php?to=' . $alumni['id']) ?>" 
             class="btn btn-outline btn-sm" title="Kirim Pesan Langsung">
            💬 Pesan
          </a>
          <button class="btn <?= $alumni['is_following'] ? 'btn-outline' : 'btn-primary' ?> btn-sm"
                  onclick="toggleFollow(<?= $alumni['id'] ?>, this)">
            <?= $alumni['is_following'] ? '✓ Following' : 'Follow' ?>
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<script>
function toggleFollow(userId, btn) {
  fetch('<?= baseUrl('pages/follow_action.php?ajax=1&user_id=') ?>' + userId)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        if (d.following) {
          btn.className = 'btn btn-outline btn-sm';
          btn.textContent = '✓ Following';
        } else {
          btn.className = 'btn btn-primary btn-sm';
          btn.textContent = 'Follow';
        }
      }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
