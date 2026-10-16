<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Ambil info user aktif (jurusan, angkatan, foto)
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

// Query Postingan TikTok Web Style: diurutkan berdasarkan engagement score
$sql = "
    SELECT 
        p.id, p.konten, p.media, p.created_at,
        u.id AS user_id, u.username,
        pr.nama, pr.foto,
        ap.jurusan, ap.profesi,
        c.nama_kategori,
        (SELECT COUNT(*) FROM likes     WHERE post_id = p.id) AS total_like,
        (SELECT COUNT(*) FROM comments  WHERE post_id = p.id) AS total_comment,
        (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id) AS total_bookmark,
        (SELECT COUNT(*) FROM shares    WHERE post_id = p.id) AS total_share,
        (SELECT COUNT(*) FROM likes     WHERE post_id = p.id AND user_id = ?) AS is_liked,
        (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id AND user_id = ?) AS is_bookmarked,
        (SELECT COUNT(*) FROM followers WHERE follower_id = ? AND following_id = u.id) AS is_followed
    FROM posts p
    JOIN users u                 ON u.id = p.user_id
    LEFT JOIN profiles pr        ON pr.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    LEFT JOIN categories c       ON c.id = p.category_id
";

$whereClauses = [];
$params = [$userId, $userId, $userId];
$types  = 'iii';

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

// Algoritma Engagement Ranking (Proposal Bab 6.2)
$sql .= "
    ORDER BY 
        ((SELECT COUNT(*) FROM likes     WHERE post_id = p.id) * 1 +
         (SELECT COUNT(*) FROM comments  WHERE post_id = p.id) * 3 +
         (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id) * 3 +
         (SELECT COUNT(*) FROM shares    WHERE post_id = p.id) * 4) DESC,
        p.created_at DESC
    LIMIT 40
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Ambil semua teman yang ditandai untuk seluruh post
$mentionsRes = $conn->query("
    SELECT pm.post_id, u.id AS user_id, u.username, p.nama
    FROM post_mentions pm
    JOIN users u ON u.id = pm.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
");
$postMentions = [];
while ($m = $mentionsRes->fetch_assoc()) {
    $postMentions[$m['post_id']][] = $m;
}

// ============================================================
// ALGORITMA REKOMENDASI ALUMNI (Proposal Bab 17.2 & Bab 6.3)
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
    $interest = 30;
    if (!empty($alumni['jurusan']) && strcasecmp($alumni['jurusan'], $myJurusan) === 0) $interest += 60;
    $interest = min(100, $interest);

    $interaction = min(100, (int)$alumni['interaction_count'] * 12);

    $similarity = 30;
    if (!empty($alumni['angkatan']) && abs((int)$alumni['angkatan'] - (int)$myAngkatan) <= 2) $similarity += 60;
    $similarity = min(100, $similarity);

    $recTime = strtotime($alumni['created_at']);
    $diffDays = max(0, (time() - $recTime) / 86400);
    $recency = max(0, 100 - ($diffDays * 1.5));

    return round(($interest * 0.35) + ($interaction * 0.30) + ($similarity * 0.20) + ($recency * 0.15), 1);
}

foreach ($rawAlumni as &$ra) {
    $ra['rec_score'] = hitungRecommendationScore($ra, $myJurusan, $myAngkatan);
}
unset($ra);

