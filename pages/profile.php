<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : $currentUserId;

// Ambil info user & profil
$stmt = $conn->prepare("
    SELECT 
        u.id, u.username, u.email, u.role_id, r.nama_role,
        p.nama, p.foto, p.bio,
        ap.angkatan, ap.tahun_lulus, ap.jurusan, ap.profesi, ap.perusahaan,
        (SELECT COUNT(*) FROM followers WHERE following_id = u.id) AS total_followers,
        (SELECT COUNT(*) FROM followers WHERE follower_id = u.id) AS total_following,
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

// Ambil riwayat pengalaman kerja (alumni_work)
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

// Ambil riwayat pendidikan (alumni_education)
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

// Ambil postingan user ini
$postStmt = $conn->prepare("
    SELECT 
        p.id, p.konten, p.media, p.created_at,
        c.nama_kategori,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS total_like,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS total_comment,
        (SELECT COUNT(*) FROM shares WHERE post_id = p.id) AS total_share,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) AS is_liked
    FROM posts p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
$postStmt->bind_param('ii', $currentUserId, $profileUserId);
$postStmt->execute();
$posts = $postStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = $userProfile['nama'] ?? $userProfile['username'];
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:850px; margin-top:20px;">

  <!-- Header Profil Sesuai Mockup Halaman 2 -->
  <div class="profile-box">
    <div class="profile-cover"></div>
    <div class="profile-content">
      <?= renderAvatar($userProfile['nama'] ?? $userProfile['username'], $userProfile['foto'] ?? null, 'avatar-lg') ?>

      <h1 class="profile-name"><?= e($userProfile['nama'] ?? $userProfile['username']) ?></h1>

      <div class="profile-headline">
        <?php if (!empty($userProfile['angkatan'])): ?>
          Angkatan <?= e($userProfile['angkatan']) ?> · 
        <?php endif; ?>
        <?php if (!empty($userProfile['jurusan'])): ?>
          <?= e($userProfile['jurusan']) ?> · 
        <?php endif; ?>
        <strong style="color:var(--text);"><?= e($userProfile['profesi'] ?? ucfirst($userProfile['nama_role'])) ?></strong>
        <?php if (!empty($userProfile['perusahaan'])): ?>
          di <em><?= e($userProfile['perusahaan']) ?></em>
        <?php endif; ?>
      </div>

      <p class="profile-bio">
        <?= e($userProfile['bio'] ?: 'Belum ada biodata yang dituliskan.') ?>
      </p>

      <div class="profile-counts">
        <div><strong><?= (int)$userProfile['total_followers'] ?></strong> <span class="text-muted">follower</span></div>
        <div><strong><?= (int)$userProfile['total_following'] ?></strong> <span class="text-muted">following</span></div>
        <div><strong><?= count($posts) ?></strong> <span class="text-muted">postingan</span></div>
      </div>

      <div class="profile-btns">
        <?php if ($isMe): ?>
          <a href="<?= baseUrl('pages/edit_profile.php') ?>" class="btn btn-outline btn-sm">
            ✏️ Edit Profil
          </a>
          <?php if ((int)$_SESSION['role_id'] === 1): ?>
            <a href="<?= baseUrl('admin/dashboard.php') ?>" class="btn btn-cyan btn-sm">
              ⚙️ Dashboard Admin
            </a>
          <?php endif; ?>
        <?php else: ?>
          <button class="btn <?= $userProfile['is_following'] ? 'btn-outline' : 'btn-primary' ?>"
                  onclick="toggleFollowProfile(<?= $userProfile['id'] ?>, this)">
            <?= $userProfile['is_following'] ? '✓ Following' : 'Follow' ?>
          </button>
          <a href="<?= baseUrl('pages/messages.php?to=' . $userProfile['id']) ?>" class="btn btn-outline">
            💬 Kirim Pesan
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr; gap:20px;">

    <!-- Pengalaman Kerja & Pendidikan (Khusus Profil Alumni) -->
    <?php if (!empty($works) || !empty($educations)): ?>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
        
        <!-- Riwayat Kerja -->
        <div class="timeline-card">
          <div class="card-header">
            <span class="card-title">💼 Pengalaman Kerja</span>
          </div>
          <?php if (empty($works)): ?>
            <p class="text-muted" style="font-size:13px;">Belum ada pengalaman kerja terdaftar.</p>
          <?php else: ?>
            <?php foreach ($works as $w): ?>
              <div class="timeline-item">
                <div class="timeline-title"><?= e($w['posisi']) ?></div>
                <div style="font-size:13px;font-weight:600;color:var(--primary);"><?= e($w['perusahaan']) ?></div>
                <div class="timeline-sub">
                  <?= e($w['tahun_mulai']) ?> - <?= $w['tahun_selesai'] ? e($w['tahun_selesai']) : 'Sekarang' ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Riwayat Pendidikan -->
        <div class="timeline-card">
          <div class="card-header">
            <span class="card-title">🎓 Riwayat Pendidikan</span>
          </div>
          <?php if (empty($educations)): ?>
            <p class="text-muted" style="font-size:13px;">Belum ada riwayat pendidikan lanjutan.</p>
          <?php else: ?>
            <?php foreach ($educations as $ed): ?>
              <div class="timeline-item">
                <div class="timeline-title"><?= e($ed['institusi']) ?></div>
                <div style="font-size:13px;font-weight:600;color:var(--primary);">
                  <?= e($ed['jenjang']) ?> <?= e($ed['jurusan']) ?>
                </div>
                <div class="timeline-sub">
                  <?= e($ed['tahun_masuk']) ?> - <?= $ed['tahun_lulus'] ? e($ed['tahun_lulus']) : 'Sekarang' ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    <?php endif; ?>

    <!-- Daftar Postingan Alumni (Sesuai Mockup Halaman 2) -->
    <div>
      <h3 style="font-size:16px;font-weight:700;color:var(--navy);margin-bottom:14px;">
        📝 Postingan <?= e($userProfile['nama'] ?? $userProfile['username']) ?>
      </h3>

      <?php if (empty($posts)): ?>
        <div class="card text-center text-muted" style="padding:30px;">
          Belum ada postingan yang dibagikan.
        </div>
      <?php else: ?>
        <?php foreach ($posts as $p): ?>
          <article class="post-card">
            <div class="post-head">
              <?= renderAvatar($userProfile['nama'] ?? $userProfile['username'], $userProfile['foto'] ?? null) ?>
              <div style="flex:1;">
                <div class="post-author"><?= e($userProfile['nama'] ?? $userProfile['username']) ?></div>
                <div class="post-meta">
                  <?= timeAgo($p['created_at']) ?>
                  <?php if (!empty($p['nama_kategori'])): ?> · <span class="post-badge"><?= e($p['nama_kategori']) ?></span><?php endif; ?>
                </div>
              </div>

              <?php if ($isMe || (int)$_SESSION['role_id'] === 1): ?>
                <a href="<?= baseUrl('pages/post_action.php?action=delete&post_id=' . $p['id']) ?>" 
                   class="btn-outline btn-sm" 
                   onclick="return confirm('Hapus postingan ini?')"
                   style="color:#EF4444;border-color:#FCA5A5;padding:4px 8px;">
                  🗑️
                </a>
              <?php endif; ?>
            </div>

            <div class="post-content"><?= nl2br(e($p['konten'])) ?></div>

            <div class="post-actions">
              <button class="post-action <?= $p['is_liked'] ? 'liked' : '' ?>"
                      onclick="toggleLike(<?= $p['id'] ?>, this)">
                ❤️ <span class="like-count"><?= $p['total_like'] ?></span>
              </button>
              <button class="post-action">💬 <?= $p['total_comment'] ?></button>
              <button class="post-action" onclick="sharePost(<?= $p['id'] ?>)">
                🔗 <?= $p['total_share'] ?>
              </button>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

</div>

<script>
function toggleFollowProfile(userId, btn) {
  fetch('<?= baseUrl('pages/follow_action.php?ajax=1&user_id=') ?>' + userId)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        if (d.following) {
          btn.className = 'btn btn-outline';
          btn.textContent = '✓ Following';
        } else {
          btn.className = 'btn btn-primary';
          btn.textContent = 'Follow';
        }
        location.reload();
      }
    });
}

function toggleLike(id, btn) {
  fetch('<?= baseUrl('pages/post_action.php?action=like&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        btn.classList.toggle('liked');
        btn.querySelector('.like-count').textContent = d.total;
      }
    });
}

function sharePost(id) {
  fetch('<?= baseUrl('pages/post_action.php?action=share&post_id=') ?>' + id, { method: 'POST' })
    .then(r => r.json())
    .then(d => { if (d.success) alert('Postingan berhasil dibagikan!'); });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
