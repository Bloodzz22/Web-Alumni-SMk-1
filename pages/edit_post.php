<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];
$postId = (int)($_GET['id'] ?? 0);
$error  = '';

if ($postId <= 0) {
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

// Ambil data post
$stmt = $conn->prepare("SELECT * FROM posts WHERE id = ?");
$stmt->bind_param('i', $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();

if (!$post) {
    setFlash('danger', 'Postingan tidak ditemukan.');
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

// Cek hak akses edit (pemilik post atau admin)
$isAdmin = ((int)($_SESSION['role_id'] ?? 0) === 1);
if ((int)$post['user_id'] !== $userId && !$isAdmin) {
    setFlash('danger', 'Anda tidak memiliki hak untuk mengedit postingan ini.');
    header('Location: ' . baseUrl('pages/feed.php'));
    exit;
}

// Proses update post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $konten      = trim($_POST['konten'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;

    if ($konten === '') {
        $error = 'Konten postingan tidak boleh kosong.';
    } else {
        $up = $conn->prepare("UPDATE posts SET konten = ?, category_id = ? WHERE id = ?");
        $up->bind_param('sii', $konten, $category_id, $postId);
        $up->execute();

        // Update hashtags
        $delTags = $conn->prepare("DELETE FROM post_hashtags WHERE post_id = ?");
        $delTags->bind_param('i', $postId);
        $delTags->execute();

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

        setFlash('success', 'Postingan berhasil diperbarui!');
        header('Location: ' . baseUrl('pages/feed.php#post-' . $postId));
        exit;
    }
}

$categories = $conn->query("SELECT * FROM categories ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Edit Postingan';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:650px; margin-top:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">✏️ Edit Postingan</span>
      <a href="<?= baseUrl('pages/feed.php') ?>" class="btn btn-outline btn-sm">Batal</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label">Isi Postingan</label>
        <textarea name="konten" class="form-textarea" rows="5" required><?= e($post['konten']) ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Kategori</label>
        <select name="category_id" class="form-select">
          <option value="">Tanpa Kategori</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ((int)$post['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
              <?= e($cat['nama_kategori']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:10px;">Simpan Perubahan</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