usort($rawAlumni, function($a, $b) {
    if ($a['is_followed'] != $b['is_followed']) return $a['is_followed'] <=> $b['is_followed'];
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
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// Ambil kategori untuk filter bar
$categories = $conn->query("SELECT * FROM categories ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Postingan';
include __DIR__ . '/../includes/header.php';
?>

<div class="feed-layout">

  <!-- Sidebar Kiri: Navigasi Desktop Sesuai Wireframe & Mockup -->
  <aside class="sidebar sidebar-left">
    <ul class="sidebar-menu">
      <li><a href="<?= baseUrl('pages/feed.php') ?>" class="<?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">🎬 Postingan</a></li>
      <li><a href="<?= baseUrl('pages/create_post.php') ?>" style="color:var(--primary); font-weight:700;">➕ Buat Postingan</a></li>
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

  <!-- Bagian Utama Postingan TikTok Web Desktop Style -->
  <section style="min-width:0;">

    <!-- BAR PINTAS BUAT POSTINGAN -->
    <div class="feed-create-bar">
      <a href="<?= baseUrl('pages/profile.php') ?>" title="Profil Anda">
        <?= renderAvatar($_SESSION['nama'], $myInfo['foto'] ?? null, 'avatar-sm') ?>
      </a>
      <a href="<?= baseUrl('pages/create_post.php') ?>" class="create-bar-trigger">
        <span>✨ Apa karya atau ceritamu hari ini, <strong><?= e($_SESSION['nama']) ?></strong>? (Ketik @ untuk tag teman...)</span>
      </a>
      <a href="<?= baseUrl('pages/create_post.php') ?>" class="btn-create-post">
        <span>📹 Posting</span>
      </a>
    </div>

    <!-- Filter Kategori Bar -->
    <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:10px; margin-bottom:20px;">
      <a href="<?= baseUrl('pages/feed.php') ?>" 
         class="filter-pill <?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">
        ✨ Semua Postingan
      </a>
      <?php foreach ($categories as $c): ?>
        <a href="<?= baseUrl('pages/feed.php?cat=' . $c['id']) ?>" 
           class="filter-pill <?= ($catFilter === (int)$c['id']) ? 'active' : '' ?>">
          <?= e($c['nama_kategori']) ?>
        </a>
      <?php endforeach; ?>
      <?php if ($tagFilter): ?>
        <a href="<?= baseUrl('pages/feed.php') ?>" class="filter-pill active" title="Klik untuk hapus filter">
          #<?= e($tagFilter) ?> ✕
        </a>
      <?php endif; ?>
    </div>

    <!-- DAFTAR POSTINGAN (TIKTOK WEB DESKTOP LAYOUT) -->
    <div class="tiktok-feed-container" id="tiktokFeedContainer">
      <?php if (empty($posts)): ?>
        <div class="card text-center text-muted" style="padding:40px 20px;">
          <div style="font-size:40px; margin-bottom:10px;">🎬</div>
          <h3 style="font-size:16px; font-weight:700; color:var(--navy);">Belum ada postingan</h3>
          <p style="font-size:13px; margin-top:4px; margin-bottom:16px;">Jadilah orang pertama yang mengunggah video atau foto!</p>
          <a href="<?= baseUrl('pages/create_post.php') ?>" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
            ➕ Buat Postingan Sekarang
          </a>
        </div>
      <?php else: ?>
        <?php foreach ($posts as $idx => $p): ?>
          <?php
            // Cek tipe media
            $mediaUrl = $p['media'] ? baseUrl($p['media']) : null;
            $isMediaExists = false;
            $isVideo = false;
            if ($p['media']) {
                $realMedia = __DIR__ . '/../' . ltrim($p['media'], '/\\');
                if (file_exists($realMedia)) {
                    $isMediaExists = true;
                    $ext = strtolower(pathinfo($realMedia, PATHINFO_EXTENSION));
                    if (in_array($ext, ['mp4', 'webm', 'mov', 'ogg', 'm4v'])) {
                        $isVideo = true;
                    }
                }
            }

            // Teman yang ditag
            $mentions = $postMentions[$p['id']] ?? [];

            // Format caption dengan hashtag & mentions
            $caption = htmlspecialchars($p['konten'] ?? '', ENT_QUOTES, 'UTF-8');
            $caption = preg_replace('/#([a-zA-Z0-9_]+)/', '<a href="' . baseUrl('pages/feed.php?tag=$1') . '">#$1</a>', $caption);
            $caption = preg_replace('/@([a-zA-Z0-9_]+)/', '<a href="' . baseUrl('pages/directory.php?q=$1') . '">@$1</a>', $caption);
          ?>

          <!-- ITEM STAGE POSTINGAN (Video Card + Right Side Action Stack) -->
          <div class="tiktok-stage-item" id="post-<?= $p['id'] ?>" data-post-id="<?= $p['id'] ?>" data-index="<?= $idx ?>">

            <!-- KARTU VIDEO UTAMA -->
            <div class="tiktok-video-card">

              <!-- 1. Media Layer (Double Tap to Like) -->
              <div class="tiktok-media-box" data-post-id="<?= $p['id'] ?>">
                <?php if ($isMediaExists && $isVideo): ?>
                  <video src="<?= $mediaUrl ?>" loop playsinline preload="metadata" data-post-video="true"></video>
                  <!-- Play/Pause Indicator -->
                  <div class="tiktok-play-overlay">▶</div>
                  <!-- Sound Toggle Button -->
                  <button type="button" class="tiktok-sound-btn" onclick="toggleMuteVideo(this, event)" title="Suara Video">🔇</button>
                <?php elseif ($isMediaExists && !$isVideo): ?>
                  <img src="<?= $mediaUrl ?>" alt="Post Image" loading="lazy">
                <?php else: ?>
                  <!-- Backdrop Text Quote Poster -->
                  <div class="tiktok-text-backdrop">
                    <div class="text-quote">
                      “<?= nl2br($caption) ?>”
                    </div>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Gradient Overlay -->
              <div class="tiktok-overlay-gradient"></div>

              <!-- Caption Box & Author Info (Bawah Video) -->
              <div class="tiktok-caption-box">
                <div class="tiktok-author-row">
                  <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>" class="tiktok-author-name">
                    @<?= e($p['username']) ?>
                  </a>
                  <span class="tiktok-author-sub">• <?= e($p['nama']) ?></span>
                  
                  <?php if ($p['nama_kategori']): ?>
                    <span class="tiktok-tag-badge">
                      🏷️ <?= e($p['nama_kategori']) ?>
                    </span>
                  <?php endif; ?>
                </div>

                <!-- Deskripsi Postingan -->
                <div class="tiktok-desc">
                  <?= nl2br($caption) ?>
                </div>

                <!-- Teman yang Ditandai -->
                <?php if (!empty($mentions)): ?>
                  <div class="tiktok-mentions-row">
                    <span style="font-size:11px; color:#FCD34D; font-weight:700;">🏷️ Bersama:</span>
                    <?php foreach ($mentions as $men): ?>
                      <a href="<?= baseUrl('pages/profile.php?id=' . $men['user_id']) ?>" class="tiktok-mention-pill">
                        @<?= e($men['username']) ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <!-- Audio Ticker TikTok -->
                <div class="tiktok-audio-ticker">
                  <span>🎵</span>
                  <span class="ticker-text">Suara Asli - ALUMNICONNECT Almamater SMK 1 &nbsp;&nbsp;&nbsp;&nbsp; 🎵 Suara Asli - ALUMNICONNECT</span>
                </div>
              </div>

              <!-- Drawer Komentar (Bisa dibuka di atas video) -->
              <div class="tiktok-comment-drawer" id="comment-drawer-<?= $p['id'] ?>" style="display:none;">
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

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.15); padding-bottom:8px;">
                  <span style="font-size:13px; font-weight:700; color:#F1F5F9;">💬 Komentar (<?= count($comments) ?>)</span>
                  <button type="button" onclick="toggleTiktokComments(<?= $p['id'] ?>)" style="background:none; border:none; color:#CBD5E1; font-size:16px; cursor:pointer;">✕</button>
                </div>

                <div class="tiktok-comment-scroll">
                  <?php if (empty($comments)): ?>
                    <p style="font-size:12px; color:#94A3B8; font-style:italic;">Belum ada komentar. Tulis komentar pertama!</p>
                  <?php else: ?>
                    <?php foreach ($comments as $cm): ?>
                      <div style="display:flex; gap:8px; align-items:flex-start;">
                        <?= renderAvatar($cm['nama'] ?? $cm['username'], $cm['foto'] ?? null, 'avatar-sm', 'width:28px;height:28px;font-size:11px;') ?>
                        <div style="background:rgba(255,255,255,0.08); padding:6px 10px; border-radius:10px; flex:1;">
                          <div style="font-weight:700; font-size:11px; color:#38BDF8;"><?= e($cm['nama'] ?? $cm['username']) ?></div>
                          <div style="font-size:12px; color:#F8FAFC;"><?= e($cm['isi']) ?></div>
                          <div style="font-size:9px; color:#94A3B8; margin-top:2px;"><?= timeAgo($cm['created_at']) ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>

                <!-- Form Komentar -->
                <form method="POST" action="<?= baseUrl('pages/post_action.php?action=comment') ?>" style="display:flex; gap:8px; margin-top:8px;">
                  <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                  <input type="text" name="isi" class="form-input" style="padding:8px 12px; font-size:12px; background:rgba(255,255,255,0.12); color:white; border-color:rgba(255,255,255,0.2);" placeholder="Tulis komentar publik..." required>
                  <button type="submit" class="btn btn-primary btn-sm" style="padding:6px 14px; font-size:12px;">Kirim</button>
                </form>
              </div>

            </div> <!-- END .tiktok-video-card -->

            <!-- KOLOM AKSI SAMPING KANAN (PERSIS TIKTOK WEB DESKTOP) -->
            <div class="tiktok-side-actions">
              
              <!-- 1. Avatar Penulis dengan Badge Merah '+' Follow -->
              <div class="tiktok-action-item tiktok-avatar-wrapper">
                <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>" title="<?= e($p['nama'] ?? $p['username']) ?>">
                  <?= renderAvatar($p['nama'] ?? $p['username'], $p['foto'] ?? null, 'avatar-sm', 'width:46px;height:46px;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.5);') ?>
                </a>
                <?php if ((int)$p['user_id'] !== $userId && empty($p['is_followed'])): ?>
                  <a href="<?= baseUrl('pages/follow_action.php?user_id=' . $p['user_id']) ?>" class="tiktok-follow-badge" title="Ikuti Pengguna">+</a>
                <?php endif; ?>
              </div>

              <!-- 2. Tombol Like (Hati) -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle <?= $p['is_liked'] ? 'liked' : '' ?>" 
                        onclick="toggleLike(<?= $p['id'] ?>, this)" title="Sukai">
                  <svg class="tiktok-svg-icon" viewBox="0 0 24 24">
                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                  </svg>
                </button>
                <span class="tiktok-action-number like-count"><?= formatCount((int)$p['total_like']) ?></span>
              </div>

              <!-- 3. Tombol Komentar -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle" onclick="toggleTiktokComments(<?= $p['id'] ?>)" title="Komentar">
                  <svg class="tiktok-svg-icon" viewBox="0 0 24 24">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                  </svg>
                </button>
                <span class="tiktok-action-number comment-count"><?= formatCount((int)$p['total_comment']) ?></span>
              </div>

              <!-- 4. Tombol Bookmark / Favorit (Simpan) -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle bookmark-btn <?= !empty($p['is_bookmarked']) ? 'bookmarked' : '' ?>" 
                        onclick="toggleBookmark(<?= $p['id'] ?>, this)" title="Favorit">
                  <svg class="tiktok-svg-icon" viewBox="0 0 24 24">
                    <path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/>
                  </svg>
                </button>
                <span class="tiktok-action-number bookmark-count"><?= formatCount((int)($p['total_bookmark'] ?? 0)) ?></span>
              </div>

              <!-- 5. Tombol Share / Bagikan -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle" onclick="sharePost(<?= $p['id'] ?>, this)" title="Bagikan">
                  <svg class="tiktok-svg-icon" viewBox="0 0 24 24">
                    <path d="M14 9V5l7 7-7 7v-4.1c-5 0-8.5 1.6-11 5.1 1-5 4-10 11-11z"/>
                  </svg>
                </button>
                <span class="tiktok-action-number share-count"><?= formatCount((int)$p['total_share']) ?></span>
              </div>

              <!-- 6. Piringan Musik Hitam Berputar (Vinyl Disc ala TikTok) -->
              <div class="tiktok-action-item">
                <div class="tiktok-music-disc" title="Musik Audio Asli">
                  <div class="disc-art">
                    <?= renderAvatar($p['nama'] ?? $p['username'], $p['foto'] ?? null, 'avatar-sm', 'width:24px;height:24px;font-size:10px;') ?>
                  </div>
                </div>
              </div>

              <!-- Tombol Edit / Hapus jika pemilik atau admin -->
              <?php if ((int)$p['user_id'] === $userId || (int)$_SESSION['role_id'] === 1): ?>
                <div class="tiktok-action-item">
                  <a href="<?= baseUrl('pages/edit_post.php?id=' . $p['id']) ?>" class="tiktok-action-circle" style="font-size:14px;" title="Edit Postingan">
                    ✏️
                  </a>
                </div>
                <div class="tiktok-action-item">
                  <a href="<?= baseUrl('pages/post_action.php?action=delete&post_id=' . $p['id']) ?>" 
                     class="tiktok-action-circle" style="font-size:14px; background:rgba(239,68,68,0.25); color:#EF4444;" 
                     onclick="return confirm('Hapus postingan ini?')" title="Hapus Postingan">
                    🗑️
                  </a>
                </div>
              <?php endif; ?>

            </div> <!-- END .tiktok-side-actions -->

          </div> <!-- END .tiktok-stage-item -->
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

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

<!-- TOMBOL NAVIGASI UP/DOWN KHAS TIKTOK WEB DESKTOP -->
<div class="tiktok-stage-nav">
  <button class="tiktok-nav-arrow" onclick="scrollToPrevPost()" title="Postingan Sebelumnya (Panah Atas / k)">⌃</button>
  <button class="tiktok-nav-arrow" onclick="scrollToNextPost()" title="Postingan Berikutnya (Panah Bawah / j)">⌄</button>
</div>

<!-- FLOATING ACTION BUTTON (FAB) UNTUK BUAT POSTINGAN CEPAT -->
<a href="<?= baseUrl('pages/create_post.php') ?>" class="tiktok-fab" title="Buat Postingan Baru">
  <span class="fab-plus">➕</span>
  <span class="fab-text">Posting</span>
</a>

<!-- TOAST NOTIFICATION CONTAINER -->
<div id="tiktokToast" class="tiktok-toast" style="display:none;"></div>

<script>
// ============================================================
// 1. LIKE AJAX & HEART POP ANIMATION
// ============================================================
function toggleLike(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=like&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        if (btn) {
          btn.classList.toggle('liked', d.liked);
          const countSpan = btn.parentElement.querySelector('.like-count');
          if (countSpan) countSpan.textContent = formatJsCount(d.total);
        }
      }
    });
}

