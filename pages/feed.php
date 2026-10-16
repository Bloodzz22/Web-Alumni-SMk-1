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

// Feed SQL: Post diurutkan berdasarkan Engagement Score Ranking ala TikTok/Instagram
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

// Algoritma Engagement Ranking (Proposal Bab 6.2)
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

$pageTitle = 'Beranda Feed TikTok-Style';
include __DIR__ . '/../includes/header.php';
?>

<div class="feed-layout">

  <!-- Sidebar Kiri: Navigasi Desktop Sesuai Wireframe -->
  <aside class="sidebar sidebar-left">
    <ul class="sidebar-menu">
      <li><a href="<?= baseUrl('pages/feed.php') ?>" class="<?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">🏠 Beranda Feed</a></li>
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

  <!-- Bagian Utama Feed TikTok-Style -->
  <section style="min-width:0;">

    <!-- BAR PINTAS BUAT POSTINGAN (TERPISAH DARI FEED AGAR FEED BERSIH) -->
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

    <!-- Filter Kategori Feed Bar -->
    <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:10px; margin-bottom:16px;">
      <a href="<?= baseUrl('pages/feed.php') ?>" 
         class="filter-pill <?= (!$catFilter && !$tagFilter) ? 'active' : '' ?>">
        ✨ Semua Feed
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

    <!-- DAFTAR FEED TIKTOK-STYLE CONTAINER -->
    <div class="tiktok-feed-container">
      <?php if (empty($posts)): ?>
        <div class="card text-center text-muted" style="padding:40px 20px;">
          <div style="font-size:40px; margin-bottom:10px;">📱</div>
          <h3 style="font-size:16px; font-weight:700; color:var(--navy);">Belum ada postingan TikTok-style</h3>
          <p style="font-size:13px; margin-top:4px; margin-bottom:16px;">Jadilah orang pertama yang mengunggah video atau foto!</p>
          <a href="<?= baseUrl('pages/create_post.php') ?>" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
            ➕ Buat Postingan Sekarang
          </a>
        </div>
      <?php else: ?>
        <?php foreach ($posts as $p): ?>
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

          <div class="tiktok-card" id="post-<?= $p['id'] ?>" data-post-id="<?= $p['id'] ?>">

            <!-- 1. Background / Media Layer (Double Tap / Click to Like) -->
            <div class="tiktok-media-box" data-post-id="<?= $p['id'] ?>">
              <?php if ($isMediaExists && $isVideo): ?>
                <video src="<?= $mediaUrl ?>" loop playsinline preload="metadata" data-post-video="true"></video>
                <!-- Play/Pause Indicator -->
                <div class="tiktok-play-overlay">▶</div>
                <!-- Sound Mute/Unmute Button -->
                <button type="button" class="tiktok-sound-btn" onclick="toggleMuteVideo(this, event)" title="Suara Video">🔇</button>
              <?php elseif ($isMediaExists && !$isVideo): ?>
                <img src="<?= $mediaUrl ?>" alt="Post Image" loading="lazy">
              <?php else: ?>
                <!-- Text-only TikTok Poster Backdrop -->
                <div class="tiktok-text-backdrop">
                  <div class="text-quote">
                    “<?= nl2br($caption) ?>”
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <!-- Gradient Shadow Overlay -->
            <div class="tiktok-overlay-gradient"></div>

            <!-- 2. Floating Action Bar ala TikTok (Sebelah Kanan) -->
            <div class="tiktok-actions-bar">
              
              <!-- Avatar Profil Penulis -->
              <div class="tiktok-action-item">
                <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>" title="<?= e($p['nama'] ?? $p['username']) ?>">
                  <?= renderAvatar($p['nama'] ?? $p['username'], $p['foto'] ?? null, 'avatar-sm', 'border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.5);') ?>
                </a>
              </div>

              <!-- Tombol Like -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-btn <?= $p['is_liked'] ? 'liked' : '' ?>" 
                        onclick="toggleLike(<?= $p['id'] ?>, this)" title="Sukai (Bisa juga double-click video!)">
                  ❤️
                </button>
                <span class="tiktok-action-count like-count"><?= $p['total_like'] ?></span>
              </div>

              <!-- Tombol Komentar -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-btn" onclick="toggleTiktokComments(<?= $p['id'] ?>)" title="Komentar">
                  💬
                </button>
                <span class="tiktok-action-count"><?= $p['total_comment'] ?></span>
              </div>

              <!-- Tombol Share -->
              <div class="tiktok-action-item">
                <button class="tiktok-action-btn" onclick="sharePost(<?= $p['id'] ?>, this)" title="Salin & Bagikan Tautan">
                  🔗
                </button>
                <span class="tiktok-action-count share-count"><?= $p['total_share'] ?></span>
              </div>

              <!-- Tombol Edit / Hapus jika pemilik atau admin -->
              <?php if ((int)$p['user_id'] === $userId || (int)$_SESSION['role_id'] === 1): ?>
                <div class="tiktok-action-item">
                  <a href="<?= baseUrl('pages/edit_post.php?id=' . $p['id']) ?>" class="tiktok-action-btn" style="font-size:14px;" title="Edit Post">
                    ✏️
                  </a>
                </div>
                <div class="tiktok-action-item">
                  <a href="<?= baseUrl('pages/post_action.php?action=delete&post_id=' . $p['id']) ?>" 
                     class="tiktok-action-btn" style="font-size:14px; background:rgba(239,68,68,0.4);" 
                     onclick="return confirm('Hapus postingan ini?')" title="Hapus Post">
                    🗑️
                  </a>
                </div>
              <?php endif; ?>

            </div>

            <!-- 3. Bottom Caption & Info Overlay (ala TikTok) -->
            <div class="tiktok-caption-box">
              <div class="tiktok-author-row">
                <a href="<?= baseUrl('pages/profile.php?id=' . $p['user_id']) ?>" class="tiktok-author-name">
                  @<?= e($p['username']) ?>
                </a>
                <span style="font-size:12px; color:#E2E8F0;">• <?= e($p['nama']) ?></span>
                
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

              <!-- Teman yang Ditandai (Tag Teman) -->
              <?php if (!empty($mentions)): ?>
                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:8px;">
                  <span style="font-size:11px; color:#FCD34D; font-weight:700;">🏷️ Ditandai bersama:</span>
                  <?php foreach ($mentions as $men): ?>
                    <a href="<?= baseUrl('pages/profile.php?id=' . $men['user_id']) ?>" 
                       style="font-size:11px; background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:10px; color:white; text-decoration:none;">
                      @<?= e($men['username']) ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <!-- Baris Meta: Waktu & Audio -->
              <div class="tiktok-meta-row">
                <span>🕒 <?= timeAgo($p['created_at']) ?></span>
                <span>🎵 Suara Asli - ALUMNICONNECT Almamater</span>
              </div>
            </div>

            <!-- 4. Drawer Komentar TikTok (Terbuka saat tombol chat ditekan) -->
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

              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:6px;">
                <span style="font-size:12px; font-weight:700; color:#F1F5F9;">💬 Komentar (<?= count($comments) ?>)</span>
                <button type="button" onclick="toggleTiktokComments(<?= $p['id'] ?>)" style="background:none; border:none; color:#94A3B8; font-size:14px; cursor:pointer;">✕</button>
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

              <!-- Input Komentar Cepat -->
              <form method="POST" action="<?= baseUrl('pages/post_action.php?action=comment') ?>" style="display:flex; gap:8px;">
                <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                <input type="text" name="isi" class="form-input" style="padding:7px 12px; font-size:12px; background:rgba(255,255,255,0.1); color:white; border-color:rgba(255,255,255,0.2);" placeholder="Tulis komentar publik..." required>
                <button type="submit" class="btn btn-primary btn-sm" style="padding:6px 14px; font-size:12px;">Kirim</button>
              </form>
            </div>

          </div>
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

