<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Tandai semua dibaca jika diminta
if (isset($_GET['action']) && $_GET['action'] === 'read_all') {
    $mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $mark->bind_param('i', $userId);
    $mark->execute();
    setFlash('success', 'Semua notifikasi telah ditandai sudah dibaca.');
    header('Location: ' . baseUrl('pages/notifications.php'));
    exit;
}

// Ambil notifikasi pengguna
$stmt = $conn->prepare("
    SELECT 
        n.id, n.tipe, n.pesan, n.is_read, n.created_at,
        u.id AS from_user_id, u.username,
        p.nama, p.foto
    FROM notifications n
    LEFT JOIN users u ON u.id = n.from_user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 50
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Tandai dibaca
$markRead = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$markRead->bind_param('i', $userId);
$markRead->execute();

$pageTitle = 'Notifikasi';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:700px; margin-top:24px;">

  <div class="card" style="margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <div>
        <h2 style="font-size:18px;font-weight:800;color:var(--navy);">🔔 Notifikasi</h2>
        <p style="font-size:12px;color:var(--text-muted);margin-top:2px;">
          Pemberitahuan interaksi, koneksi baru, dan pengumuman sekolah.
        </p>
      </div>
      <?php if (!empty($notifications)): ?>
        <a href="<?= baseUrl('pages/notifications.php?action=read_all') ?>" class="btn btn-outline btn-sm">
          ✓ Tandai Semua Dibaca
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="card text-center" style="padding:40px 20px;">
      <div style="font-size:36px;margin-bottom:8px;">🔔</div>
      <h3 style="font-size:15px;font-weight:700;color:var(--navy);">Belum ada notifikasi baru</h3>
      <p style="font-size:13px;color:var(--text-muted);margin-top:4px;">
        Aktivitas seperti follow, like, dan komentar akan muncul di sini.
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($notifications as $n): ?>
      <?php 
        $icon = '🔔';
        if ($n['tipe'] === 'follow')  $icon = '👤';
        if ($n['tipe'] === 'like')    $icon = '❤️';
        if ($n['tipe'] === 'comment') $icon = '💬';
        if ($n['tipe'] === 'system' || $n['tipe'] === 'announcement') $icon = '📢';
      ?>
      <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>">
        <div class="avatar" style="width:42px;height:42px;font-size:16px;">
          <?= $n['from_user_id'] ? initials($n['nama'] ?? $n['username']) : $icon ?>
        </div>

        <div class="notif-text">
          <div>
            <?php if ($n['from_user_id']): ?>
              <a href="<?= baseUrl('pages/profile.php?id=' . $n['from_user_id']) ?>" style="font-weight:700;color:var(--navy);">
                <?= e($n['nama'] ?? $n['username']) ?>
              </a>
            <?php endif; ?>
            <span><?= e($n['pesan']) ?></span>
          </div>
          <div class="notif-time">
            🕒 <?= timeAgo($n['created_at']) ?>
          </div>
        </div>

        <div>
          <?php if ($n['from_user_id']): ?>
            <a href="<?= baseUrl('pages/profile.php?id=' . $n['from_user_id']) ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:11px;">
              Lihat
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