// Double tap / Double click to like on media box
document.querySelectorAll('.tiktok-media-box').forEach(box => {
  let lastTap = 0;
  
  function triggerHeartPop(e) {
    const postId = box.getAttribute('data-post-id');
    const stage = document.getElementById('post-' + postId);
    if (!stage) return;

    // Pop animated heart
    const heart = document.createElement('div');
    heart.className = 'heart-pop';
    heart.innerHTML = '❤️';
    box.appendChild(heart);

    setTimeout(() => {
      heart.remove();
    }, 900);

    // Call like if not already liked
    const likeBtn = stage.querySelector('.tiktok-action-circle[title*="Sukai"]');
    if (likeBtn && !likeBtn.classList.contains('liked')) {
      toggleLike(postId, likeBtn);
    }
  }

  box.addEventListener('dblclick', function(e) {
    triggerHeartPop(e);
  });

  box.addEventListener('touchend', function(e) {
    const curTime = new Date().getTime();
    const tapLen = curTime - lastTap;
    if (tapLen < 300 && tapLen > 0) {
      triggerHeartPop(e);
      e.preventDefault();
    }
    lastTap = curTime;
  });
});

// ============================================================
// 2. TOGGLE BOOKMARK / FAVORIT (AJAX)
// ============================================================
function toggleBookmark(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=bookmark&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        btn.classList.toggle('bookmarked', d.bookmarked);
        const countSpan = btn.parentElement.querySelector('.bookmark-count');
        if (countSpan) countSpan.textContent = formatJsCount(d.total);
        showToast(d.bookmarked ? '⭐ Postingan disimpan ke favorit!' : 'Dihapus dari favorit.');
      }
    });
}