<!-- FLOATING ACTION BUTTON (FAB) TIKTOK-STYLE UNTUK BUAT POSTINGAN CEPAT -->
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
          if (countSpan) countSpan.textContent = d.total;
        }
      }
    });
}

// Double tap / Double click to like on media box
document.querySelectorAll('.tiktok-media-box').forEach(box => {
  let lastTap = 0;
  
  function triggerHeartPop(e) {
    const postId = box.getAttribute('data-post-id');
    const card = document.getElementById('post-' + postId);
    if (!card) return;

    // Pop animated heart
    const heart = document.createElement('div');
    heart.className = 'heart-pop';
    heart.innerHTML = '❤️';
    box.appendChild(heart);

    setTimeout(() => {
      heart.remove();
    }, 900);

    // Call like if not already liked
    const likeBtn = card.querySelector('.tiktok-action-btn[title*="Sukai"]');
    if (likeBtn && !likeBtn.classList.contains('liked')) {
      toggleLike(postId, likeBtn);
    }
  }

  // Double click for desktop
  box.addEventListener('dblclick', function(e) {
    triggerHeartPop(e);
  });

  // Double tap for mobile touch devices
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
// 2. VIDEO PLAY / PAUSE ON TAP & SMART SCROLL AUTOPLAY
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

  document.querySelectorAll('.tiktok-card').forEach(card => {
    videoObserver.observe(card);
  });
}

// ============================================================
// 3. SHARE LINK & TOAST ALERT
// ============================================================
function showToast(msg) {
  const toast = document.getElementById('tiktokToast');
  toast.textContent = msg;
  toast.style.display = 'block';
  toast.classList.add('show');
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.style.display = 'none', 300);
  }, 3000);
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
        if (countSpan) countSpan.textContent = d.total;
      } 
    });
}

// ============================================================
// 4. COMMENT DRAWER TOGGLE
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>