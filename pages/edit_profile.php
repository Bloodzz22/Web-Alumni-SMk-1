<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];
$error = '';
$success = '';

// Ambil data user saat ini
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.email, u.password, u.role_id,
           p.nama, p.bio, p.foto,
           ap.angkatan, ap.jurusan, ap.profesi, ap.perusahaan
    FROM users u
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama            = trim($_POST['nama'] ?? '');
    $username        = trim($_POST['username'] ?? '');
    $bio             = trim($_POST['bio'] ?? '');
    $angkatan        = trim($_POST['angkatan'] ?? '');
    $jurusan         = trim($_POST['jurusan'] ?? '');
    $profesi         = trim($_POST['profesi'] ?? '');
    $perusahaan      = trim($_POST['perusahaan'] ?? '');
    $new_password    = $_POST['new_password'] ?? '';
    $confirm_password= $_POST['confirm_password'] ?? '';
    $hapus_foto      = isset($_POST['hapus_foto']) && $_POST['hapus_foto'] === '1';

    // 1. Validasi Nama
    if ($nama === '') {
        $error = 'Nama lengkap tidak boleh kosong.';
    }
    // 2. Validasi Username
    elseif ($username === '') {
        $error = 'Username tidak boleh kosong.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username hanya boleh terdiri dari huruf, angka, garis bawah (_), dan 3-30 karakter.';
    } else {
        // Cek username apakah sudah dipakai user lain
        $uCheck = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $uCheck->bind_param('si', $username, $userId);
        $uCheck->execute();
        if ($uCheck->get_result()->num_rows > 0) {
            $error = 'Username "' . htmlspecialchars($username) . '" sudah dipakai oleh pengguna lain.';
        }
    }

    // 3. Validasi Password jika diisi
    if (!$error && $new_password !== '') {
        if (strlen($new_password) < 6) {
            $error = 'Password baru minimal harus 6 karakter.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Konfirmasi password baru tidak cocok.';
        }
    }

    // 4. Proses Upload Foto Profil (PP) jika ada
    $fotoPath = $data['foto'];
    if (!$error) {
        if ($hapus_foto) {
            // Hapus file lama jika ada
            if (!empty($fotoPath) && file_exists(__DIR__ . '/../' . ltrim($fotoPath, '/\\'))) {
                @unlink(__DIR__ . '/../' . ltrim($fotoPath, '/\\'));
            }
            $fotoPath = null;
        }

        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['foto']['tmp_name'];
            $fileName = $_FILES['foto']['name'];
            $fileSize = $_FILES['foto']['size'];
            $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowed  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            if (!in_array($ext, $allowed)) {
                $error = 'Format file foto profil harus berupa JPG, PNG, WEBP, atau GIF.';
            } elseif ($fileSize > 3 * 1024 * 1024) {
                $error = 'Ukuran file foto profil maksimal 3MB.';
            } else {
                $uploadDir = __DIR__ . '/../assets/uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $newFileName = 'user_' . $userId . '_' . time() . '.' . $ext;
                $destPath    = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmp, $destPath)) {
                    // Hapus foto lama bila berbeda
                    if (!empty($fotoPath) && file_exists(__DIR__ . '/../' . ltrim($fotoPath, '/\\'))) {
                        @unlink(__DIR__ . '/../' . ltrim($fotoPath, '/\\'));
                    }
                    $fotoPath = 'assets/uploads/profiles/' . $newFileName;
                } else {
                    $error = 'Gagal menyimpan file foto yang diunggah ke server.';
                }
            }
        }
    }

    // 5. Simpan Perubahan jika tidak ada error
    if (!$error) {
        $conn->begin_transaction();
        try {
            // Update username & password jika diubah
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $upUser = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                $upUser->bind_param('ssi', $username, $hash, $userId);
            } else {
                $upUser = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                $upUser->bind_param('si', $username, $userId);
            }
            $upUser->execute();

            // Update profile (nama, bio, foto)
            $upProfile = $conn->prepare("UPDATE profiles SET nama = ?, bio = ?, foto = ? WHERE user_id = ?");
            $upProfile->bind_param('sssi', $nama, $bio, $fotoPath, $userId);
            $upProfile->execute();

            // Update alumni profile jika ada
            $chkAlumni = $conn->prepare("SELECT id FROM alumni_profiles WHERE user_id = ?");
            $chkAlumni->bind_param('i', $userId);
            $chkAlumni->execute();
            if ($chkAlumni->get_result()->num_rows > 0) {
                $upAlumni = $conn->prepare("
                    UPDATE alumni_profiles 
                    SET angkatan = ?, jurusan = ?, profesi = ?, perusahaan = ? 
                    WHERE user_id = ?
                ");
                $upAlumni->bind_param('ssssi', $angkatan, $jurusan, $profesi, $perusahaan, $userId);
                $upAlumni->execute();
            } else {
                $insAlumni = $conn->prepare("
                    INSERT INTO alumni_profiles (user_id, angkatan, jurusan, profesi, perusahaan)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insAlumni->bind_param('issss', $userId, $angkatan, $jurusan, $profesi, $perusahaan);
                $insAlumni->execute();
            }

            $conn->commit();

            // Update session
            $_SESSION['nama']     = $nama;
            $_SESSION['username'] = $username;

            setFlash('success', 'Profil, foto profil, dan akun Anda berhasil diperbarui!');
            header('Location: ' . baseUrl('pages/profile.php'));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
        }
    }
}

