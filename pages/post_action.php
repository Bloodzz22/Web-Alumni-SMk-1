<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// ============================================================
// 1. CREATE POST (TikTok-Style Media, Hashtag, Tag Teman)
// ============================================================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $konten      = trim($_POST['konten'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $mediaPath   = null;

    // Handle Upload Media (Foto atau Video)
    if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
        $fileTmp  = $_FILES['media']['tmp_name'];
        $fileName = $_FILES['media']['name'];
        $fileSize = $_FILES['media']['size'];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedImg   = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $allowedVideo = ['mp4', 'webm', 'mov', 'ogg', 'm4v'];
        $allowedAll   = array_merge($allowedImg, $allowedVideo);

        if (in_array($ext, $allowedAll)) {
            $uploadDir = __DIR__ . '/../assets/uploads/posts/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $prefix      = in_array($ext, $allowedVideo) ? 'vid_' : 'img_';
            $newFileName = $prefix . $userId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $destPath    = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmp, $destPath)) {
                $mediaPath = 'assets/uploads/posts/' . $newFileName;
            }
        }
    }

    if ($konten !== '' || $mediaPath !== null) {
        $stmt = $conn->prepare("INSERT INTO posts (user_id, category_id, konten, media) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiss', $userId, $category_id, $konten, $mediaPath);
        $stmt->execute();
        $postId = $conn->insert_id;

        // Auto extract hashtags (#PPLG, #Prestasi, dll)
        if (preg_match_all('/#([a-zA-Z0-9_]+)/', $konten, $matches)) {
            $tags = array_unique($matches[1]);
            foreach ($tags as $tag) {
                $tagClean = strtolower(trim($tag));
                $st = $conn->prepare("SELECT id FROM hashtags WHERE tag = ?");
                $st->bind_param('s', $tagClean);
                $st->execute();
                $res = $st->get_result();
                if ($res->num_rows > 0) {
                    $tagId = $res->fetch_assoc()['id'];
                } else {
                    $ins = $conn->prepare("INSERT INTO hashtags (tag) VALUES (?)");
                    $ins->bind_param('s', $tagClean);
                    $ins->execute();
                    $tagId = $conn->insert_id;
                }
                $link = $conn->prepare("INSERT IGNORE INTO post_hashtags (post_id, hashtag_id) VALUES (?, ?)");
                $link->bind_param('ii', $postId, $tagId);
                $link->execute();
            }
        }

        // ============================================================
        // PROSES TAG TEMAN (Tersimpan & Otomatis Muncul di Pesan)
        // ============================================================
        $taggedUserIds = [];

        // Dari select form "tag_users[]"
        if (!empty($_POST['tag_users']) && is_array($_POST['tag_users'])) {
            foreach ($_POST['tag_users'] as $tid) {
                $tid = (int)$tid;
                if ($tid > 0 && $tid !== $userId) {
                    $taggedUserIds[] = $tid;
                }
            }
        }

        // Dari caption @username
        if (preg_match_all('/@([a-zA-Z0-9_]+)/', $konten, $atMatches)) {
            $usernames = array_unique($atMatches[1]);
            foreach ($usernames as $un) {
                $unStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $unStmt->bind_param('s', $un);
                $unStmt->execute();
                $unRow = $unStmt->get_result()->fetch_assoc();
                if ($unRow && (int)$unRow['id'] !== $userId) {
                    $taggedUserIds[] = (int)$unRow['id'];
                }
            }
        }

        $taggedUserIds = array_unique($taggedUserIds);
        $captionPreview = mb_substr($konten ?: 'Sebuah foto/video baru', 0, 80);

        foreach ($taggedUserIds as $taggedId) {
            // 1. Simpan ke tabel post_mentions
            $pm = $conn->prepare("INSERT IGNORE INTO post_mentions (post_id, user_id) VALUES (?, ?)");
            $pm->bind_param('ii', $postId, $taggedId);
            $pm->execute();

            // 2. Kirim Notifikasi
            $notifMsg = $_SESSION['nama'] . ' menandai Anda dalam sebuah postingan: "' . $captionPreview . '..."';
            $tipe = 'system';
            $nIns = $conn->prepare("INSERT INTO notifications (user_id, from_user_id, tipe, pesan) VALUES (?, ?, ?, ?)");
            $nIns->bind_param('iiss', $taggedId, $userId, $tipe, $notifMsg);
            $nIns->execute();

            // 3. Kirim Otomatis ke PESAN (Direct Message) sesuai permintaan!
            $postUrl = baseUrl('pages/feed.php#post-' . $postId);
            $msgContent = "👋 Halo! Saya menandai Anda dalam sebuah postingan baru:\n\n\"" . $captionPreview . "\"\n\n🔗 Buka postingan: " . $postUrl;
            $mIns = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, isi) VALUES (?, ?, ?)");
            $mIns->bind_param('iis', $userId, $taggedId, $msgContent);
            $mIns->execute();
        }

        $flashMsg = 'Postingan berhasil diterbitkan!';
        if (count($taggedUserIds) > 0) {
            $flashMsg .= ' ' . count($taggedUserIds) . ' teman yang ditandai telah menerima pemberitahuan di Pesan.';
        }
        setFlash('success', $flashMsg);
    }
    header('Location: ' . baseUrl('pages/feed.php#post-' . ($postId ?? '')));
    exit;
}

