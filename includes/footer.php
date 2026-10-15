</main>

<?php if (isLoggedIn()): ?>
<!-- Bottom navigation (mobile only) -->
<nav class="bottom-nav">
  <a href="<?= baseUrl('pages/feed.php') ?>" class="<?= $currentPage==='feed'?'active':'' ?>">
    <span class="icon">🏠</span> Feed
  </a>
  <a href="<?= baseUrl('pages/directory.php') ?>" class="<?= $currentPage==='directory'?'active':'' ?>">
    <span class="icon">🔍</span> Direktori
  </a>
  <a href="<?= baseUrl('pages/messages.php') ?>" class="<?= $currentPage==='messages'?'active':'' ?>">
    <span class="icon">💬</span> Pesan
  </a>
  <a href="<?= baseUrl('pages/notifications.php') ?>" class="<?= $currentPage==='notifications'?'active':'' ?>">
    <span class="icon">🔔</span> Notifikasi
  </a>
  <a href="<?= baseUrl('pages/profile.php') ?>" class="<?= $currentPage==='profile'?'active':'' ?>">
    <span class="icon">👤</span> Profil
  </a>
</nav>
<?php endif; ?>

<footer class="footer">
  <p>&copy; <?= date('Y') ?> <strong>ALUMNICONNECT</strong> — Finz The Dragon</p>
  <p style="margin-top:4px;font-size:11px;">Paket 14 PKWU · Fidi Naidzar · XI PPLG</p>
</footer>

<script src="<?= baseUrl('assets/js/main.js') ?>"></script>
</body>
</html>