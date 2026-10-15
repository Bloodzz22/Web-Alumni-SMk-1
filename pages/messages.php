<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];
$toId   = isset($_GET['to']) ? (int)$_GET['to'] : 0;

// Kirim pesan baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $toId > 0) {
    $isi = trim($_POST['isi'] ?? '');
    if ($isi !== '' && $toId !== $userId) {
        $ins = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, isi) VALUES (?, ?, ?)");
        $ins->bind_param('iis', $userId, $toId, $isi);
        $ins->execute();

        // Notifikasi ke penerima pesan
        $msgNotif = $_SESSION['nama'] . ' mengirimi Anda pesan baru: "' . mb_substr($isi, 0, 30) . '..."';
        $tipe = 'system';
        $nStmt = $conn->prepare("INSERT INTO notifications (user_id, from_user_id, tipe, pesan) VALUES (?, ?, ?, ?)");
        $nStmt->bind_param('iiss', $toId, $userId, $tipe, $msgNotif);
        $nStmt->execute();

        header('Location: ' . baseUrl('pages/messages.php?to=' . $toId));
        exit;
    }
}

// Ambil daftar riwayat kontak yang pernah saling kirim pesan atau difollow
$contactsSql = "
    SELECT DISTINCT
        u.id, u.username,
        p.nama, p.foto,
        ap.profesi, ap.angkatan,
        (SELECT isi FROM messages 
         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
         ORDER BY created_at DESC LIMIT 1) AS last_message,
        (SELECT created_at FROM messages 
         WHERE (sender_id = u.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.id)
         ORDER BY created_at DESC LIMIT 1) AS last_time
    FROM users u
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id <> ?
      AND (
          u.id IN (SELECT sender_id FROM messages WHERE receiver_id = ?)
          OR u.id IN (SELECT receiver_id FROM messages WHERE sender_id = ?)
          OR u.id IN (SELECT following_id FROM followers WHERE follower_id = ?)
          OR u.id = ?
      )
    ORDER BY (last_time IS NOT NULL) DESC, last_time DESC, p.nama ASC
";
$cStmt = $conn->prepare($contactsSql);
$cStmt->bind_param('iiiiiiiii', $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $toId);
$cStmt->execute();
$contacts = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Jika tidak ada toId tapi ada kontak, default ke kontak pertama
if ($toId === 0 && !empty($contacts)) {
    $toId = (int)$contacts[0]['id'];
}

