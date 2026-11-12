<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Ambil info user aktif (jurusan, angkatan, foto)
$uStmt = $conn->prepare("
    SELECT pr.foto, pr.nama, ap.jurusan, ap.angkatan 
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

// Hitung notifikasi belum dibaca & pesan masuk
$unreadNotifCount = 0;
$nCheck = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
if ($nCheck && $nCheck->num_rows > 0) {
    $nr = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE user_id = $userId AND is_read = 0");
    if ($nr) $unreadNotifCount = (int)$nr->fetch_assoc()['total'];
}

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

// Ambil semua komentar untuk seluruh post yang tampil
$allPostIds = array_column($posts, 'id');
$commentsByPost = [];
if (!empty($allPostIds)) {
    $inClause = implode(',', array_map('intval', $allPostIds));
    $cQuery = $conn->query("
        SELECT c.*, u.username, pr.nama, pr.foto 
        FROM comments c 
        JOIN users u ON u.id = c.user_id 
        LEFT JOIN profiles pr ON pr.user_id = u.id 
        WHERE c.post_id IN ($inClause)
        ORDER BY c.created_at ASC
    ");
    if ($cQuery) {
        while ($cRow = $cQuery->fetch_assoc()) {
            $commentsByPost[$cRow['post_id']][] = $cRow;
        }
    }
}

// Algoritma Rekomendasi Alumni (Proposal Bab 17.2 & Bab 6.3)
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

  <!-- ============================================================
       SIDEBAR KIRI ("HODBAR") SESUAI MOCKUP GAMBAR USER
       ============================================================ -->
  <aside class="sidebar sidebar-left">
    
    <!-- Pencarian Cepat -->
    <div style="margin-bottom:14px;">
      <form action="<?= baseUrl('pages/directory.php') ?>" method="GET" style="display:flex; align-items:center; background:white; border:1px solid var(--border); border-radius:var(--radius-pill); padding:6px 14px; box-shadow:var(--shadow-sm);">
        <span style="margin-right:6px; font-size:13px; color:var(--text-muted);">🔍</span>
        <input type="text" name="q" placeholder="Cari alumni / postingan..." style="border:none; outline:none; background:none; font-size:12.5px; width:100%; color:var(--navy);">
      </form>
    </div>

    <!-- Menu Utama -->
    <ul class="sidebar-menu">
      <li>
        <a href="<?= baseUrl('pages/feed.php') ?>" class="<?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">
          <span>🏠</span> Postingan
        </a>
      </li>
      <li>
        <a href="<?= baseUrl('pages/directory.php') ?>">
          <span>🔍</span> Direktori Alumni
        </a>
      </li>
      <li>
        <a href="<?= baseUrl('pages/messages.php') ?>" style="display:flex; justify-content:space-between; align-items:center;">
          <div style="display:flex; align-items:center; gap:12px;">
            <span>💬</span> Pesan & Chat
          </div>
        </a>
      </li>
      <li>
        <a href="<?= baseUrl('pages/notifications.php') ?>" style="display:flex; justify-content:space-between; align-items:center;">
          <div style="display:flex; align-items:center; gap:12px;">
            <span>🔔</span> Notifikasi
          </div>
          <?php if ($unreadNotifCount > 0): ?>
            <span style="background:#EF4444; color:white; font-size:10px; font-weight:700; border-radius:10px; padding:2px 7px;">
              <?= $unreadNotifCount ?>
            </span>
          <?php endif; ?>
        </a>
      </li>
      <li>
        <a href="<?= baseUrl('pages/create_post.php') ?>" style="color:var(--primary); font-weight:700;">
          <span>➕</span> Unggah Video / Foto
        </a>
      </li>
      <li>
        <a href="<?= baseUrl('pages/profile.php') ?>">
          <span>👤</span> Profil Saya
        </a>
      </li>
      <?php if ((int)$_SESSION['role_id'] === 1 || (int)$_SESSION['role_id'] === 2): ?>
        <li>
          <a href="<?= baseUrl('admin/dashboard.php') ?>" style="color:var(--orange);">
            <span>⚙️</span> Dashboard Sekolah
          </a>
        </li>
      <?php endif; ?>
      <li>
        <a href="<?= baseUrl('auth/logout.php') ?>" onclick="return confirmLogout()" style="color:#EF4444;">
          <span>🚪</span> Keluar
        </a>
      </li>
    </ul>

    <!-- Profil Mini Pengguna Aktif -->
    <div class="card" style="margin-top:14px; padding:12px 14px; display:flex; align-items:center; gap:10px;">
      <a href="<?= baseUrl('pages/profile.php') ?>">
        <?= renderAvatar($_SESSION['nama'], $myInfo['foto'] ?? null, 'avatar-sm') ?>
      </a>
      <div style="min-width:0; flex:1;">
        <a href="<?= baseUrl('pages/profile.php') ?>" style="font-size:13px; font-weight:700; color:var(--navy); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
          <?= e($_SESSION['nama']) ?>
        </a>
        <div style="font-size:11px; color:var(--text-muted);">@<?= e($_SESSION['username'] ?? 'saya') ?></div>
      </div>
    </div>

    <!-- Widget Trending Topic (Bab 6.5) -->
    <?php if (!empty($trendingTags)): ?>
      <div class="card" style="margin-top:14px; padding:14px;">
        <div style="font-size:13px; font-weight:700; color:var(--navy); margin-bottom:10px; display:flex; align-items:center; gap:6px;">
          <span>🔥</span> Trending Topik
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          <?php foreach ($trendingTags as $tt): ?>
            <a href="<?= baseUrl('pages/feed.php?tag=' . urlencode($tt['tag'])) ?>" 
               style="display:flex; justify-content:space-between; align-items:center; font-size:12.5px; color:var(--navy);">
              <strong>#<?= e($tt['tag']) ?></strong>
              <span style="font-size:11px; color:var(--text-muted); background:var(--bg); padding:1px 7px; border-radius:10px;">
                <?= $tt['total'] ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Rekomendasi Akun Alumni (Suggested Accounts) -->
    <?php if (!empty($rekomendasi)): ?>
      <div class="card" style="margin-top:14px; padding:14px;">
        <div style="font-size:13px; font-weight:700; color:var(--navy); margin-bottom:10px; display:flex; align-items:center; gap:6px;">
          <span>⭐</span> Akun yang Disarankan
        </div>
        <div style="display:flex; flex-direction:column; gap:10px;">
          <?php foreach ($rekomendasi as $r): ?>
            <div style="display:flex; gap:8px; align-items:center; justify-content:space-between;">
              <a href="<?= baseUrl('pages/profile.php?id=' . $r['id']) ?>" style="display:flex; align-items:center; gap:8px; min-width:0; text-decoration:none;">
                <?= renderAvatar($r['nama'] ?? $r['username'], $r['foto'] ?? null, 'avatar-sm', 'width:30px;height:30px;font-size:11px;') ?>
                <div style="min-width:0;">
                  <div style="font-size:12px; font-weight:700; color:var(--navy); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px;">
                    <?= e($r['nama'] ?? $r['username']) ?>
                  </div>
                  <div style="font-size:10.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px;">
                    <?= e($r['profesi'] ?? $r['jurusan'] ?? 'Alumni') ?>
                  </div>
                </div>
              </a>
              <a href="<?= baseUrl('pages/follow_action.php?user_id=' . $r['id']) ?>" 
                 class="btn <?= $r['is_followed'] ? 'btn-outline' : 'btn-primary' ?> btn-sm" 
                 style="padding:2px 8px; font-size:10px; border-radius:12px;">
                <?= $r['is_followed'] ? 'Followed' : 'Follow' ?>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </aside>

  <!-- ============================================================
       BAGIAN UTAMA FEED: STAGE VIDEO TIKTOK (CENTER) & PANEL KOMEN (RIGHT)
       ============================================================ -->
  <section style="flex:1; min-width:0; max-width:1120px;">

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

    <!-- CONTAINER DAFTAR POSTINGAN TIKTOK -->
    <div class="tiktok-feed-container" id="tiktokFeedContainer">
      <?php if (empty($posts)): ?>
        <div class="card text-center text-muted" style="padding:48px 20px;">
          <div style="font-size:48px; margin-bottom:12px;">🎬</div>
          <h3 style="font-size:17px; font-weight:700; color:var(--navy);">Belum ada postingan</h3>
          <p style="font-size:13px; margin-top:4px; margin-bottom:18px;">Jadilah orang pertama yang mengunggah video atau foto!</p>
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

            // Komentar untuk post ini
            $postComments = $commentsByPost[$p['id']] ?? [];

            // Format caption dengan hashtag & mentions
            $caption = htmlspecialchars($p['konten'] ?? '', ENT_QUOTES, 'UTF-8');
            $caption = preg_replace('/#([a-zA-Z0-9_]+)/', '<a href="' . baseUrl('pages/feed.php?tag=$1') . '">#$1</a>', $caption);
            $caption = preg_replace('/@([a-zA-Z0-9_]+)/', '<a href="' . baseUrl('pages/directory.php?q=$1') . '">@$1</a>', $caption);
          ?>

          <!-- ITEM STAGE TIKTOK (Video Center + Action Stack + Comment Panel Right) -->
          <div class="tiktok-stage-item" id="post-<?= $p['id'] ?>" data-post-id="<?= $p['id'] ?>" data-index="<?= $idx ?>">

            <!-- 1. VIDEO / MEDIA STAGE (CENTER) -->
            <div class="tiktok-video-stage" id="stage-<?= $p['id'] ?>">
              
              <!-- Layer Media (Video atau Foto) -->
              <div class="tiktok-media-box" data-post-id="<?= $p['id'] ?>">
                <?php if ($isMediaExists && $isVideo): ?>
                  <video src="<?= $mediaUrl ?>" 
                         loop 
                         playsinline 
                         preload="metadata" 
                         id="video-player-<?= $p['id'] ?>"
                         onloadedmetadata="checkVideoAspect(this, <?= $p['id'] ?>)"
                         ontimeupdate="updateProgress(<?= $p['id'] ?>)"></video>
                  <div class="tiktok-play-overlay">▶</div>
                <?php elseif ($isMediaExists && !$isVideo): ?>
                  <img src="<?= $mediaUrl ?>" alt="Post Image" loading="lazy" onload="checkImageAspect(this, <?= $p['id'] ?>)">
                <?php else: ?>
                  <!-- Backdrop Text Quote Poster -->
                  <div class="tiktok-text-backdrop">
                    <div class="text-quote">
                      “<?= nl2br($caption) ?>”
                    </div>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Gradient Shadow Overlay -->
              <div class="tiktok-overlay-gradient"></div>

              <!-- BOTTOM OVERLAY: AUTHOR + DESKRIPSI & TAG + CONTROLS (DI BAWAH VIDEO) -->
              <div class="tiktok-bottom-overlay">

                <!-- Baris Penulis & Kategori -->
                <div class="tiktok-author-row">
                  <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>">
                    <?= renderAvatar($p['nama'] ?? $p['username'], $p['foto'] ?? null, 'avatar-sm', 'width:36px;height:36px;border:2px solid white;') ?>
                  </a>
                  <div class="tiktok-author-names">
                    <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>" class="tiktok-author-name">
                      <?= e($p['nama'] ?? $p['username']) ?>
                    </a>
                    <span class="tiktok-author-handle">@<?= e($p['username']) ?></span>
                  </div>
                  <?php if ((int)$p['user_id'] !== $userId && empty($p['is_followed'])): ?>
                    <a href="<?= baseUrl('pages/follow_action.php?user_id=' . $p['user_id']) ?>" class="btn-tiktok-follow" title="Ikuti Pengguna">
                      Ikuti
                    </a>
                  <?php endif; ?>
                  <?php if ($p['nama_kategori']): ?>
                    <span class="tiktok-cat-pill">🏷️ <?= e($p['nama_kategori']) ?></span>
                  <?php endif; ?>
                </div>

                <!-- DESKRIPSI & TAG GABUNGAN DENGAN TOGGLE (LIHAT LEBIH BANYAK) -->
                <div class="tiktok-caption-block" id="caption-block-<?= $p['id'] ?>">
                  <div class="tiktok-caption-content">
                    <span class="caption-text"><?= nl2br($caption) ?></span>
                    <?php if (!empty($mentions)): ?>
                      <span style="margin-left:4px;">
                        — bersama 
                        <?php foreach ($mentions as $men): ?>
                          <a href="<?= baseUrl('pages/profile.php?id=' . $men['user_id']) ?>" class="caption-mention-tag">
                            @<?= e($men['username']) ?>
                          </a>
                        <?php endforeach; ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <button type="button" class="btn-caption-more" onclick="toggleCaptionExpand(<?= $p['id'] ?>)">
                    lihat lebih banyak
                  </button>
                </div>

                <!-- Video Scrubber & Playback Controls -->
                <?php if ($isMediaExists && $isVideo): ?>
                  <div class="tiktok-player-bar">
                    <div class="player-progress-bar" onclick="seekVideo(event, <?= $p['id'] ?>)">
                      <div class="player-progress-fill" id="p-fill-<?= $p['id'] ?>"></div>
                    </div>
                    <div class="player-ctrls-row">
                      <button type="button" class="ctrl-btn" onclick="togglePlayPause(<?= $p['id'] ?>)" id="btn-play-<?= $p['id'] ?>" title="Putar/Jeda">▶</button>
                      <span class="ctrl-time" id="ctrl-time-<?= $p['id'] ?>">00:00 / 00:00</span>
                      <button type="button" class="ctrl-btn" onclick="toggleSound(<?= $p['id'] ?>)" id="btn-sound-<?= $p['id'] ?>" style="margin-left:auto;" title="Suara">🔊</button>
                      <button type="button" class="ctrl-btn" onclick="toggleFullscreen(<?= $p['id'] ?>)" title="Layar Penuh">⛶</button>
                    </div>
                  </div>
                <?php endif; ?>

              </div> <!-- END .tiktok-bottom-overlay -->

            </div> <!-- END .tiktok-video-stage -->

            <!-- 2. TOMBOL AKSI SAMPING KANAN (LIKE, KOMEN, BOOKMARK, SHARE) -->
            <div class="tiktok-side-actions">

              <!-- Tombol Like (Hati) -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle <?= $p['is_liked'] ? 'liked' : '' ?>" 
                        onclick="toggleLike(<?= $p['id'] ?>, this)" title="Sukai">
                  <span class="action-icon">❤️</span>
                </button>
                <span class="tiktok-action-number like-count"><?= formatCount((int)$p['total_like']) ?></span>
              </div>

              <!-- Tombol Komentar (Membuka Panel Komentar Kanan ala Mockup Gambar) -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle" onclick="toggleCommentPanel(<?= $p['id'] ?>)" title="Komentar">
                  <span class="action-icon">💬</span>
                </button>
                <span class="tiktok-action-number comment-count-btn-<?= $p['id'] ?>"><?= formatCount((int)$p['total_comment']) ?></span>
              </div>

              <!-- Tombol Bookmark / Favorit (Simpan) -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle bookmark-btn <?= !empty($p['is_bookmarked']) ? 'bookmarked' : '' ?>" 
                        onclick="toggleBookmark(<?= $p['id'] ?>, this)" title="Favorit">
                  <span class="action-icon">🔖</span>
                </button>
                <span class="tiktok-action-number bookmark-count"><?= formatCount((int)($p['total_bookmark'] ?? 0)) ?></span>
              </div>

              <!-- Tombol Share / Bagikan -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-circle" onclick="sharePost(<?= $p['id'] ?>, this)" title="Bagikan">
                  <span class="action-icon">↗️</span>
                </button>
                <span class="tiktok-action-number share-count"><?= formatCount((int)$p['total_share']) ?></span>
              </div>

              <!-- Piringan Musik Berputar (Vinyl Disc) -->
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
                  <a href="<?= baseUrl('pages/edit_post.php?id=' . $p['id']) ?>" class="tiktok-action-circle" style="font-size:15px;" title="Edit Postingan">
                    ✏️
                  </a>
                </div>
                <div class="tiktok-action-item">
                  <a href="<?= baseUrl('pages/post_action.php?action=delete&post_id=' . $p['id']) ?>" 
                     class="tiktok-action-circle" style="font-size:15px; background:#FEE2E2; color:#EF4444; border-color:#EF4444;" 
                     onclick="return confirm('Hapus postingan ini?')" title="Hapus Postingan">
                    🗑️
                  </a>
                </div>
              <?php endif; ?>

            </div> <!-- END .tiktok-side-actions -->

            <!-- 3. PANEL KOMENTAR KANAN (DEDICATED PANEL SESUAI MOCKUP GAMBAR USER!) -->
            <div class="tiktok-comment-panel" id="comment-panel-<?= $p['id'] ?>">
              
              <!-- Header Panel Komentar -->
              <div class="comment-panel-header">
                <span class="comment-panel-title">
                  Komentar <strong id="comment-panel-count-<?= $p['id'] ?>"><?= formatCount((int)$p['total_comment']) ?></strong>
                </span>
                <button type="button" class="btn-close-comments" onclick="closeCommentPanel(<?= $p['id'] ?>)" title="Tutup Komentar">✕</button>
              </div>

              <!-- Daftar Komentar (Scrollable List) -->
              <div class="comment-panel-list" id="comment-list-<?= $p['id'] ?>">
                <?php if (empty($postComments)): ?>
                  <div class="empty-comment-notice" style="text-align:center; padding:30px 10px; color:#94A3B8; font-size:13px;">
                    <div style="font-size:28px; margin-bottom:6px;">💬</div>
                    Belum ada komentar.<br>Jadilah yang pertama berkomentar!
                  </div>
                <?php else: ?>
                  <?php foreach ($postComments as $cm): ?>
                    <div class="comment-row-item">
                      <a href="<?= baseUrl('pages/profile.php?id=' . $cm['user_id']) ?>">
                        <?= renderAvatar($cm['nama'] ?? $cm['username'], $cm['foto'] ?? null, 'avatar-sm', 'width:34px;height:34px;border:1.5px solid rgba(255,255,255,0.2);') ?>
                      </a>
                      <div class="comment-row-content">
                        <a href="<?= baseUrl('pages/profile.php?id=' . $cm['user_id']) ?>" class="comment-row-author">
                          <?= e($cm['nama'] ?? $cm['username']) ?>
                        </a>
                        <div class="comment-row-text"><?= nl2br(e($cm['isi'])) ?></div>
                        <div class="comment-row-meta">
                          <span><?= timeAgo($cm['created_at']) ?></span>
                          <button type="button" class="btn-reply" onclick="replyComment(<?= $p['id'] ?>, '<?= e($cm['username']) ?>')">Jawab</button>
                        </div>
                      </div>
                      <div class="comment-row-like" onclick="likeComment(this)">
                        <span>🤍</span>
                        <span>0</span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- Input Bar Tambah Komentar di Bawah Panel -->
              <form class="comment-panel-form" id="form-comment-<?= $p['id'] ?>" onsubmit="submitCommentAjax(event, <?= $p['id'] ?>)">
                <div class="comment-input-row">
                  <?= renderAvatar($_SESSION['nama'], $myInfo['foto'] ?? null, 'avatar-sm', 'width:30px;height:30px;') ?>
                  <input type="text" name="isi" id="input-comment-<?= $p['id'] ?>" placeholder="Tambah komentar..." autocomplete="off" required>
                  <button type="button" class="btn-input-emoji" onclick="insertEmoji(<?= $p['id'] ?>, '😊')" title="Tambah Emoji">😊</button>
                  <button type="submit" class="btn-input-send" title="Kirim Komentar">➔</button>
                </div>
              </form>

            </div> <!-- END .tiktok-comment-panel -->

          </div> <!-- END .tiktok-stage-item -->
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </section>

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
// 1. ASPECT RATIO ADAPTATION (16:9 Landscape vs 9:16 Portrait)
// ============================================================
function checkVideoAspect(vid, postId) {
  if (vid.videoWidth && vid.videoHeight) {
    const ratio = vid.videoWidth / vid.videoHeight;
    const stageItem = document.getElementById('post-' + postId);
    if (stageItem) {
      if (ratio >= 1.2) {
        stageItem.classList.add('is-landscape');
      } else {
        stageItem.classList.remove('is-landscape');
      }
    }
  }
}

function checkImageAspect(img, postId) {
  if (img.naturalWidth && img.naturalHeight) {
    const ratio = img.naturalWidth / img.naturalHeight;
    const stageItem = document.getElementById('post-' + postId);
    if (stageItem) {
      if (ratio >= 1.2) {
        stageItem.classList.add('is-landscape');
      } else {
        stageItem.classList.remove('is-landscape');
      }
    }
  }
}

// ============================================================
// 2. TOGGLE CAPTION EXPAND (Bawah Video memanjang ke atas 50%)
// ============================================================
function toggleCaptionExpand(postId) {
  const block = document.getElementById('caption-block-' + postId);
  if (!block) return;
  const isExp = block.classList.toggle('is-expanded');
  const btn = block.querySelector('.btn-caption-more');
  if (btn) {
    btn.textContent = isExp ? 'Lebih sedikit ▴' : 'lihat lebih banyak';
  }
}

// ============================================================
// 3. TOGGLE COMMENT PANEL (Panel Kanan ala Mockup Gambar)
// ============================================================
function toggleCommentPanel(postId) {
  const stage = document.getElementById('post-' + postId);
  if (!stage) return;

  const isOpen = stage.classList.contains('comments-open');

  // Tutup panel lain yang terbuka agar rapi
  document.querySelectorAll('.tiktok-stage-item.comments-open').forEach(el => {
    if (el !== stage) el.classList.remove('comments-open');
  });

  if (isOpen) {
    stage.classList.remove('comments-open');
  } else {
    stage.classList.add('comments-open');
    // Focus ke input komentar
    setTimeout(() => {
      const inp = document.getElementById('input-comment-' + postId);
      if (inp) inp.focus();
    }, 150);
  }
}

function closeCommentPanel(postId) {
  const stage = document.getElementById('post-' + postId);
  if (stage) stage.classList.remove('comments-open');
}

// ============================================================
// 4. AJAX SUBMIT KOMENTAR
// ============================================================
function submitCommentAjax(e, postId) {
  e.preventDefault();
  const form = document.getElementById('form-comment-' + postId);
  const input = document.getElementById('input-comment-' + postId);
  const text = input ? input.value.trim() : '';
  if (!text) return;

  const formData = new FormData(form);
  formData.append('post_id', postId);

  fetch('<?= baseUrl('pages/post_action.php?action=comment&ajax=1') ?>', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      input.value = '';
      
      // Update count badge di panel dan side button
      const countEl = document.getElementById('comment-panel-count-' + postId);
      if (countEl) countEl.textContent = formatJsCount(d.total);
      const btnCount = document.querySelector('.comment-count-btn-' + postId);
      if (btnCount) btnCount.textContent = formatJsCount(d.total);

      // Append ke comment list
      const list = document.getElementById('comment-list-' + postId);
      if (list) {
        const emptyNotice = list.querySelector('.empty-comment-notice');
        if (emptyNotice) emptyNotice.remove();

        const item = document.createElement('div');
        item.className = 'comment-row-item';
        item.style.animation = 'fadeIn 0.3s ease';
        item.innerHTML = `
          <div class="avatar avatar-sm" style="width:34px;height:34px;border:1.5px solid rgba(255,255,255,0.2);">
            ${d.comment.foto ? `<img src="${d.comment.foto}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">` : (d.comment.nama ? d.comment.nama.charAt(0).toUpperCase() : '?')}
          </div>
          <div class="comment-row-content">
            <span class="comment-row-author">${escapeHtml(d.comment.nama || d.comment.username)}</span>
            <div class="comment-row-text">${escapeHtml(d.comment.isi)}</div>
            <div class="comment-row-meta">
              <span>baru saja</span>
              <button type="button" class="btn-reply">Jawab</button>
            </div>
          </div>
          <div class="comment-row-like" onclick="likeComment(this)">
            <span>🤍</span>
            <span>0</span>
          </div>
        `;
        list.appendChild(item);
        list.scrollTop = list.scrollHeight;
      }
      showToast('💬 Komentar berhasil ditambahkan!');
    } else {
      alert(d.error || 'Gagal mengirim komentar.');
    }
  })
  .catch(err => {
    console.error(err);
    alert('Terjadi kesalahan jaringan.');
  });
}

