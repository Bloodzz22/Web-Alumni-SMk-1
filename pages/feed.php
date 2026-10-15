<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Ambil info user aktif (jurusan & angkatan)
$uStmt = $conn->prepare("
    SELECT pr.foto, ap.jurusan, ap.angkatan 
    FROM users u 
    LEFT JOIN profiles pr ON pr.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id 
    WHERE u.id = ?
");
$uStmt->bind_param('i', $userId);
$uStmt->execute();
$myInfo = $uStmt->get_result()->fetch_assoc();
$myJurusan  = $myInfo['jurusan'] ?? 'PPLG';
$myAngkatan = $myInfo['angkatan'] ?? '2020';

// Filter berdasarkan kategori atau hashtag jika ada
$catFilter = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$tagFilter = isset($_GET['tag']) ? trim($_GET['tag']) : '';

// Feed SQL: Post diurutkan berdasarkan Engagement Score Ranking:
// Engagement = (Like * 1) + (Comment * 3) + (Share * 4) + Recency
$sql = "
    SELECT 
        p.id, p.konten, p.media, p.created_at,
        u.id AS user_id, u.username,
        pr.nama, pr.foto,
        ap.jurusan, ap.profesi,
        c.nama_kategori,
        (SELECT COUNT(*) FROM likes    WHERE post_id = p.id) AS total_like,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS total_comment,
        (SELECT COUNT(*) FROM shares   WHERE post_id = p.id) AS total_share,
        (SELECT COUNT(*) FROM likes    WHERE post_id = p.id AND user_id = ?) AS is_liked
    FROM posts p
    JOIN users u                 ON u.id = p.user_id
    LEFT JOIN profiles pr        ON pr.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    LEFT JOIN categories c       ON c.id = p.category_id
";

$whereClauses = [];
$params = [$userId];
$types  = 'i';

if ($catFilter > 0) {
    $whereClauses[] = "p.category_id = ?";
    $params[] = $catFilter;
    $types   .= 'i';
}
if ($tagFilter !== '') {
    $whereClauses[] = "p.id IN (SELECT post_id FROM post_hashtags ph JOIN hashtags h ON h.id = ph.hashtag_id WHERE h.tag = ?)";
    $params[] = strtolower($tagFilter);
    $types   .= 's';
}

if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}

