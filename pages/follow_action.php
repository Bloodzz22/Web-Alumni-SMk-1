<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$targetId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$isAjax   = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
            || isset($_GET['ajax']) 
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($targetId <= 0 || $targetId === $userId) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Target pengguna tidak valid.']);
        exit;
    }
    header('Location: ' . baseUrl('pages/directory.php'));
    exit;
}

// Cek apakah sudah follow
$check = $conn->prepare("SELECT id FROM followers WHERE follower_id = ? AND following_id = ?");
$check->bind_param('ii', $userId, $targetId);
$check->execute();
$res = $check->get_result();

if ($res->num_rows > 0) {
    // Unfollow
    $del = $conn->prepare("DELETE FROM followers WHERE follower_id = ? AND following_id = ?");
    $del->bind_param('ii', $userId, $targetId);
    $del->execute();
    $following = false;
    $msg = 'Berhenti mengikuti alumni.';
} else {
    // Follow
    $ins = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?, ?)");
    $ins->bind_param('ii', $userId, $targetId);
    $ins->execute();
    $following = true;
    $msg = 'Berhasil mengikuti alumni.';

    // Kirim notifikasi ke target
    $notifMsg = $_SESSION['nama'] . ' mulai mengikuti Anda.';
    $tipe = 'follow';
    $notif = $conn->prepare("INSERT INTO notifications (user_id, from_user_id, tipe, pesan) VALUES (?, ?, ?, ?)");
    $notif->bind_param('iiss', $targetId, $userId, $tipe, $notifMsg);
    $notif->execute();
}

// Hitung total followers baru
$cStmt = $conn->prepare("SELECT COUNT(*) as total FROM followers WHERE following_id = ?");
$cStmt->bind_param('i', $targetId);
$cStmt->execute();
$totalFollowers = $cStmt->get_result()->fetch_assoc()['total'];

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'        => true,
        'following'      => $following,
        'totalFollowers' => $totalFollowers,
        'message'        => $msg
    ]);
    exit;
}

setFlash('success', $msg);
$referer = $_SERVER['HTTP_REFERER'] ?? baseUrl('pages/directory.php');
header('Location: ' . $referer);
exit;