function replyComment(postId, username) {
  const inp = document.getElementById('input-comment-' + postId);
  if (inp) {
    inp.value = '@' + username + ' ';
    inp.focus();
  }
}

function insertEmoji(postId, emoji) {
  const inp = document.getElementById('input-comment-' + postId);
  if (inp) {
    inp.value += emoji;
    inp.focus();
  }
}

function likeComment(btn) {
  const heart = btn.querySelector('span:first-child');
  const count = btn.querySelector('span:last-child');
  if (heart.textContent === '🤍') {
    heart.textContent = '❤️';
    btn.style.color = '#FE2C55';
    count.textContent = (parseInt(count.textContent) || 0) + 1;
  } else {
    heart.textContent = '🤍';
    btn.style.color = '#94A3B8';
    count.textContent = Math.max(0, (parseInt(count.textContent) || 0) - 1);
  }
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// ============================================================
// 5. LIKE & HEART POP AJAX
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

// Double click to like on media box
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

    setTimeout(() => heart.remove(), 900);

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
// 6. TOGGLE BOOKMARK / FAVORIT (AJAX)
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
// 7. VIDEO CONTROLS & SCRUBBER
// ============================================================
function togglePlayPause(postId) {
  const vid = document.getElementById('video-player-' + postId);
  const btn = document.getElementById('btn-play-' + postId);
  if (!vid) return;
  if (vid.paused) {
    vid.play();
    if (btn) btn.textContent = '⏸';
  } else {
    vid.pause();
    if (btn) btn.textContent = '▶';
  }
}

function toggleSound(postId) {
  const vid = document.getElementById('video-player-' + postId);
  const btn = document.getElementById('btn-sound-' + postId);
  if (!vid) return;
  vid.muted = !vid.muted;
  if (btn) btn.textContent = vid.muted ? '🔇' : '🔊';
}

function toggleFullscreen(postId) {
  const stage = document.getElementById('stage-' + postId);
  if (!stage) return;
  if (!document.fullscreenElement) {
    stage.requestFullscreen().catch(() => {});
  } else {
    document.exitFullscreen().catch(() => {});
  }
}

function updateProgress(postId) {
  const vid = document.getElementById('video-player-' + postId);
  const fill = document.getElementById('p-fill-' + postId);
  const timeDisplay = document.getElementById('ctrl-time-' + postId);
  if (!vid) return;

  if (vid.duration) {
    const pct = (vid.currentTime / vid.duration) * 100;
    if (fill) fill.style.width = pct + '%';
    if (timeDisplay) {
      timeDisplay.textContent = formatTime(vid.currentTime) + ' / ' + formatTime(vid.duration);
    }
  }
}

function seekVideo(e, postId) {
  const vid = document.getElementById('video-player-' + postId);
  if (!vid || !vid.duration) return;
  const bar = e.currentTarget;
  const rect = bar.getBoundingClientRect();
  const pos = (e.clientX - rect.left) / rect.width;
  vid.currentTime = pos * vid.duration;
}

function formatTime(sec) {
  sec = Math.floor(sec || 0);
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

// Intersection Observer: Autoplay center video, pause when scrolled
if ('IntersectionObserver' in window) {
  const videoObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      const vid = entry.target.querySelector('video');
      const postId = entry.target.getAttribute('data-post-id');
      const btn = document.getElementById('btn-play-' + postId);
      if (vid) {
        if (entry.isIntersecting && entry.intersectionRatio >= 0.55) {
          vid.muted = true; // muted for browser policy
          const playPromise = vid.play();
          if (playPromise !== undefined) {
            playPromise.then(() => {
              if (btn) btn.textContent = '⏸';
            }).catch(() => {});
          }
        } else {
          vid.pause();
          if (btn) btn.textContent = '▶';
        }
      }
    });
  }, { threshold: [0.55] });

  document.querySelectorAll('.tiktok-stage-item').forEach(item => {
    videoObserver.observe(item);
  });
}

// ============================================================
// 8. SHARE & TOAST
// ============================================================
function showToast(msg) {
  const toast = document.getElementById('tiktokToast');
  if (!toast) return;
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
      showToast('✨ Tautan postingan disalin ke clipboard!');
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
// 9. UP/DOWN STAGE ARROWS (TIKTOK DESKTOP SIGNATURE CONTROLS)
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

window.addEventListener('keydown', function(e) {
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
