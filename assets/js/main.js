// ============================================================
// ALUMNICONNECT - Main JavaScript
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

  // ====== HAMBURGER TOGGLE ======
  const hamburger = document.querySelector('.hamburger');
  const menu      = document.querySelector('.navbar-menu');

  if (hamburger && menu) {
    hamburger.addEventListener('click', function (e) {
      e.stopPropagation();
      menu.classList.toggle('open');

      // Ubah ikon ☰ ↔ ✕
      hamburger.textContent = menu.classList.contains('open') ? '✕' : '☰';
    });

    // Tutup menu kalau klik di luar
    document.addEventListener('click', function (e) {
      if (!menu.contains(e.target) && !hamburger.contains(e.target)) {
        menu.classList.remove('open');
        hamburger.textContent = '☰';
      }
    });
  }

  // ====== AUTO-HIDE ALERT ======
  document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity 0.4s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 400);
    }, 4000);
  });
});

function confirmLogout() {
  return confirm('Yakin ingin keluar?');
}