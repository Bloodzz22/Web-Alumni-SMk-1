<?php
require_once __DIR__ . '/functions.php';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>ALUMNICONNECT</title>
  <link rel="stylesheet" href="<?= baseUrl('assets/css/style.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

<nav class="navbar">
  <div class="navbar-inner">
    <a href="<?= baseUrl('index.php') ?>" class="navbar-brand">
      <span class="dot"></span>
      ALUMNI<em>CONNECT</em>
    </a>

    <button class="hamburger" aria-label="Menu">☰</button>

    <div class="navbar-menu">
      <?php if (isLoggedIn()): ?>
        <a href="<?= baseUrl('pages/feed.php') ?>" class="<?= $currentPage==='feed'?'active':'' ?>">Feed</a>
        <a href="<?= baseUrl('pages/create_post.php') ?>" class="nav-post-btn <?= $currentPage==='create_post'?'active':'' ?>">➕ Posting</a>
        <a href="<?= baseUrl('pages/directory.php') ?>" class="<?= $currentPage==='directory'?'active':'' ?>">Direktori</a>
        <a href="<?= baseUrl('pages/messages.php') ?>" class="<?= $currentPage==='messages'?'active':'' ?>">Pesan</a>
        <a href="<?= baseUrl('pages/notifications.php') ?>" class="<?= $currentPage==='notifications'?'active':'' ?>">Notifikasi</a>
        <a href="<?= baseUrl('pages/profile.php') ?>" class="<?= $currentPage==='profile'?'active':'' ?>">Profil</a>
        <?php if (!empty($_SESSION['role_id']) && ((int)$_SESSION['role_id'] === 1 || (int)$_SESSION['role_id'] === 2)): ?>
          <a href="<?= baseUrl('admin/dashboard.php') ?>" class="<?= $currentPage==='dashboard'?'active':'' ?>" style="color:var(--orange);">Dashboard</a>
        <?php endif; ?>
        <a href="<?= baseUrl('auth/logout.php') ?>" class="btn-primary" onclick="return confirmLogout()">Keluar</a>
      <?php else: ?>
        <a href="<?= baseUrl('auth/login.php') ?>">Masuk</a>
        <a href="<?= baseUrl('auth/register.php') ?>" class="btn-primary">Daftar</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main>
  <div class="container">
    <?php if ($flash = getFlash()): ?>
      <div class="alert alert-<?= e($flash['type']) ?>" style="margin-top:16px;">
        <?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>
  </div>