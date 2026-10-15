<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $conn->prepare('
            SELECT u.id, u.username, u.password, u.role_id, p.nama
            FROM users u
            LEFT JOIN profiles p ON p.user_id = u.id
            WHERE u.username = ? OR u.email = ?
            LIMIT 1
        ');
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $error = 'Akun tidak ditemukan.';
        } else {
            $u = $res->fetch_assoc();
            if (password_verify($password, $u['password'])) {
                $_SESSION['user_id']  = $u['id'];
                $_SESSION['username'] = $u['username'];
                $_SESSION['nama']     = $u['nama'] ?? $u['username'];
                $_SESSION['role_id']  = $u['role_id'];

                header('Location: ' . baseUrl('pages/feed.php'));
                exit;
            } else {
                $error = 'Password salah.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk - ALUMNICONNECT</title>
  <link rel="stylesheet" href="<?= baseUrl('assets/css/style.css') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    
<div class="auth-wrapper">
  <div class="auth-box">
    <div class="auth-header" style="position:relative;">
      <a href="<?= baseUrl('index.php') ?>" 
         style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#94A3B8;font-size:13px;text-decoration:none;">
        ←
      </a>
      <h1>ALUMNI<em>CONNECT</em></h1>
    </div>
    <div class="auth-tabs">
      <span class="auth-tab active">Login</span>
      <a href="<?= baseUrl('auth/register.php') ?>" class="auth-tab">Register</a>
    </div>

    <div class="auth-body">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label">Email atau Username</label>
          <input type="text" name="username" class="form-input" placeholder="email@contoh.com" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-input" placeholder="••••••" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Masuk</button>
      </form>

      <!-- Quick Demo Accounts Helper -->
      <div style="margin-top:20px; padding:12px; background:#F8FAFC; border:1px dashed #CBD5E1; border-radius:12px; font-size:11px;">
        <div style="font-weight:700; color:var(--navy); margin-bottom:6px;">⚡ Akun Demo Siap Uji (Password: password123):</div>
        <div style="display:flex; flex-direction:column; gap:4px; color:var(--text-muted);">
          <div>• <strong>Admin:</strong> <code>admin</code></div>
          <div>• <strong>Guru:</strong> <code>guru_budi</code></div>
          <div>• <strong>Siswa:</strong> <code>siswa_andi</code></div>
          <div>• <strong>Alumni:</strong> <code>alum_sarah</code></div>
        </div>
      </div>
    </div>

    <div class="auth-footer">
      Belum punya akun? <a href="<?= baseUrl('auth/register.php') ?>">Daftar di sini</a>
    </div>
  </div>
</div>

</body>
</html>