// ============================================================
// 3. VIDEO PLAY / PAUSE ON TAP & SMART SCROLL OBSERVER
// ============================================================
document.querySelectorAll('.tiktok-media-box video').forEach(vid => {
  const box = vid.closest('.tiktok-media-box');
  const indicator = box.querySelector('.tiktok-play-overlay');

  vid.addEventListener('click', function(e) {
    if (e.target.classList.contains('tiktok-sound-btn')) return;
    if (vid.paused) {
      vid.play();
      if (indicator) {
        indicator.textContent = '▶';
        indicator.classList.add('flash');
        setTimeout(() => indicator.classList.remove('flash'), 500);
      }
    } else {
      vid.pause();
      if (indicator) {
        indicator.textContent = '⏸';
        indicator.classList.add('flash');
        setTimeout(() => indicator.classList.remove('flash'), 500);
      }
    }
  });
});

function toggleMuteVideo(btn, e) {
  e.stopPropagation();
  const box = btn.closest('.tiktok-media-box');
  const vid = box.querySelector('video');
  if (vid) {
    vid.muted = !vid.muted;
    btn.textContent = vid.muted ? '🔇' : '🔊';
  }
}

// Intersection Observer: Autoplay video in center, pause when out of view
if ('IntersectionObserver' in window) {
  const videoObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      const vid = entry.target.querySelector('video');
      if (vid) {
        if (entry.isIntersecting && entry.intersectionRatio >= 0.55) {
          vid.muted = true; // muted for browser policy
          const playPromise = vid.play();
          if (playPromise !== undefined) {
            playPromise.catch(() => {});
          }
        } else {
          vid.pause();
        }
      }
    });
  }, { threshold: [0.55] });

  document.querySelectorAll('.tiktok-stage-item').forEach(item => {
    videoObserver.observe(item);
  });
}

