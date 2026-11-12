<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId;

// 1. Ambil info user & profil
$stmt = $conn->prepare("
    SELECT 
        u.id, u.username, u.email, u.role_id, r.nama_role,
        p.nama, p.foto, p.bio,
        ap.angkatan, ap.tahun_lulus, ap.jurusan, ap.profesi, ap.perusahaan,
        (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS total_followers,
        (SELECT COUNT(*) FROM followers WHERE follower_id = u.id)  AS total_following,
        (SELECT COUNT(*) FROM followers WHERE follower_id = ? AND following_id = u.id) AS is_following
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $currentUserId, $profileUserId);
$stmt->execute();
$userProfile = $stmt->get_result()->fetch_assoc();

if (!$userProfile) {
    setFlash('danger', 'Pengguna tidak ditemukan.');
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

$isMe = ($currentUserId === $profileUserId);

// 2. Hitung Total Suka (Likes) yang Diterima User di Semua Postingan
$likeCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total_likes 
    FROM likes l 
    JOIN posts p ON p.id = l.post_id 
    WHERE p.user_id = ?
");
$likeCountStmt->bind_param('i', $profileUserId);
$likeCountStmt->execute();
$totalLikes = (int)$likeCountStmt->get_result()->fetch_assoc()['total_likes'];

// 3. Ambil Postingan Milik User Ini (Tab: Video / Postingan)
$myPostsStmt = $conn->prepare("
    SELECT 
        p.id, p.konten, p.media, p.created_at,
        c.nama_kategori,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS total_like,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS total_comment,
        (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id) AS total_bookmark,
        (SELECT COUNT(*) FROM shares WHERE post_id = p.id) AS total_share
    FROM posts p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
$myPostsStmt->bind_param('i', $profileUserId);
$myPostsStmt->execute();
$myPosts = $myPostsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Ambil Postingan yang Disimpan / Favorit oleh User Ini (Tab: Favorit)
$favPostsStmt = $conn->prepare("
    SELECT 
        p.id, p.konten, p.media, p.created_at,
        u.id AS author_id, u.username AS author_username,
        pr.nama AS author_nama, pr.foto AS author_foto,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS total_like,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS total_comment
    FROM bookmarks b
    JOIN posts p ON p.id = b.post_id
    JOIN users u ON u.id = p.user_id
    LEFT JOIN profiles pr ON pr.user_id = u.id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
");
$favPostsStmt->bind_param('i', $profileUserId);
$favPostsStmt->execute();
$favPosts = $favPostsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 5. Ambil Postingan yang Disukai oleh User Ini (Tab: Disukai)
$likedPostsStmt = $conn->prepare("
    SELECT 
        p.id, p.konten, p.media, p.created_at,
        u.id AS author_id, u.username AS author_username,
        pr.nama AS author_nama, pr.foto AS author_foto,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS total_like,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS total_comment
    FROM likes l
    JOIN posts p ON p.id = l.post_id
    JOIN users u ON u.id = p.user_id
    LEFT JOIN profiles pr ON pr.user_id = u.id
    WHERE l.user_id = ?
    ORDER BY l.created_at DESC
");
$likedPostsStmt->bind_param('i', $profileUserId);
$likedPostsStmt->execute();
$likedPosts = $likedPostsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 6. Ambil Pengalaman Kerja & Pendidikan Alumni
$workStmt = $conn->prepare("
    SELECT w.* 
    FROM alumni_work w
    JOIN alumni_profiles ap ON ap.id = w.alumni_id
    WHERE ap.user_id = ?
    ORDER BY w.tahun_mulai DESC
");
$workStmt->bind_param('i', $profileUserId);
$workStmt->execute();
$works = $workStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$eduStmt = $conn->prepare("
    SELECT e.* 
    FROM alumni_education e
    JOIN alumni_profiles ap ON ap.id = e.alumni_id
    WHERE ap.user_id = ?
    ORDER BY e.tahun_masuk DESC
");
$eduStmt->bind_param('i', $profileUserId);
$eduStmt->execute();
$educations = $eduStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 7. Unread Notification Count untuk Sidebar
$unreadNotifCount = 0;
$nCheck = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
if ($nCheck && $nCheck->num_rows > 0) {
    $nr = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE user_id = $currentUserId AND is_read = 0");
    if ($nr) $unreadNotifCount = (int)$nr->fetch_assoc()['total'];
}

$pageTitle = '@' . ($userProfile['username'] ?? 'profil');
include __DIR__ . '/../includes/header.php';
?>

<div class="tt-profile-layout">

  <!-- ============================================================
       SIDEBAR KIRI ("HODBAR") SESUAI MOCKUP TIKTOK WEB
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
        <a href="<?= baseUrl('pages/feed.php') ?>">
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
        <a href="<?= baseUrl('pages/profile.php') ?>" class="<?= $isMe ? 'active' : '' ?>">
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

    <!-- Mini Profil di Bawah Menu -->
    <div class="card" style="margin-top:14px; padding:12px 14px; display:flex; align-items:center; gap:10px;">
      <a href="<?= baseUrl('pages/profile.php') ?>">
        <?= renderAvatar($_SESSION['nama'], null, 'avatar-sm') ?>
      </a>
      <div style="min-width:0; flex:1;">
        <a href="<?= baseUrl('pages/profile.php') ?>" style="font-size:13px; font-weight:700; color:var(--navy); display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
          <?= e($_SESSION['nama']) ?>
        </a>
        <div style="font-size:11px; color:var(--text-muted);">@<?= e($_SESSION['username'] ?? 'user') ?></div>
      </div>
    </div>
  </aside>

  <!-- ============================================================
       CONTAINER PROFIL TIKTOK (SESUAI MOCKUP GAMBAR USER)
       ============================================================ -->
  <main class="tt-profile-container">

    <!-- Header Profil Ala TikTok -->
    <section class="tt-profile-header">
      
      <!-- Avatar Besar (Ungu TikTok atau Foto Pengguna) -->
      <?php if (!empty($userProfile['foto']) && file_exists(__DIR__ . '/../' . ltrim($userProfile['foto'], '/\\'))): ?>
        <img src="<?= baseUrl($userProfile['foto']) ?>" class="tt-profile-avatar" alt="<?= e($userProfile['username']) ?>">
      <?php else: ?>
        <div class="tt-profile-avatar" title="<?= e($userProfile['username']) ?>">
          <?= strtoupper(substr($userProfile['username'] ?: ($userProfile['nama'] ?? 'U'), 0, 1)) ?>
        </div>
      <?php endif; ?>

      <!-- Info Username, Stats, Actions, & Bio -->
      <div class="tt-profile-info">
        
        <!-- Judul: Username & Nama Lengkap -->
        <div class="tt-profile-title-row">
          <h1 class="tt-profile-username"><?= e($userProfile['username']) ?></h1>
          <span class="tt-profile-handle"><?= e($userProfile['nama'] ?: $userProfile['username']) ?></span>
        </div>

        <!-- Statistik: Mengikuti | Pengikut | Suka -->
        <div class="tt-profile-stats">
          <div class="tt-profile-stat-item">
            <span class="tt-profile-stat-number" id="stat-following"><?= number_format((int)$userProfile['total_following']) ?></span>
            <span class="tt-profile-stat-label">Mengikuti</span>
          </div>
          <div class="tt-profile-stat-item">
            <span class="tt-profile-stat-number" id="stat-followers"><?= number_format((int)$userProfile['total_followers']) ?></span>
            <span class="tt-profile-stat-label">Pengikut</span>
          </div>
          <div class="tt-profile-stat-item">
            <span class="tt-profile-stat-number" id="stat-likes"><?= number_format($totalLikes) ?></span>
            <span class="tt-profile-stat-label">Suka</span>
          </div>
        </div>

        <!-- Tombol Aksi Sesuai Mockup -->
        <div class="tt-profile-actions">
          <?php if ($isMe): ?>
            <a href="<?= baseUrl('pages/edit_profile.php') ?>" class="btn-tt-gray">
              Edit profil
            </a>
            <button type="button" class="btn-tt-gray" onclick="copyProfileLink()">
              Promosikan postingan
            </button>
            <a href="<?= baseUrl('pages/edit_profile.php') ?>" class="btn-tt-icon" title="Pengaturan Akun">
              ⚙️
            </a>
            <button type="button" class="btn-tt-icon" onclick="copyProfileLink()" title="Bagikan Tautan Profil">
              ↗
            </button>
          <?php else: ?>
            <button type="button" 
                    class="btn-tt-follow <?= $userProfile['is_following'] ? 'following' : '' ?>" 
                    id="btn-follow-user"
                    onclick="toggleFollowUser(<?= (int)$userProfile['id'] ?>)">
              <?= $userProfile['is_following'] ? 'Mengikuti' : 'Ikuti' ?>
            </button>
            <a href="<?= baseUrl('pages/messages.php?to=' . (int)$userProfile['id']) ?>" class="btn-tt-gray">
              💬 Kirim Pesan
            </a>
            <button type="button" class="btn-tt-icon" onclick="copyProfileLink()" title="Bagikan Tautan Profil">
              ↗
            </button>
          <?php endif; ?>
        </div>

        <!-- Bio / Deskripsi Diri -->
        <div class="tt-profile-bio">
          <?= !empty($userProfile['bio']) ? nl2br(e($userProfile['bio'])) : 'Belum ada biodata.' ?>
        </div>

        <!-- Alumni Badges (Angkatan, Jurusan, Profesi) -->
        <?php if (!empty($userProfile['angkatan']) || !empty($userProfile['jurusan']) || !empty($userProfile['profesi'])): ?>
          <div class="tt-profile-tags">
            <?php if (!empty($userProfile['angkatan'])): ?>
              <span class="tt-profile-badge">🎓 Angkatan <?= e($userProfile['angkatan']) ?></span>
            <?php endif; ?>
            <?php if (!empty($userProfile['jurusan'])): ?>
              <span class="tt-profile-badge">💻 <?= e($userProfile['jurusan']) ?></span>
            <?php endif; ?>
            <?php if (!empty($userProfile['profesi'])): ?>
              <span class="tt-profile-badge">💼 <?= e($userProfile['profesi']) ?><?= !empty($userProfile['perusahaan']) ? ' @ ' . e($userProfile['perusahaan']) : '' ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>
    </section>

    <!-- ============================================================
         TABS BAR ALA TIKTOK (VIDEO, FAVORIT, DISUKAI, BIODATA)
         ============================================================ -->
    <div class="tt-profile-tabs">
      <button type="button" class="tt-tab-btn active" data-tab="tab-video">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
          <line x1="8" y1="6" x2="8" y2="18"></line>
          <line x1="12" y1="6" x2="12" y2="18"></line>
          <line x1="16" y1="6" x2="16" y2="18"></line>
        </svg>
        Video <span class="tt-tab-count">(<?= count($myPosts) ?>)</span>
      </button>

      <button type="button" class="tt-tab-btn" data-tab="tab-favorit">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
        </svg>
        Favorit <span class="tt-tab-count">(<?= count($favPosts) ?>)</span>
      </button>

      <button type="button" class="tt-tab-btn" data-tab="tab-disukai">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
        </svg>
        Disukai <span class="tt-tab-count">(<?= count($likedPosts) ?>)</span>
      </button>

      <button type="button" class="tt-tab-btn" data-tab="tab-alumni">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
          <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
        </svg>
        Biodata Alumni
      </button>
    </div>

    <!-- ============================================================
         TAB PANE 1: VIDEO / POSTINGAN MILIK SENDIRI
         ============================================================ -->
    <div class="tt-tab-pane active" id="tab-video">
      <?php if (empty($myPosts)): ?>
        <!-- EMPTY STATE PERSIS MOCKUP -->
        <div class="tt-empty-state">
          <div class="tt-empty-icon">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="7" height="7"></rect>
              <rect x="14" y="3" width="7" height="7"></rect>
              <rect x="14" y="14" width="7" height="7"></rect>
              <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
          </div>
          <h2 class="tt-empty-title">Unggah video pertama Anda</h2>
          <p class="tt-empty-sub">Video Anda akan muncul di sini</p>
          <?php if ($isMe): ?>
            <a href="<?= baseUrl('pages/create_post.php') ?>" class="btn-tt-follow" style="text-decoration:none;">
              ➕ Unggah Video / Foto
            </a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="tt-media-grid">
          <?php foreach ($myPosts as $p): ?>
            <?php 
              $mediaUrl = !empty($p['media']) ? baseUrl($p['media']) : '';
              $ext = strtolower(pathinfo($p['media'] ?? '', PATHINFO_EXTENSION));
              $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'ogg', 'm4v']);
              $isImg   = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            ?>
            <div class="tt-media-card" onclick="window.location.href='<?= baseUrl('pages/feed.php#post-' . $p['id']) ?>'">
              
              <?php if ($isVideo): ?>
                <video src="<?= $mediaUrl ?>" preload="metadata" muted loop playsinline onmouseenter="this.play()" onmouseleave="this.pause();this.currentTime=0;"></video>
              <?php elseif ($isImg): ?>
                <img src="<?= $mediaUrl ?>" alt="Post Media" loading="lazy">
              <?php else: ?>
                <div class="tt-media-text-card">
                  <span style="font-size:24px; opacity:0.6; margin-bottom:6px;">💬</span>
                  <div class="tt-media-text-preview"><?= e(mb_substr($p['konten'], 0, 120)) ?></div>
                </div>
              <?php endif; ?>

              <?php if ($isMe || (int)$_SESSION['role_id'] === 1): ?>
                <button type="button" 
                        class="tt-card-del-btn" 
                        title="Hapus postingan"
                        onclick="event.stopPropagation(); if(confirm('Hapus postingan ini?')) window.location.href='<?= baseUrl('pages/post_action.php?action=delete&post_id=' . $p['id']) ?>';">
                  🗑️
                </button>
              <?php endif; ?>

              <div class="tt-card-bottom-overlay">
                <div class="tt-card-stat">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                  </svg>
                  <span><?= formatCount($p['total_like']) ?></span>
                </div>
                <div class="tt-card-stat" style="font-size:11px; opacity:0.85;">
                  💬 <?= $p['total_comment'] ?>
                </div>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ============================================================
         TAB PANE 2: POSTINGAN FAVORIT
         ============================================================ -->
    <div class="tt-tab-pane" id="tab-favorit">
      <?php if (empty($favPosts)): ?>
        <div class="tt-empty-state">
          <div class="tt-empty-icon">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
            </svg>
          </div>
          <h2 class="tt-empty-title">Belum ada postingan favorit</h2>
          <p class="tt-empty-sub">Postingan yang disimpan sebagai favorit akan muncul di sini</p>
          <a href="<?= baseUrl('pages/feed.php') ?>" class="btn-tt-gray" style="text-decoration:none;">
            Jelajahi Postingan
          </a>
        </div>
      <?php else: ?>
        <div class="tt-media-grid">
          <?php foreach ($favPosts as $p): ?>
            <?php 
              $mediaUrl = !empty($p['media']) ? baseUrl($p['media']) : '';
              $ext = strtolower(pathinfo($p['media'] ?? '', PATHINFO_EXTENSION));
              $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'ogg', 'm4v']);
              $isImg   = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            ?>
            <div class="tt-media-card" onclick="window.location.href='<?= baseUrl('pages/feed.php#post-' . $p['id']) ?>'">
              
              <div class="tt-card-author-pill">
                @<?= e($p['author_username']) ?>
              </div>

              <?php if ($isVideo): ?>
                <video src="<?= $mediaUrl ?>" preload="metadata" muted loop playsinline onmouseenter="this.play()" onmouseleave="this.pause();this.currentTime=0;"></video>
              <?php elseif ($isImg): ?>
                <img src="<?= $mediaUrl ?>" alt="Post Media" loading="lazy">
              <?php else: ?>
                <div class="tt-media-text-card">
                  <span style="font-size:24px; opacity:0.6; margin-bottom:6px;">💬</span>
                  <div class="tt-media-text-preview"><?= e(mb_substr($p['konten'], 0, 120)) ?></div>
                </div>
              <?php endif; ?>

              <div class="tt-card-bottom-overlay">
                <div class="tt-card-stat">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                  </svg>
                  <span><?= formatCount($p['total_like']) ?></span>
                </div>
                <div class="tt-card-stat" style="font-size:11px; opacity:0.85;">
                  💬 <?= $p['total_comment'] ?>
                </div>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ============================================================
         TAB PANE 3: POSTINGAN YANG DISUKAI
         ============================================================ -->
    <div class="tt-tab-pane" id="tab-disukai">
      <?php if (empty($likedPosts)): ?>
        <div class="tt-empty-state">
          <div class="tt-empty-icon">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
            </svg>
          </div>
          <h2 class="tt-empty-title">Belum ada video yang disukai</h2>
          <p class="tt-empty-sub">Postingan yang Anda sukai akan muncul di sini</p>
          <a href="<?= baseUrl('pages/feed.php') ?>" class="btn-tt-gray" style="text-decoration:none;">
            Jelajahi Postingan
          </a>
        </div>
      <?php else: ?>
        <div class="tt-media-grid">
          <?php foreach ($likedPosts as $p): ?>
            <?php 
              $mediaUrl = !empty($p['media']) ? baseUrl($p['media']) : '';
              $ext = strtolower(pathinfo($p['media'] ?? '', PATHINFO_EXTENSION));
              $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'ogg', 'm4v']);
              $isImg   = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            ?>
            <div class="tt-media-card" onclick="window.location.href='<?= baseUrl('pages/feed.php#post-' . $p['id']) ?>'">
              
              <div class="tt-card-author-pill">
                @<?= e($p['author_username']) ?>
              </div>

              <?php if ($isVideo): ?>
                <video src="<?= $mediaUrl ?>" preload="metadata" muted loop playsinline onmouseenter="this.play()" onmouseleave="this.pause();this.currentTime=0;"></video>
              <?php elseif ($isImg): ?>
                <img src="<?= $mediaUrl ?>" alt="Post Media" loading="lazy">
              <?php else: ?>
                <div class="tt-media-text-card">
                  <span style="font-size:24px; opacity:0.6; margin-bottom:6px;">💬</span>
                  <div class="tt-media-text-preview"><?= e(mb_substr($p['konten'], 0, 120)) ?></div>
                </div>
              <?php endif; ?>

              <div class="tt-card-bottom-overlay">
                <div class="tt-card-stat" style="color:#FE2C55;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                  </svg>
                  <span style="color:#FFFFFF;"><?= formatCount($p['total_like']) ?></span>
                </div>
                <div class="tt-card-stat" style="font-size:11px; opacity:0.85;">
                  💬 <?= $p['total_comment'] ?>
                </div>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ============================================================
         TAB PANE 4: BIODATA ALUMNI (PENGALAMAN KERJA & PENDIDIKAN)
         ============================================================ -->
    <div class="tt-tab-pane" id="tab-alumni" style="padding-top:20px;">
      
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
        
        <!-- Riwayat Kerja -->
        <div class="tt-career-card">
          <div class="tt-career-title">
            <span>💼</span> Pengalaman Kerja & Karir
          </div>
          <?php if (empty($works)): ?>
            <p style="color:#94A3B8; font-size:13.5px; margin:0;">Belum ada pengalaman kerja yang ditambahkan.</p>
          <?php else: ?>
            <?php foreach ($works as $w): ?>
              <div class="tt-career-item">
                <div style="font-size:15px; font-weight:700; color:#FFFFFF;"><?= e($w['posisi']) ?></div>
                <div style="font-size:13.5px; font-weight:600; color:#38BDF8;"><?= e($w['perusahaan']) ?></div>
                <div style="font-size:12px; color:#94A3B8; margin-top:2px;">
                  <?= e($w['tahun_mulai']) ?> - <?= $w['tahun_selesai'] ? e($w['tahun_selesai']) : 'Sekarang' ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Riwayat Pendidikan -->
        <div class="tt-career-card">
          <div class="tt-career-title">
            <span>🎓</span> Riwayat Pendidikan
          </div>
          <?php if (empty($educations)): ?>
            <p style="color:#94A3B8; font-size:13.5px; margin:0;">Belum ada riwayat pendidikan lanjutan.</p>
          <?php else: ?>
            <?php foreach ($educations as $ed): ?>
              <div class="tt-career-item">
                <div style="font-size:15px; font-weight:700; color:#FFFFFF;"><?= e($ed['institusi']) ?></div>
                <div style="font-size:13.5px; font-weight:600; color:#38BDF8;">
                  <?= e($ed['jenjang']) ?> <?= e($ed['jurusan']) ?>
                </div>
                <div style="font-size:12px; color:#94A3B8; margin-top:2px;">
                  <?= e($ed['tahun_masuk']) ?> - <?= $ed['tahun_lulus'] ? e($ed['tahun_lulus']) : 'Sekarang' ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>

      <?php if ($isMe): ?>
        <div style="margin-top:16px;">
          <a href="<?= baseUrl('pages/edit_profile.php') ?>" class="btn-tt-gray" style="text-decoration:none;">
            ✏️ Perbarui Informasi Karir & Pendidikan
          </a>
        </div>
      <?php endif; ?>

    </div>

  </main>

</div>

<!-- TOAST ALERT UNTUK COPY LINK -->
<div class="tiktok-toast" id="tt-toast">Tautan profil berhasil disalin!</div>

<script>
// Tab Switching ala TikTok
document.querySelectorAll('.tt-tab-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const targetId = this.getAttribute('data-tab');
    
    document.querySelectorAll('.tt-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tt-tab-pane').forEach(p => p.classList.remove('active'));
    
    this.classList.add('active');
    const pane = document.getElementById(targetId);
    if (pane) pane.classList.add('active');
  });
});

// Follow / Unfollow Toggle
function toggleFollowUser(userId) {
  const btn = document.getElementById('btn-follow-user');
  const statFollowers = document.getElementById('stat-followers');

  fetch('<?= baseUrl('pages/follow_action.php?ajax=1&user_id=') ?>' + userId)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        if (d.following) {
          btn.classList.add('following');
          btn.textContent = 'Mengikuti';
        } else {
          btn.classList.remove('following');
          btn.textContent = 'Ikuti';
        }
        if (statFollowers && typeof d.total_followers !== 'undefined') {
          statFollowers.textContent = d.total_followers;
        }
        showToast(d.following ? 'Berhasil mengikuti @<?= e($userProfile['username']) ?>' : 'Berhenti mengikuti @<?= e($userProfile['username']) ?>');
      }
    })
    .catch(err => console.error(err));
}

// Copy Profile Link
function copyProfileLink() {
  const url = window.location.href;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      showToast('Tautan profil berhasil disalin ke papan klip!');
    });
  } else {
    showToast('Tautan: ' + url);
  }
}

// Show Toast
function showToast(msg) {
  const toast = document.getElementById('tt-toast');
  if (toast) {
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => {
      toast.classList.remove('show');
    }, 2800);
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
