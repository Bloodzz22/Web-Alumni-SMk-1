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

// Ambil daftar teman untuk picker "Tag Teman"
$friendsStmt = $conn->prepare("
    SELECT u.id, u.username, p.nama, ap.profesi 
    FROM users u 
    LEFT JOIN profiles p ON p.user_id = u.id 
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id <> ? 
    ORDER BY p.nama ASC
");
$friendsStmt->bind_param('i', $userId);
$friendsStmt->execute();
$availableFriends = $friendsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

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

    <!-- FORM BUAT POSTINGAN (TIKTOK UPLOAD STYLE) -->
    <div class="tiktok-create-card">
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
        <a href="<?= baseUrl('pages/profile.php') ?>">
          <?= renderAvatar($_SESSION['nama'], $myInfo['foto'] ?? null) ?>
        </a>
        <div>
          <div style="font-weight:700; color:var(--navy); font-size:14px;"><?= e($_SESSION['nama']) ?></div>
          <div style="font-size:11px; color:var(--text-muted);">Bagikan video atau foto karya, karier, dan kegiatanmu</div>
        </div>
      </div>

      <form method="POST" action="<?= baseUrl('pages/post_action.php?action=create') ?>" enctype="multipart/form-data">
        <!-- Deskripsi Postingan -->
        <div class="form-group">
          <textarea name="konten" class="form-textarea" rows="3" 
                    placeholder="Tulis deskripsi / caption... (bisa pakai hashtag #PPLG atau mention teman @username)" required></textarea>
        </div>

        <!-- Live Preview Media yang dipilih -->
        <div id="mediaPreviewBox" style="display:none; margin-bottom:14px; border-radius:14px; overflow:hidden; max-height:300px; background:#000; text-align:center;">
          <!-- injected by JS -->
        </div>

        <!-- Baris Pengaturan: Upload File, Tag Teman, Kategori -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-bottom:14px;">
          
          <!-- Upload Video / Foto -->
          <div>
            <label class="form-label" style="margin-bottom:4px; font-size:11px;">📹 Unggah Video / Foto</label>
            <input type="file" name="media" id="mediaInput" accept="image/*,video/*" class="form-input" style="padding:6px 10px; font-size:12px;">
          </div>

          <!-- Kategori -->
          <div>
            <label class="form-label" style="margin-bottom:4px; font-size:11px;">📂 Kategori</label>
            <select name="category_id" class="form-select" style="padding:7px 10px; font-size:12px;">
              <option value="">Pilih Kategori (opsional)</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= e($cat['nama_kategori']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

        </div>

        <!-- Tag Teman (Muncul di Pesan) -->
        <div class="form-group">
          <label class="form-label" style="margin-bottom:4px; font-size:11px; display:flex; justify-content:space-between;">
            <span>🏷️ Tag Teman (Otomatis dapat pesan di inbox)</span>
            <span style="font-weight:normal; text-transform:none; color:var(--text-muted);">Tahan Ctrl untuk pilih lebih dari 1</span>
          </label>
          <select name="tag_users[]" class="form-select" multiple style="height:76px; font-size:12px; padding:6px 8px;">
            <?php foreach ($availableFriends as $fr): ?>
              <option value="<?= $fr['id'] ?>">
                <?= e($fr['nama'] ?? $fr['username']) ?> (@<?= e($fr['username']) ?> - <?= e($fr['profesi'] ?? 'Member') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Tombol Publish -->
        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border); padding-top:12px;">
          <div style="font-size:11px; color:var(--text-muted);">
            💡 Teman yang kamu tandai akan langsung menerima link postingan ini di Pesan.
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 26px; font-size:13px;">
            🚀 Posting Video/Foto
          </button>
        </div>
      </form>
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
        <a href="#" class="filter-pill active">
          #<?= e($tagFilter) ?> (Filter aktif)
        </a>
      <?php endif; ?>
    </div>

    <!-- DAFTAR FEED TIKTOK-STYLE CONTAINER -->
    <div class="tiktok-feed-container">
      <?php if (empty($posts)): ?>
        <div class="card text-center text-muted" style="padding:40px 20px;">
          <div style="font-size:40px; margin-bottom:10px;">📱</div>
          <h3 style="font-size:16px; font-weight:700; color:var(--navy);">Belum ada postingan TikTok-style</h3>
          <p style="font-size:13px; margin-top:4px;">Jadilah orang pertama yang mengunggah video atau foto!</p>
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

          <div class="tiktok-card" id="post-<?= $p['id'] ?>">

            <!-- 1. Background / Media Layer -->
            <div class="tiktok-media-box">
              <?php if ($isMediaExists && $isVideo): ?>
                <video src="<?= $mediaUrl ?>" controls loop playsinline preload="metadata"></video>
              <?php elseif ($isMediaExists && !$isVideo): ?>
                <img src="<?= $mediaUrl ?>" alt="Post Image">
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
                        onclick="toggleLike(<?= $p['id'] ?>, this)" title="Sukai">
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
                <button class="tiktok-action-btn" onclick="sharePost(<?= $p['id'] ?>, this)" title="Bagikan">
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

<script>
// Preview Media Live saat memilih Video atau Foto
document.getElementById('mediaInput').addEventListener('change', function(e) {
  const file = e.target.files[0];
  const box = document.getElementById('mediaPreviewBox');
  if (file) {
    const fileType = file.type;
    const reader = new FileReader();
    reader.onload = function(evt) {
      box.style.display = 'block';
      if (fileType.startsWith('video/')) {
        box.innerHTML = '<video src="' + evt.target.result + '" controls style="max-height:280px; width:100%; object-fit:contain;"></video>';
      } else {
        box.innerHTML = '<img src="' + evt.target.result + '" style="max-height:280px; width:100%; object-fit:contain;" alt="Preview">';
      }
    };
    reader.readAsDataURL(file);
  } else {
    box.style.display = 'none';
    box.innerHTML = '';
  }
});

function toggleLike(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=like&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        btn.classList.toggle('liked', d.liked);
        const countSpan = btn.parentElement.querySelector('.like-count');
        if (countSpan) countSpan.textContent = d.total;
      }
    });
}

function sharePost(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=share&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => { 
      if (d.success) {
        const countSpan = btn.parentElement.querySelector('.share-count');
        if (countSpan) countSpan.textContent = d.total;
        alert('Tautan postingan berhasil dibagikan!');
      } 
    });
}

function toggleTiktokComments(id) {
  const drawer = document.getElementById('comment-drawer-' + id);
  if (drawer) {
    drawer.style.display = (drawer.style.display === 'none' || drawer.style.display === '') ? 'flex' : 'none';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>