// Ambil info lawan bicara
$targetUser = null;
$messages = [];
if ($toId > 0) {
    $tStmt = $conn->prepare("
        SELECT u.id, u.username, p.nama, p.foto, ap.profesi, ap.angkatan
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
        WHERE u.id = ?
    ");
    $tStmt->bind_param('i', $toId);
    $tStmt->execute();
    $targetUser = $tStmt->get_result()->fetch_assoc();

    if ($targetUser) {
        // Ambil riwayat chat
        $mStmt = $conn->prepare("
            SELECT m.*, u.username
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $mStmt->bind_param('iiii', $userId, $toId, $toId, $userId);
        $mStmt->execute();
        $messages = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Tandai pesan sudah dibaca
        $up = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $up->bind_param('ii', $toId, $userId);
        $up->execute();
    }
}

$pageTitle = 'Pesan & Networking';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:1000px; margin-top:20px;">

  <div class="card" style="margin-bottom:14px; padding:12px 18px;">
    <h2 style="font-size:18px;font-weight:800;color:var(--navy);">💬 Networking & Pesan Langsung</h2>
    <p style="font-size:12px;color:var(--text-muted);">Hubungi alumni untuk konsultasi karier, berbagi informasi pekerjaan, atau kolaborasi almamater.</p>
  </div>

  <div class="chat-wrapper">

    <!-- Sidebar Kontak -->
    <div class="chat-sidebar">
      <div style="padding:14px; font-weight:700; border-bottom:1px solid var(--border); font-size:13px; color:var(--navy);">
        Daftar Kontak Alumni
      </div>
      <div style="overflow-y:auto; max-height:480px;">
        <?php if (empty($contacts)): ?>
          <div style="padding:20px; text-align:center; color:var(--text-muted); font-size:13px;">
            Belum ada obrolan.<br>Cari alumni di <a href="<?= baseUrl('pages/directory.php') ?>" style="color:var(--primary);font-weight:600;">Direktori</a> untuk memulai percakapan.
          </div>
        <?php else: ?>
          <?php foreach ($contacts as $c): ?>
            <a href="<?= baseUrl('pages/messages.php?to=' . $c['id']) ?>" 
               class="chat-user-item <?= ((int)$c['id'] === $toId) ? 'active' : '' ?>">
              <?= renderAvatar($c['nama'] ?? $c['username'], $c['foto'] ?? null, 'avatar-sm') ?>
              <div style="flex:1; min-width:0;">
                <div style="font-weight:700; font-size:13px; color:var(--navy); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                  <?= e($c['nama'] ?? $c['username']) ?>
                </div>
                <div style="font-size:11px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                  <?= e($c['last_message'] ?: ($c['profesi'] ?? 'Alumni')) ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Area Obrolan Chat -->
    <div class="chat-main">
      <?php if (!$targetUser): ?>
        <div style="flex:1; display:flex; align-items:center; justify-content:center; flex-direction:column; padding:40px; color:var(--text-muted);">
          <div style="font-size:48px; margin-bottom:12px;">💬</div>
          <div style="font-size:16px; font-weight:700; color:var(--navy);">Pilih kontak untuk mulai berkirim pesan</div>
          <p style="font-size:13px; margin-top:4px;">Atau cari profil alumni di direktori untuk menghubungi senior dan teman seangkatan.</p>
        </div>
      <?php else: ?>
        <!-- Header Target Chat -->
        <div style="padding:14px 18px; border-bottom:1px solid var(--border); background:white; display:flex; justify-content:space-between; align-items:center;">
          <div style="display:flex; align-items:center; gap:12px;">
            <?= renderAvatar($targetUser['nama'] ?? $targetUser['username'], $targetUser['foto'] ?? null, 'avatar-sm') ?>
            <div>
              <a href="<?= baseUrl('pages/profile.php?id=' . $targetUser['id']) ?>" style="font-weight:700; font-size:14px; color:var(--navy);">
                <?= e($targetUser['nama'] ?? $targetUser['username']) ?>
              </a>
              <div style="font-size:11px; color:var(--text-muted);">
                <?= e($targetUser['profesi'] ?? 'Alumni') ?> · Angkatan <?= e($targetUser['angkatan'] ?? '-') ?>
              </div>
            </div>
          </div>
          <a href="<?= baseUrl('pages/profile.php?id=' . $targetUser['id']) ?>" class="btn btn-outline btn-sm">Lihat Profil</a>
        </div>

        <!-- Daftar Pesan -->
        <div class="chat-messages" id="chatContainer">
          <?php if (empty($messages)): ?>
            <div style="text-align:center; color:var(--text-muted); font-size:13px; margin:auto;">
              Belum ada pesan dengan <?= e($targetUser['nama'] ?? $targetUser['username']) ?>.<br>Kirimkan sapaan pertamamu di bawah! 👋
            </div>
          <?php else: ?>
            <?php foreach ($messages as $m): ?>
              <?php $isSentByMe = ((int)$m['sender_id'] === $userId); ?>
              <div class="chat-bubble <?= $isSentByMe ? 'sent' : 'received' ?>">
                <div><?= nl2br(e($m['isi'])) ?></div>
                <div style="font-size:10px; margin-top:4px; opacity:0.8; text-align:right;">
                  <?= date('H:i', strtotime($m['created_at'])) ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Form Kirim Pesan -->
        <form method="POST" action="<?= baseUrl('pages/messages.php?to=' . $toId) ?>" class="chat-input-area">
          <input type="text" name="isi" class="form-input" placeholder="Tulis pesan ke <?= e($targetUser['nama'] ?? $targetUser['username']) ?>..." autocomplete="off" required>
          <button type="submit" class="btn btn-primary btn-sm" style="padding:0 20px;">Kirim</button>
        </form>
      <?php endif; ?>
    </div>

  </div>

</div>

<script>
window.onload = function() {
  const box = document.getElementById('chatContainer');
  if (box) {
    box.scrollTop = box.scrollHeight;
  }
};
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
