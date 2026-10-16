<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Ambil info user aktif
$uStmt = $conn->prepare("
    SELECT pr.foto, ap.jurusan, ap.angkatan 
    FROM users u 
    LEFT JOIN profiles pr ON pr.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id 
    WHERE u.id = ?
");
$uStmt->bind_param('i', $userId);
$uStmt->execute();
$myInfo = $uStmt->get_result()->fetch_assoc();

// Kategori postingan
$categories = $conn->query("SELECT * FROM categories ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Ambil rekomendasi teman untuk quick mention chips
$friendsStmt = $conn->prepare("
    SELECT u.id, u.username, p.nama, ap.profesi 
    FROM users u 
    LEFT JOIN profiles p ON p.user_id = u.id 
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id <> ? 
    ORDER BY p.nama ASC 
    LIMIT 8
");
$friendsStmt->bind_param('i', $userId);
$friendsStmt->execute();
$quickFriends = $friendsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Buat Postingan Baru';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width: 760px; margin: 30px auto 60px;">

  <!-- Header Breadcrumb & Back -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <a href="<?= baseUrl('pages/feed.php') ?>" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
      ← Kembali ke Feed
    </a>
    <span style="font-size:12px; color:var(--text-muted); font-weight:600;">ALUMNICONNECT STUDIO</span>
  </div>

  <div class="card" style="border-radius:24px; padding:28px; box-shadow:var(--shadow-lg); border:1px solid var(--border);">
    
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:24px; padding-bottom:18px; border-bottom:1px solid var(--border);">
      <?= renderAvatar($_SESSION['nama'], $myInfo['foto'] ?? null, 'avatar-md') ?>
      <div>
        <h2 style="font-size:18px; font-weight:800; color:var(--navy); margin-bottom:2px;">Buat Postingan Baru</h2>
        <div style="font-size:12px; color:var(--text-muted);">
          Posting sebagai <strong style="color:var(--primary);"><?= e($_SESSION['nama']) ?></strong> (@<?= e($_SESSION['username'] ?? '') ?>)
        </div>
      </div>
    </div>

    <form method="POST" action="<?= baseUrl('pages/post_action.php?action=create') ?>" enctype="multipart/form-data" id="createPostForm">
      
      <!-- 1. AREA UPLOAD MEDIA (Video / Foto) ala TikTok/Instagram -->
      <div class="form-group" style="margin-bottom:20px;">
        <label class="form-label" style="font-size:13px; font-weight:700; margin-bottom:8px; display:block;">
          📹 Video atau Foto Karya / Kegiatan
        </label>
        
        <div id="dropzoneBox" class="media-dropzone" style="border:2px dashed var(--border); border-radius:18px; padding:30px 20px; text-align:center; background:#F8FAFC; cursor:pointer; transition:all 0.25s; position:relative;">
          <input type="file" name="media" id="mediaFileInput" accept="image/*,video/*" style="display:none;">
          
          <div id="dropzoneEmpty">
            <div style="font-size:44px; margin-bottom:10px;">🎬</div>
            <div style="font-weight:700; color:var(--navy); font-size:15px; margin-bottom:4px;">
              Klik untuk pilih Video atau Foto
            </div>
            <div style="font-size:12px; color:var(--text-muted); max-width:400px; margin:0 auto 12px;">
              Mendukung video MP4, WebM, MOV, atau foto JPG, PNG, WebP hingga 50MB
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('mediaFileInput').click()">
              Pilih dari Perangkat
            </button>
          </div>

          <!-- Preview Player / Image -->
          <div id="dropzonePreview" style="display:none; text-align:center;">
            <div id="mediaPreviewHolder" style="max-height:360px; overflow:hidden; border-radius:14px; background:#000; display:inline-block; max-width:100%; box-shadow:var(--shadow);"></div>
            <div style="margin-top:12px; display:flex; justify-content:center; gap:10px;">
              <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('mediaFileInput').click()">
                🔄 Ganti Media
              </button>
              <button type="button" class="btn btn-danger btn-sm" id="btnRemoveMedia">
                🗑️ Hapus Media
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- 2. DESKRIPSI / CAPTION DENGAN AUTOCOMPLETE MENTION & HASHTAG -->
      <div class="form-group" style="position:relative; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
          <label class="form-label" style="font-size:13px; font-weight:700; margin:0;">
            ✍️ Deskripsi & Caption
          </label>
          <span id="charCounter" style="font-size:11px; color:var(--text-muted); font-weight:600;">0 karakter</span>
        </div>

        <textarea name="konten" id="postContentInput" class="form-textarea" rows="4" 
                  placeholder="Ceritakan tentang karya, karier, atau kenanganmu... Ketik @username untuk tag teman (mereka akan menerima pesan otomatis di chat!), atau #hashtag untuk topik." 
                  required style="border-radius:14px; font-size:14px; padding:14px; line-height:1.6;"></textarea>

        <!-- Floating Autocomplete Dropdown saat user mengetik @ -->
        <div id="mentionDropdown" class="mention-autocomplete-menu" style="display:none;"></div>
      </div>

      <!-- Quick Friend Mention Chips -->
      <?php if (!empty($quickFriends)): ?>
        <div style="margin-bottom:20px; background:var(--bg); border:1px solid var(--border); border-radius:14px; padding:12px 14px;">
          <div style="font-size:11px; font-weight:700; color:var(--navy); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
            <span>🏷️ Klik untuk Cepat Tag Teman:</span>
            <span style="font-weight:normal; color:var(--text-muted);">(Otomatis tertulis @username di deskripsi)</span>
          </div>
          <div style="display:flex; flex-wrap:wrap; gap:6px;">
            <?php foreach ($quickFriends as $qf): ?>
              <button type="button" class="mention-chip" onclick="insertMention('<?= e($qf['username']) ?>')">
                <?= renderAvatar($qf['nama'] ?? $qf['username'], null, 'avatar-sm', 'width:18px;height:18px;font-size:9px;') ?>
                <span>@<?= e($qf['username']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 3. KATEGORI -->
      <div class="form-group" style="margin-bottom:24px;">
        <label class="form-label" style="font-size:13px; font-weight:700; margin-bottom:6px; display:block;">
          📂 Kategori Postingan
        </label>
        <select name="category_id" class="form-select" style="border-radius:12px; font-size:13px; padding:10px 14px;">
          <option value="">-- Pilih Kategori (Opsional) --</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= e($cat['nama_kategori']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Notifikasi Info Box -->
      <div style="display:flex; gap:12px; background:linear-gradient(135deg, rgba(17,152,185,0.08), rgba(245,158,11,0.08)); border:1px solid rgba(17,152,185,0.25); border-radius:14px; padding:14px 16px; margin-bottom:24px;">
        <div style="font-size:22px; flex-shrink:0;">💡</div>
        <div style="font-size:12px; color:var(--navy); line-height:1.5;">
          <strong>Sistem Notifikasi Otomatis:</strong><br>
          Teman yang kamu sebut di deskripsi dengan <code>@username</code> akan otomatis mendapatkan <strong>notifikasi</strong> serta <strong>pesan masuk di Inbox Chat mereka</strong> lengkap dengan tautan postingan ini.
        </div>
      </div>

      <!-- TOMBOL PUBLISH -->
      <div style="display:flex; justify-content:flex-end; gap:12px; align-items:center; border-top:1px solid var(--border); padding-top:18px;">
        <a href="<?= baseUrl('pages/feed.php') ?>" class="btn btn-outline" style="border-radius:12px; padding:10px 22px;">
          Batal
        </a>
        <button type="submit" id="btnSubmitPost" class="btn btn-primary" style="border-radius:12px; padding:10px 32px; font-weight:700; font-size:14px; box-shadow:0 4px 14px rgba(17,152,185,0.4);">
          🚀 Terbitkan Postingan
        </button>
      </div>

    </form>

  </div>

</div>

<script>
// Live Drag & Drop / Preview Media
const mediaInput   = document.getElementById('mediaFileInput');
const dropzoneBox  = document.getElementById('dropzoneBox');
const dropEmpty    = document.getElementById('dropzoneEmpty');
const dropPreview  = document.getElementById('dropzonePreview');
const holder       = document.getElementById('mediaPreviewHolder');
const btnRemove    = document.getElementById('btnRemoveMedia');

mediaInput.addEventListener('change', handleFileSelected);

function handleFileSelected() {
  const file = mediaInput.files[0];
  if (!file) {
    dropEmpty.style.display = 'block';
    dropPreview.style.display = 'none';
    holder.innerHTML = '';
    return;
  }
  const isVideo = file.type.startsWith('video/');
  const reader = new FileReader();

  reader.onload = function(e) {
    dropEmpty.style.display = 'none';
    dropPreview.style.display = 'block';
    if (isVideo) {
      holder.innerHTML = '<video src="' + e.target.result + '" controls autoplay muted style="max-height:340px; width:100%; max-width:540px; object-fit:contain; border-radius:12px;"></video>';
    } else {
      holder.innerHTML = '<img src="' + e.target.result + '" style="max-height:340px; width:100%; max-width:540px; object-fit:contain; border-radius:12px;" alt="Preview">';
    }
  };
  reader.readAsDataURL(file);
}

btnRemove.addEventListener('click', function(e) {
  e.stopPropagation();
  mediaInput.value = '';
  dropEmpty.style.display = 'block';
  dropPreview.style.display = 'none';
  holder.innerHTML = '';
});

// Drag & drop highlight
['dragenter', 'dragover'].forEach(eventName => {
  dropzoneBox.addEventListener(eventName, e => {
    e.preventDefault();
    dropzoneBox.style.borderColor = 'var(--primary)';
    dropzoneBox.style.background = '#F0F9FF';
  }, false);
});
['dragleave', 'drop'].forEach(eventName => {
  dropzoneBox.addEventListener(eventName, e => {
    e.preventDefault();
    dropzoneBox.style.borderColor = 'var(--border)';
    dropzoneBox.style.background = '#F8FAFC';
  }, false);
});
dropzoneBox.addEventListener('drop', e => {
  if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
    mediaInput.files = e.dataTransfer.files;
    handleFileSelected();
  }
});

// Mention Quick Insertion
function insertMention(username) {
  const textarea = document.getElementById('postContentInput');
  const mentionText = '@' + username + ' ';
  
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const val = textarea.value;

  textarea.value = val.substring(0, start) + mentionText + val.substring(end);
  textarea.focus();
  textarea.selectionStart = textarea.selectionEnd = start + mentionText.length;
  updateCharCounter();
}

// Character counter & Autocomplete helper
const textarea = document.getElementById('postContentInput');
const counter  = document.getElementById('charCounter');
const dropdown = document.getElementById('mentionDropdown');

function updateCharCounter() {
  counter.textContent = textarea.value.length + ' karakter';
}
textarea.addEventListener('input', updateCharCounter);

// Autocomplete Live saat ketik @
let mentionQuery = null;
let mentionStartIndex = -1;

textarea.addEventListener('keyup', function(e) {
  const cursor = textarea.selectionStart;
  const textBeforeCursor = textarea.value.slice(0, cursor);
  const match = textBeforeCursor.match(/@([a-zA-Z0-9_]*)$/);

  if (match) {
    mentionQuery = match[1];
    mentionStartIndex = cursor - match[0].length;
    fetchMentions(mentionQuery);
  } else {
    hideMentionDropdown();
  }
});

function hideMentionDropdown() {
  dropdown.style.display = 'none';
  dropdown.innerHTML = '';
}

function fetchMentions(q) {
  fetch('<?= baseUrl('pages/api_users.php?q=') ?>' + encodeURIComponent(q))
    .then(res => res.json())
    .then(data => {
      if (data.success && data.users.length > 0) {
        showMentionDropdown(data.users);
      } else {
        hideMentionDropdown();
      }
    })
    .catch(() => hideMentionDropdown());
}

function showMentionDropdown(users) {
  dropdown.innerHTML = '';
  users.forEach(u => {
    const item = document.createElement('div');
    item.className = 'mention-item';
    item.innerHTML = `
      <div style="width:26px; height:26px; border-radius:50%; background:#1198B9; color:white; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700;">
        ${u.nama.charAt(0).toUpperCase()}
      </div>
      <div>
        <div style="font-weight:700; font-size:12px; color:var(--navy);">${u.nama}</div>
        <div style="font-size:11px; color:var(--text-muted);">@${u.username} • ${u.info}</div>
      </div>
    `;
    item.addEventListener('click', () => {
      const cursor = textarea.selectionStart;
      const before = textarea.value.slice(0, mentionStartIndex);
      const after  = textarea.value.slice(cursor);
      textarea.value = before + '@' + u.username + ' ' + after;
      textarea.focus();
      hideMentionDropdown();
      updateCharCounter();
    });
    dropdown.appendChild(item);
  });
  dropdown.style.display = 'block';
}

// Close mention dropdown when clicking outside
document.addEventListener('click', function(e) {
  if (!dropdown.contains(e.target) && e.target !== textarea) {
    hideMentionDropdown();
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
