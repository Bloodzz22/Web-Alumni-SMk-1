<?php
// ============================================================
// FUNGSI BANTU - ALUMNICONNECT
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . baseUrl('auth/login.php'));
        exit;
    }
}

function baseUrl($path = '') {
    $base = '/alumniconnect'; // Sesuaikan dengan nama folder di Laragon
    return $base . '/' . ltrim($path, '/');
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= strtoupper(substr($p, 0, 1));
    }
    return $ini ?: '?';
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60)       return 'baru saja';
    if ($diff < 3600)     return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400)    return floor($diff / 3600) . ' jam lalu';
    if ($diff < 2592000)  return floor($diff / 86400) . ' hari lalu';
    if ($diff < 31536000) return floor($diff / 2592000) . ' bulan lalu';
    return floor($diff / 31536000) . ' tahun lalu';
}

function setFlash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function renderAvatar($name, $foto = null, $extraClass = '', $extraStyle = '') {
    if (!empty($foto)) {
        $filePath = __DIR__ . '/../' . ltrim($foto, '/\\');
        if (file_exists($filePath)) {
            return '<img src="' . baseUrl($foto) . '" class="avatar ' . e($extraClass) . '" style="object-fit:cover;border-radius:50%;' . e($extraStyle) . '" alt="' . e($name) . '">';
        }
    }
    return '<div class="avatar ' . e($extraClass) . '" style="' . e($extraStyle) . '">' . initials($name) . '</div>';
}