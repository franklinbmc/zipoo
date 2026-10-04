(function () {
  'use strict';

  const sidebar = document.querySelector('[data-sidebar]');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const openBtn = document.querySelector('[data-sidebar-open]');
  const closeBtn = document.querySelector('[data-sidebar-close]');

  function setSidebar(open) {
    if (!sidebar || !backdrop) return;
    sidebar.classList.toggle('open', open);
    backdrop.classList.toggle('show', open);
  }

  if (openBtn) openBtn.addEventListener('click', () => setSidebar(true));
  if (closeBtn) closeBtn.addEventListener('click', () => setSidebar(false));
  if (backdrop) backdrop.addEventListener('click', () => setSidebar(false));

  document.querySelectorAll('.side-link').forEach((link) => {
    link.addEventListener('click', () => {
      document.querySelectorAll('.side-link').forEach((item) => item.classList.remove('active'));
      link.classList.add('active');
      setSidebar(false);
    });
  });
})();