// Algoritma Engagement Ranking (Proposal Bab 6.2 & Bab 18)
$sql .= "
    ORDER BY 
        ((SELECT COUNT(*) FROM likes    WHERE post_id = p.id) * 1 +
         (SELECT COUNT(*) FROM comments WHERE post_id = p.id) * 3 +
         (SELECT COUNT(*) FROM shares   WHERE post_id = p.id) * 4) DESC,
        p.created_at DESC
    LIMIT 40
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ============================================================
// ALGORITMA REKOMENDASI ALUMNI (Proposal Bab 17.2 & Bab 6.3)
// Score = (Interest × 0.35) + (Interaction × 0.30) + (Similarity × 0.20) + (Recency × 0.15)
// ============================================================
$allAlumniStmt = $conn->prepare("
    SELECT 
        u.id, u.username, u.created_at,
        pr.nama, pr.foto, ap.jurusan, ap.profesi, ap.angkatan, ap.perusahaan,
        (SELECT COUNT(*) FROM followers WHERE follower_id = ? AND following_id = u.id) AS is_followed,
        (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS total_followers,
        (SELECT COUNT(*) FROM comments WHERE user_id = u.id) + (SELECT COUNT(*) FROM likes WHERE user_id = u.id) AS interaction_count
    FROM alumni_profiles ap
    JOIN users u          ON u.id = ap.user_id
    LEFT JOIN profiles pr ON pr.user_id = u.id
    WHERE u.id <> ?
");
$allAlumniStmt->bind_param('ii', $userId, $userId);
$allAlumniStmt->execute();
$rawAlumni = $allAlumniStmt->get_result()->fetch_all(MYSQLI_ASSOC);

function hitungRecommendationScore($alumni, $myJurusan, $myAngkatan) {
    // 1. Interest: kesamaan bidang jurusan (0 - 100)
    $interest = 30;
    if (!empty($alumni['jurusan']) && strcasecmp($alumni['jurusan'], $myJurusan) === 0) {
        $interest += 60;
    }
    $interest = min(100, $interest);

    // 2. Interaction: keaktifan like & comment (0 - 100)
    $interaction = min(100, (int)$alumni['interaction_count'] * 12);

    // 3. Similarity: kemiripan angkatan (0 - 100)
    $similarity = 30;
    if (!empty($alumni['angkatan']) && abs((int)$alumni['angkatan'] - (int)$myAngkatan) <= 2) {
        $similarity += 60;
    }
    $similarity = min(100, $similarity);

    // 4. Recency (0 - 100)
    $recTime = strtotime($alumni['created_at']);
    $diffDays = max(0, (time() - $recTime) / 86400);
    $recency = max(0, 100 - ($diffDays * 1.5));

    $finalScore = ($interest * 0.35) + ($interaction * 0.30) + ($similarity * 0.20) + ($recency * 0.15);
    return round($finalScore, 1);
}

foreach ($rawAlumni as &$ra) {
    $ra['rec_score'] = hitungRecommendationScore($ra, $myJurusan, $myAngkatan);
}
unset($ra);

// Urutkan rekomendasi alumni tertinggi yang belum di-follow
usort($rawAlumni, function($a, $b) {
    if ($a['is_followed'] != $b['is_followed']) {
        return $a['is_followed'] <=> $b['is_followed'];
    }
    return $b['rec_score'] <=> $a['rec_score'];
});
$rekomendasi = array_slice($rawAlumni, 0, 5);

// Ambil trending hashtag
$trendingTags = $conn->query("
    SELECT h.tag, COUNT(ph.post_id) AS total
    FROM hashtags h
    JOIN post_hashtags ph ON ph.hashtag_id = h.id
    GROUP BY h.id, h.tag
    ORDER BY total DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Ambil kategori untuk filter bar
$categories = $conn->query("SELECT * FROM categories ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Beranda Feed';
include __DIR__ . '/../includes/header.php';
?>

<div class="feed-layout">

  <!-- Sidebar Kiri: Navigasi Desktop Sesuai Wireframe -->
  <aside class="sidebar sidebar-left">
    <ul class="sidebar-menu">
      <li><a href="<?= baseUrl('pages/feed.php') ?>" class="<?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">🏠 Beranda Feed</a></li>
      <li><a href="<?= baseUrl('pages/directory.php') ?>">🔍 Direktori Alumni</a></li>
      <li><a href="<?= baseUrl('pages/messages.php') ?>">💬 Pesan & Chat</a></li>
      <li><a href="<?= baseUrl('pages/notifications.php') ?>">🔔 Notifikasi</a></li>
      <li><a href="<?= baseUrl('pages/profile.php') ?>">👤 Profil Saya</a></li>
      <?php if ((int)$_SESSION['role_id'] === 1 || (int)$_SESSION['role_id'] === 2): ?>
        <li><a href="<?= baseUrl('admin/dashboard.php') ?>">⚙️ Dashboard Sekolah</a></li>
      <?php endif; ?>
    </ul>

    <!-- Widget Trending Topic (Bab 6.5) -->
    <?php if (!empty($trendingTags)): ?>
      <div class="card" style="margin-top:16px;">
        <div class="card-header">
          <span class="card-title">🔥 Trending Topik</span>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          <?php foreach ($trendingTags as $tt): ?>
            <a href="<?= baseUrl('pages/feed.php?tag=' . urlencode($tt['tag'])) ?>" 
               style="display:flex; justify-content:space-between; align-items:center; font-size:13px; color:var(--navy);">
              <strong>#<?= e($tt['tag']) ?></strong>
              <span style="font-size:11px; color:var(--text-muted); background:var(--bg); padding:2px 8px; border-radius:10px;">
                <?= $tt['total'] ?> post
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </aside>

  <!-- Bagian Utama Feed Sesuai Mockup Halaman 1 -->
  <section>

    <!-- Form Buat Postingan Baru -->
    <div class="card">
      <form method="POST" action="<?= baseUrl('pages/post_action.php?action=create') ?>">
        <div style="display:flex; gap:12px; align-items:flex-start;">
          <?= renderAvatar($_SESSION['nama'], $myInfo['foto'] ?? null) ?>
          <div style="flex:1;">
            <textarea name="konten" class="form-textarea" placeholder="Bagikan karya, peluang karier, prestasi, atau kegiatan alumni... (bisa pakai hashtag seperti #PPLG)" required></textarea>
            
            <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
              <select name="category_id" class="form-select" style="width:auto;padding:8px 12px;font-size:12px;">
                <option value="">Pilih Kategori (opsional)</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>"><?= e($cat['nama_kategori']) ?></option>
                <?php endforeach; ?>
              </select>

              <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 22px;">Posting</button>
            </div>
          </div>
        </div>
      </form>
    </div>

    <!-- Filter Kategori Feed Bar -->
    <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:8px; margin-bottom:14px;">
      <a href="<?= baseUrl('pages/feed.php') ?>" 
         class="filter-pill <?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">
        Semua
      </a>
      <?php foreach ($categories as $c): ?>
        <a href="<?= baseUrl('pages/feed.php?cat=' . $c['id']) ?>" 
           class="filter-pill <?= ($catFilter === (int)$c['id']) ? 'active' : '' ?>">
          <?= e($c['nama_kategori']) ?>
        </a>
      <?php endforeach; ?>
      <?php if ($tagFilter): ?>
        <a href="#" class="filter-pill active">
          #<?= e($tagFilter) ?> (Filter aktif)
        </a>
      <?php endif; ?>
    </div>

    <!-- Daftar Postingan Feed -->
    <?php if (empty($posts)): ?>
      <div class="card text-center text-muted" style="padding:40px 20px;">
        <div style="font-size:36px;margin-bottom:8px;">📰</div>
        <h3 style="font-size:15px;font-weight:700;color:var(--navy);">Belum ada postingan dalam kategori ini</h3>
        <p style="font-size:13px;margin-top:4px;">Jadilah orang pertama yang berbagi informasi!</p>
      </div>
    <?php else: ?>
      <?php foreach ($posts as $p): ?>
        <article class="post-card" id="post-<?= $p['id'] ?>">
          <div class="post-head">
            <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>">
              <?= renderAvatar($p['nama'] ?? $p['username'], $p['foto'] ?? null) ?>
            </a>
            <div style="flex:1; min-width:0;">
              <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>" class="post-author">
                <?= e($p['nama'] ?? $p['username']) ?>
              </a>
              <div class="post-meta">
                <?= e($p['profesi'] ?? $p['jurusan'] ?? 'Anggota') ?>
                · <?= timeAgo($p['created_at']) ?>
                <?php if ($p['nama_kategori']): ?>
                  <span class="post-badge"><?= e($p['nama_kategori']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Tombol Edit & Hapus Jika Pemilik Post atau Admin -->
            <?php if ((int)$p['user_id'] === $userId || (int)$_SESSION['role_id'] === 1): ?>
              <div style="display:flex; gap:4px;">
                <a href="<?= baseUrl('pages/edit_post.php?id=' . $p['id']) ?>" 
                   class="btn-outline btn-sm" 
                   style="color:var(--primary);border-color:var(--border);padding:4px 8px;" title="Edit postingan">
                  ✏️
                </a>
                <a href="<?= baseUrl('pages/post_action.php?action=delete&post_id=' . $p['id']) ?>" 
                   class="btn-outline btn-sm" 
                   onclick="return confirm('Hapus postingan ini?')"
                   style="color:#EF4444;border-color:#FCA5A5;padding:4px 8px;" title="Hapus postingan">
                  🗑️
                </a>
              </div>
            <?php endif; ?>
          </div>

          <div class="post-content">
            <?php
              // Auto format hashtag klikable
              $formatted = htmlspecialchars($p['konten'] ?? '', ENT_QUOTES, 'UTF-8');
              $formatted = preg_replace('/#([a-zA-Z0-9_]+)/', '<a href="' . baseUrl('pages/feed.php?tag=$1') . '" style="color:var(--primary);font-weight:600;">#$1</a>', $formatted);
              echo nl2br($formatted);
            ?>
          </div>

          <div class="post-actions">
            <button class="post-action <?= $p['is_liked'] ? 'liked' : '' ?>"
                    onclick="toggleLike(<?= $p['id'] ?>, this)">
              ❤️ <span class="like-count"><?= $p['total_like'] ?></span>
            </button>
            <button class="post-action" onclick="toggleCommentBox(<?= $p['id'] ?>)">
              💬 <span><?= $p['total_comment'] ?></span> Komentar
            </button>
            <button class="post-action" onclick="sharePost(<?= $p['id'] ?>, this)">
              🔗 <span class="share-count"><?= $p['total_share'] ?></span> Share
            </button>
          </div>

          <!-- Bagian Komentar Dropdown -->
          <div class="comment-section" id="comment-box-<?= $p['id'] ?>" style="display:none;">
            <?php
              $cStmt = $conn->prepare("
                  SELECT c.*, u.username, pr.nama, pr.foto 
                  FROM comments c 
                  JOIN users u ON u.id = c.user_id 
                  LEFT JOIN profiles pr ON pr.user_id = u.id 
                  WHERE c.post_id = ? 
                  ORDER BY c.created_at ASC
              ");
              $cStmt->bind_param('i', $p['id']);
              $cStmt->execute();
              $comments = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>

            <div style="margin-bottom:12px;">
              <?php if (empty($comments)): ?>
                <p style="font-size:12px; color:var(--text-muted); font-style:italic;">Belum ada komentar. Tulis komentar pertama!</p>
              <?php else: ?>
                <?php foreach ($comments as $cm): ?>
                  <div class="comment-item">
                    <?= renderAvatar($cm['nama'] ?? $cm['username'], $cm['foto'] ?? null, 'avatar-sm') ?>
                    <div class="comment-bubble">
                      <div class="comment-user"><?= e($cm['nama'] ?? $cm['username']) ?></div>
                      <div><?= e($cm['isi']) ?></div>
                      <div style="font-size:10px; color:var(--text-muted); margin-top:3px;"><?= timeAgo($cm['created_at']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <form method="POST" action="<?= baseUrl('pages/post_action.php?action=comment') ?>" style="display:flex; gap:8px;">
              <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
              <input type="text" name="isi" class="form-input" style="padding:8px 12px; font-size:13px;" placeholder="Tulis komentar..." required>
              <button type="submit" class="btn btn-primary btn-sm">Kirim</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>

  </section>

  <!-- Sidebar Kanan: Mesin Rekomendasi Alumni Sesuai Mockup Halaman 1 -->
  <aside class="sidebar sidebar-right">
    <div class="card">
      <div class="card-header">
        <span class="card-title">⭐ Rekomendasi Alumni</span>
      </div>
      <p style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
        Disarankan berdasarkan kesamaan jurusan, minat, dan keaktifan.
      </p>

      <?php if (empty($rekomendasi)): ?>
        <p class="text-muted" style="font-size:12px;">Tidak ada rekomendasi baru.</p>
      <?php else: ?>
        <?php foreach ($rekomendasi as $r): ?>
          <div style="display:flex; gap:10px; align-items:center; padding:10px 0; border-bottom:1px solid var(--border);">
            <a href="<?= baseUrl('pages/profile.php?id=' . $r['id']) ?>">
              <?= renderAvatar($r['nama'] ?? $r['username'], $r['foto'] ?? null, 'avatar-sm') ?>
            </a>
            <div style="flex:1;min-width:0;">
              <a href="<?= baseUrl('pages/profile.php?id=' . $r['id']) ?>" 
                 style="font-weight:700;font-size:13px;color:var(--navy);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= e($r['nama'] ?? $r['username']) ?>
              </a>
              <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= e($r['profesi'] ?? $r['jurusan'] ?? 'Alumni') ?>
              </div>
            </div>

            <a href="<?= baseUrl('pages/follow_action.php?user_id=' . $r['id']) ?>" 
               class="btn <?= $r['is_followed'] ? 'btn-outline' : 'btn-primary' ?> btn-sm" 
               style="padding:4px 10px;font-size:11px;">
              <?= $r['is_followed'] ? 'Following' : 'Follow' ?>
            </a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div style="margin-top:12px; text-align:center;">
        <a href="<?= baseUrl('pages/directory.php') ?>" style="font-size:12px;color:var(--primary);font-weight:700;">
          Lihat Semua Alumni →
        </a>
      </div>
    </div>
  </aside>

</div>

<script>
function toggleLike(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=like&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        btn.classList.toggle('liked', d.liked);
        btn.querySelector('.like-count').textContent = d.total;
      }
    });
}

function sharePost(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=share&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => { 
      if (d.success) {
        btn.querySelector('.share-count').textContent = d.total;
        alert('Tautan postingan berhasil dibagikan!');
      } 
    });
}

function toggleCommentBox(id) {
  const box = document.getElementById('comment-box-' + id);
  if (box) {
    box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>