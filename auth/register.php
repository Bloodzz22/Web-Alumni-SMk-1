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
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $nama     = trim($_POST['nama'] ?? '');
    $role     = (int)($_POST['role'] ?? 4);

    if ($username === '' || $email === '' || $password === '' || $nama === '') {
        $error = 'Semua kolom wajib diisi.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Username atau email sudah digunakan.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('INSERT INTO users (username, email, password, role_id) VALUES (?,?,?,?)');
                $stmt->bind_param('sssi', $username, $email, $hash, $role);
                $stmt->execute();
                $userId = $conn->insert_id;

                $stmt = $conn->prepare('INSERT INTO profiles (user_id, nama, bio) VALUES (?,?,?)');
                $bio = '';
                $stmt->bind_param('iss', $userId, $nama, $bio);
                $stmt->execute();

                if ($role === 4) {
                    $defAngkatan = date('Y');
                    $defJurusan  = 'PPLG';
                    $defProfesi  = 'Alumni';
                    $stmtAlum = $conn->prepare('INSERT INTO alumni_profiles (user_id, angkatan, jurusan, profesi) VALUES (?,?,?,?)');
                    $stmtAlum->bind_param('isss', $userId, $defAngkatan, $defJurusan, $defProfesi);
                    $stmtAlum->execute();
                }

                $conn->commit();

                setFlash('success', 'Registrasi berhasil! Silakan masuk.');
                header('Location: ' . baseUrl('auth/login.php'));
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Terjadi kesalahan: ' . $e->getMessage();
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
  <title>Daftar - ALUMNICONNECT</title>
  <link rel="stylesheet" href="<?= baseUrl('assets/css/style.css') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

<div class="auth-wrapper">
  <div class="auth-box">
    <div class="auth-header" style="position:relative;">
      <a href="<?= baseUrl('index.php') ?>" 
         style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#94A3B8;font-size:16px;text-decoration:none;">
        ←
      </a>
      <h1>ALUMNI<em>CONNECT</em></h1>
    </div>

    <div class="auth-tabs">
      <a href="<?= baseUrl('auth/login.php') ?>" class="auth-tab">Login</a>
      <span class="auth-tab active">Register</span>
    </div>

    <div class="auth-body">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label">Nama Lengkap</label>
          <input type="text" name="nama" class="form-input" placeholder="Nama lengkap" required>
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-input" placeholder="username" required>
        </div>

        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-input" placeholder="email@contoh.com" required>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-input" placeholder="Min. 6 karakter" required>
        </div>

        <div class="form-group">
          <label class="form-label">Daftar Sebagai</label>
          <select name="role" class="form-select">
            <option value="4">Alumni</option>
            <option value="3">Siswa</option>
            <option value="2">Guru</option>
          </select>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Daftar</button>
      </form>
    </div>

    <div class="auth-footer">
      Sudah punya akun? <a href="<?= baseUrl('auth/login.php') ?>">Masuk di sini</a>
    </div>
  </div>
</div>

</body>
</html>