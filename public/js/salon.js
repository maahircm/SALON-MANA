(function () {
  // Sidebar toggle (mobile)
  document.addEventListener('click', function (e) {
    const t = e.target.closest('[data-toggle-sidebar]');
    if (t) document.querySelector('.sm-sidebar').classList.toggle('open');
  });

  // Confirmation dialogs: <form data-confirm="Message"> opens a Bootstrap modal before submitting.
  const modalEl = document.getElementById('confirmModal');
  let pendingForm = null;
  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (form.dataset.confirm && !form.dataset.confirmed && (!modalEl || typeof bootstrap === 'undefined')) {
      // Bootstrap (CDN) unavailable: fall back to the native confirm dialog so destructive actions are still guarded.
      if (!window.confirm(form.dataset.confirm)) { e.preventDefault(); return; }
    } else if (form.dataset.confirm && !form.dataset.confirmed && modalEl) {
      e.preventDefault();
      pendingForm = form;
      modalEl.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      return;
    }
    // Prevent duplicate submissions (double click / slow network).
    if (form.dataset.submitting) { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    form.querySelectorAll('button[type=submit]').forEach(b => { b.disabled = true; });
    setTimeout(() => { delete form.dataset.submitting; form.querySelectorAll('button[type=submit]').forEach(b => { b.disabled = false; }); }, 8000);
  });
  if (modalEl && typeof bootstrap !== 'undefined') {
    modalEl.querySelector('[data-confirm-accept]').addEventListener('click', function () {
      if (!pendingForm) return;
      pendingForm.dataset.confirmed = '1';
      bootstrap.Modal.getInstance(modalEl).hide();
      pendingForm.requestSubmit();
    });
  }

  // Toasts from flash messages
  if (typeof bootstrap !== 'undefined') document.querySelectorAll('.toast[data-auto]').forEach(el => new bootstrap.Toast(el, { delay: 5000 }).show());
  else document.querySelectorAll('.toast[data-auto]').forEach(el => el.classList.add('show'));

  // Select-all helper for permission groups
  document.addEventListener('change', function (e) {
    const g = e.target.closest('[data-check-group]');
    if (g) document.querySelectorAll('[data-group="' + g.dataset.checkGroup + '"]').forEach(c => { c.checked = g.checked; });
  });
})();