// ============================================================
// 2. TOGGLE LIKE (AJAX)
// ============================================================
if ($action === 'like') {
    header('Content-Type: application/json');
    $postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);

    if ($postId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Post ID']);
        exit;
    }

    // Cek apakah sudah di-like
    $check = $conn->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
    $check->bind_param('ii', $postId, $userId);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        // Unlike
        $del = $conn->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
        $del->bind_param('ii', $postId, $userId);
        $del->execute();
        $isLiked = false;
    } else {
        // Like
        $ins = $conn->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?)");
        $ins->bind_param('ii', $postId, $userId);
        $ins->execute();
        $isLiked = true;

        // Kirim notifikasi ke pemilik post
        $ownerQuery = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
        $ownerQuery->bind_param('i', $postId);
        $ownerQuery->execute();
        $owner = $ownerQuery->get_result()->fetch_assoc();
        if ($owner && (int)$owner['user_id'] !== $userId) {
            $msg = $_SESSION['nama'] . ' menyukai postingan Anda.';
            $tipe = 'like';
            $notif = $conn->prepare("INSERT INTO notifications (user_id, from_user_id, tipe, pesan) VALUES (?, ?, ?, ?)");
            $notif->bind_param('iiss', $owner['user_id'], $userId, $tipe, $msg);
            $notif->execute();
        }
    }

    // Hitung total like baru
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM likes WHERE post_id = ?");
    $countStmt->bind_param('i', $postId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];

    echo json_encode(['success' => true, 'liked' => $isLiked, 'total' => $total]);
    exit;
}

// ============================================================
// 3. SHARE POST (AJAX / POST)
// ============================================================
if ($action === 'share') {
    header('Content-Type: application/json');
    $postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);

    if ($postId > 0) {
        $ins = $conn->prepare("INSERT INTO shares (post_id, user_id) VALUES (?, ?)");
        $ins->bind_param('ii', $postId, $userId);
        $ins->execute();

        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM shares WHERE post_id = ?");
        $countStmt->bind_param('i', $postId);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        echo json_encode(['success' => true, 'total' => $total]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

// ============================================================
// 4. ADD COMMENT
// ============================================================
if ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = (int)($_POST['post_id'] ?? 0);
    $isi    = trim($_POST['isi'] ?? '');

    if ($postId > 0 && $isi !== '') {
        $stmt = $conn->prepare("INSERT INTO comments (post_id, user_id, isi) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $postId, $userId, $isi);
        $stmt->execute();

        // Notifikasi ke pemilik post
        $ownerQuery = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
        $ownerQuery->bind_param('i', $postId);
        $ownerQuery->execute();
        $owner = $ownerQuery->get_result()->fetch_assoc();
        if ($owner && (int)$owner['user_id'] !== $userId) {
            $msg = $_SESSION['nama'] . ' mengomentari postingan Anda: "' . mb_substr($isi, 0, 30) . '..."';
            $tipe = 'comment';
            $notif = $conn->prepare("INSERT INTO notifications (user_id, from_user_id, tipe, pesan) VALUES (?, ?, ?, ?)");
            $notif->bind_param('iiss', $owner['user_id'], $userId, $tipe, $msg);
            $notif->execute();
        }
        setFlash('success', 'Komentar terkirim.');
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? baseUrl('pages/feed.php');
    header('Location: ' . $referer);
    exit;
}

// ============================================================
// 5. DELETE POST
// ============================================================
if ($action === 'delete') {
    $postId = (int)($_GET['post_id'] ?? 0);
    if ($postId > 0) {
        // Cek pemilik atau admin (role 1)
        $isAdmin = ((int)($_SESSION['role_id'] ?? 0) === 1);
        if ($isAdmin) {
            $del = $conn->prepare("DELETE FROM posts WHERE id = ?");
            $del->bind_param('i', $postId);
            $del->execute();
            setFlash('success', 'Postingan berhasil dihapus oleh Admin.');
        } else {
            $del = $conn->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
            $del->bind_param('ii', $postId, $userId);
            $del->execute();
            if ($del->affected_rows > 0) {
                setFlash('success', 'Postingan berhasil dihapus.');
            } else {
                setFlash('danger', 'Kamu tidak memiliki izin menghapus postingan ini.');
            }
        }
    }
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

header('Location: ' . baseUrl('pages/feed.php'));
exit;