// ============================================================
// 4. SHARE LINK & TOAST ALERT
// ============================================================
function showToast(msg) {
  const toast = document.getElementById('tiktokToast');
  toast.textContent = msg;
  toast.style.display = 'block';
  toast.classList.add('show');
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.style.display = 'none', 300);
  }, 2800);
}

function sharePost(id, btn) {
  const postUrl = window.location.origin + window.location.pathname + '#post-' + id;
  
  if (navigator.clipboard) {
    navigator.clipboard.writeText(postUrl).then(() => {
      showToast('✨ Tautan postingan berhasil disalin ke clipboard!');
    }).catch(() => {
      showToast('🔗 ' + postUrl);
    });
  } else {
    showToast('✨ Tautan postingan siap dibagikan!');
  }

  fetch('<?= baseUrl('pages/post_action.php?action=share&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => { 
      if (d.success) {
        const countSpan = btn.parentElement.querySelector('.share-count');
        if (countSpan) countSpan.textContent = formatJsCount(d.total);
      } 
    });
}

function formatJsCount(n) {
  n = parseInt(n) || 0;
  if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
  if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
  return n.toString();
}

// ============================================================
// 5. COMMENT DRAWER TOGGLE
// ============================================================
function toggleTiktokComments(id) {
  const drawer = document.getElementById('comment-drawer-' + id);
  if (drawer) {
    const isHidden = (drawer.style.display === 'none' || drawer.style.display === '');
    drawer.style.display = isHidden ? 'flex' : 'none';
    if (isHidden) {
      const inp = drawer.querySelector('input[name="isi"]');
      if (inp) inp.focus();
    }
  }
}