// Refresh data setelah update bila ada error
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.email, u.role_id,
           p.nama, p.bio, p.foto,
           ap.angkatan, ap.jurusan, ap.profesi, ap.perusahaan
    FROM users u
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

$pageTitle = 'Edit Profil & Akun';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:680px; margin-top:24px; margin-bottom:40px;">
  <div class="card" style="box-shadow:var(--shadow-md);">
    <div class="card-header">
      <span class="card-title" style="font-size:16px;">⚙️ Edit Profil & Pengaturan Akun</span>
      <a href="<?= baseUrl('pages/profile.php') ?>" class="btn btn-outline btn-sm">Batal</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

      <!-- 1. BAGIAN FOTO PROFIL (PP) -->
      <div style="background:#F8FAFC; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:20px; text-align:center;">
        <label class="form-label" style="margin-bottom:10px; display:block;">Foto Profil (PP)</label>
        
        <div style="display:flex; flex-direction:column; align-items:center; gap:12px;">
          <div id="previewContainer">
            <?= renderAvatar($data['nama'] ?? $data['username'], $data['foto'] ?? null, 'avatar-lg') ?>
          </div>

          <div style="display:flex; flex-direction:column; gap:6px; align-items:center;">
            <input type="file" name="foto" id="fotoInput" accept="image/*" class="form-input" style="padding:8px 12px; font-size:12px; max-width:280px;">
            <span style="font-size:11px; color:var(--text-muted);">Format: JPG, PNG, WEBP (Maksimal 3MB)</span>

            <?php if (!empty($data['foto'])): ?>
              <label style="font-size:12px; color:#EF4444; margin-top:6px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                <input type="checkbox" name="hapus_foto" value="1"> Hapus foto profil (gunakan inisial)
              </label>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- 2. BAGIAN AKUN (USERNAME & PASSWORD) -->
      <div style="background:white; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:20px;">
        <div style="font-size:13px; font-weight:700; color:var(--navy); margin-bottom:12px; border-bottom:1px solid var(--border); padding-bottom:6px;">
          🔐 Kredensial Akun (Username & Password)
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-input" value="<?= e($data['username'] ?? '') ?>" required>
          <span style="font-size:11px; color:var(--text-muted);">Bisa dipakai untuk login. Huruf, angka, atau underscore.</span>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group">
            <label class="form-label">Password Baru (Opsional)</label>
            <input type="password" name="new_password" class="form-input" placeholder="••••••••">
            <span style="font-size:10px; color:var(--text-muted);">Kosongkan jika tak ingin ganti.</span>
          </div>

          <div class="form-group">
            <label class="form-label">Ulangi Password Baru</label>
            <input type="password" name="confirm_password" class="form-input" placeholder="••••••••">
          </div>
        </div>
      </div>

      <!-- 3. BAGIAN BIODATA & DATA ALUMNI -->
      <div style="background:white; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:20px;">
        <div style="font-size:13px; font-weight:700; color:var(--navy); margin-bottom:12px; border-bottom:1px solid var(--border); padding-bottom:6px;">
          📋 Biodata & Karier Profesional
        </div>

        <div class="form-group">
          <label class="form-label">Nama Lengkap</label>
          <input type="text" name="nama" class="form-input" value="<?= e($data['nama'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label">Biodata Singkat / Motto</label>
          <textarea name="bio" class="form-textarea" placeholder="Tuliskan pengalaman atau bio singkat tentang dirimu..."><?= e($data['bio'] ?? '') ?></textarea>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group">
            <label class="form-label">Angkatan</label>
            <input type="text" name="angkatan" class="form-input" placeholder="contoh: 2020" value="<?= e($data['angkatan'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Jurusan</label>
            <input type="text" name="jurusan" class="form-input" placeholder="contoh: PPLG" value="<?= e($data['jurusan'] ?? '') ?>">
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group">
            <label class="form-label">Profesi / Keahlian</label>
            <input type="text" name="profesi" class="form-input" placeholder="contoh: Software Engineer" value="<?= e($data['profesi'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Perusahaan / Kampus</label>
            <input type="text" name="perusahaan" class="form-input" placeholder="contoh: Tokopedia / ITB" value="<?= e($data['perusahaan'] ?? '') ?>">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="padding:12px; font-size:14px;">
        💾 Simpan Seluruh Perubahan
      </button>
    </form>
  </div>
</div>

<script>
// Preview Foto Profil Langsung Saat Dipilih
document.getElementById('fotoInput').addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function(evt) {
      const container = document.getElementById('previewContainer');
      container.innerHTML = '<img src="' + evt.target.result + '" class="avatar avatar-lg" style="object-fit:cover;border-radius:50%;" alt="Preview">';
    };
    reader.readAsDataURL(file);
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