// ============================================================
// 6. UP/DOWN STAGE ARROW CONTROLS (TIKTOK DESKTOP NAVIGATION)
// ============================================================
function getCurrentPostIndex() {
  const items = Array.from(document.querySelectorAll('.tiktok-stage-item'));
  const viewportMiddle = window.scrollY + (window.innerHeight / 2);
  let closestIdx = 0;
  let minDiff = Infinity;

  items.forEach((it, idx) => {
    const rect = it.getBoundingClientRect();
    const itemMiddle = window.scrollY + rect.top + (rect.height / 2);
    const diff = Math.abs(viewportMiddle - itemMiddle);
    if (diff < minDiff) {
      minDiff = diff;
      closestIdx = idx;
    }
  });
  return closestIdx;
}

function scrollToPostIndex(idx) {
  const items = document.querySelectorAll('.tiktok-stage-item');
  if (idx >= 0 && idx < items.length) {
    items[idx].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function scrollToNextPost() {
  const cur = getCurrentPostIndex();
  scrollToPostIndex(cur + 1);
}

function scrollToPrevPost() {
  const cur = getCurrentPostIndex();
  scrollToPostIndex(cur - 1);
}

// Keyboard arrow navigation (ArrowUp, ArrowDown, j, k)
window.addEventListener('keydown', function(e) {
  // If user is typing in an input or textarea, don't hijack keys
  if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;

  if (e.key === 'ArrowDown' || e.key === 'j') {
    e.preventDefault();
    scrollToNextPost();
  } else if (e.key === 'ArrowUp' || e.key === 'k') {
    e.preventDefault();
    scrollToPrevPost();
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>