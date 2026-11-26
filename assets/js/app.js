/* =========================================================
   COURTCTRL — shared vanilla JavaScript
   Top-level project: assets/js/app.js
   ========================================================= */
(function () {
  'use strict';

  const doc = document;
  const focusableSelector = [
    'a[href]', 'area[href]', 'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])', 'textarea:not([disabled])',
    'button:not([disabled])', '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  /* -------------------------------------------------------
     Accessible create/edit modal used by the tournament form
     ------------------------------------------------------- */
  const backdrop = doc.getElementById('modalBackdrop');
  const modal = backdrop ? (backdrop.querySelector('.modal,[role="dialog"]') || backdrop) : null;
  const openButtons = [doc.getElementById('openModal'), doc.getElementById('emptyCreate')].filter(Boolean);
  const closeButtons = [doc.getElementById('closeModal'), doc.getElementById('cancelModal')].filter(Boolean);
  let modalReturnFocus = null;

  function modalFocusable() {
    return modal ? Array.from(modal.querySelectorAll(focusableSelector)).filter(el => el.offsetParent !== null) : [];
  }

  function openModal() {
    if (!backdrop) return;
    modalReturnFocus = doc.activeElement;
    backdrop.classList.add('show');
    backdrop.setAttribute('aria-hidden', 'false');
    backdrop.setAttribute('role', 'presentation');
    if (modal) {
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      if (!modal.hasAttribute('aria-label') && !modal.hasAttribute('aria-labelledby')) {
        const heading = modal.querySelector('h1,h2,h3');
        if (heading) {
          if (!heading.id) heading.id = 'courtCtrlModalTitle';
          modal.setAttribute('aria-labelledby', heading.id);
        }
      }
    }
    const focusables = modalFocusable();
    const preferred = modal && modal.querySelector('[autofocus],input:not([type="hidden"]),select,button');
    (preferred || focusables[0] || modal || backdrop).focus({ preventScroll: true });
  }

  function closeModal() {
    if (!backdrop) return;
    backdrop.classList.remove('show');
    backdrop.setAttribute('aria-hidden', 'true');
    if (modalReturnFocus && typeof modalReturnFocus.focus === 'function') {
      modalReturnFocus.focus({ preventScroll: true });
    }
    modalReturnFocus = null;
  }

  window.openModal = openModal;
  window.closeModal = closeModal;
  openButtons.forEach(button => button.addEventListener('click', openModal));
  closeButtons.forEach(button => button.addEventListener('click', closeModal));

  if (backdrop) {
    backdrop.addEventListener('click', event => {
      if (event.target === backdrop) closeModal();
    });
  }

  /* -------------------------------------------------------
     Tournament category form helpers (only on the page that
     contains these controls; safe no-op on all other pages)
     ------------------------------------------------------- */
  const categoryList = doc.getElementById('categoryList');
  let categoryIndex = categoryList ? categoryList.children.length : 0;

  function addCategory(name = '', type = 'Doubles', format = 'Round Robin + Elimination') {
    if (!categoryList) return;
    const row = doc.createElement('div');
    row.className = 'category-row';
    const idx = categoryIndex++;
    row.innerHTML = `
      <label class="field">Category name <span>*</span><input name="categories[${idx}]" type="text" placeholder="e.g. Novice Men's Doubles" maxlength="100" required></label>
      <label class="field">Type<select name="category_types[${idx}]"><option value="Doubles">Doubles</option><option value="Singles">Singles</option></select></label>
      <label class="field">Game format<select name="game_formats[${idx}]"><option value="Round Robin + Elimination">Round Robin + Elimination</option><option value="Round Robin">Round Robin</option><option value="Single Elimination">Single Elimination</option></select></label>
      <button type="button" class="remove-category" aria-label="Remove category">×</button>`;
    const categoryInput = row.querySelector(`input[name="categories[${idx}]"]`);
    const typeSelect = row.querySelector(`select[name="category_types[${idx}]"]`);
    const formatSelect = row.querySelector(`select[name="game_formats[${idx}]"]`);
    categoryInput.value = name;
    typeSelect.value = type;
    formatSelect.value = format;
    row.querySelector('.remove-category').addEventListener('click', () => {
      if (categoryList.children.length > 1) row.remove();
      else categoryInput.value = '';
    });
    categoryList.appendChild(row);
  }

  const addCategoryButton = doc.getElementById('addCategory');
  if (addCategoryButton && categoryList) {
    addCategoryButton.addEventListener('click', () => addCategory());
    if (categoryList.children.length === 0) addCategory();
  }

  const startDate = doc.getElementById('startDate');
  const numberOfDays = doc.getElementById('numberOfDays');
  const endDatePreview = doc.getElementById('endDatePreview');

  function updateEndDate() {
    if (!startDate || !numberOfDays || !endDatePreview) return;
    if (!startDate.value || !numberOfDays.value || Number(numberOfDays.value) < 1) {
      endDatePreview.textContent = 'Select start date';
      return;
    }
    const date = new Date(`${startDate.value}T12:00:00`);
    date.setDate(date.getDate() + Number(numberOfDays.value) - 1);
    endDatePreview.textContent = date.toLocaleDateString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric'
    });
  }
  if (startDate) startDate.addEventListener('change', updateEndDate);
  if (numberOfDays) numberOfDays.addEventListener('input', updateEndDate);

  // Reopen the create/edit form if PHP returned validation errors.
  if (doc.querySelector('.alert.error') && backdrop) openModal();

  /* -------------------------------------------------------
     Reusable accessible confirmation modal.
     Add data-confirm="message" to a destructive form or
     submit button. Original submission runs only on confirm.
     ------------------------------------------------------- */
  let confirmBackdrop = null;
  let confirmMessage = null;
  let confirmAction = null;
  let confirmReturnFocus = null;
  let pendingSubmit = null;
  const bypassSubmit = new WeakSet();
  const bypassClick = new WeakSet();

  function ensureConfirmModal() {
    if (confirmBackdrop) return;
    confirmBackdrop = doc.createElement('div');
    confirmBackdrop.className = 'cc-confirm-backdrop';
    confirmBackdrop.hidden = true;
    confirmBackdrop.innerHTML = `
      <section class="cc-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="ccConfirmTitle" aria-describedby="ccConfirmMessage" tabindex="-1">
        <div class="cc-confirm-icon" aria-hidden="true">!</div>
        <div class="cc-confirm-copy">
          <h2 id="ccConfirmTitle">Please confirm</h2>
          <p id="ccConfirmMessage"></p>
        </div>
        <div class="cc-confirm-actions">
          <button type="button" class="btn btn-outline" data-confirm-cancel>Cancel</button>
          <button type="button" class="btn btn-danger" data-confirm-accept>Confirm</button>
        </div>
      </section>`;
    doc.body.appendChild(confirmBackdrop);
    confirmMessage = confirmBackdrop.querySelector('#ccConfirmMessage');
    confirmBackdrop.querySelector('[data-confirm-cancel]').addEventListener('click', cancelConfirmation);
    confirmBackdrop.querySelector('[data-confirm-accept]').addEventListener('click', acceptConfirmation);
    confirmBackdrop.addEventListener('click', event => {
      if (event.target === confirmBackdrop) cancelConfirmation();
    });
  }

  function showConfirmation(message, action, returnFocus) {
    ensureConfirmModal();
    confirmMessage.textContent = message || 'Are you sure you want to continue?';
    confirmAction = action;
    confirmReturnFocus = returnFocus || doc.activeElement;
    confirmBackdrop.hidden = false;
    const dialog = confirmBackdrop.querySelector('[role="dialog"]');
    const cancelButton = confirmBackdrop.querySelector('[data-confirm-cancel]');
    cancelButton.focus({ preventScroll: true });
    dialog.dataset.open = 'true';
  }

  function finishConfirmation() {
    if (confirmBackdrop) confirmBackdrop.hidden = true;
    confirmAction = null;
    if (confirmReturnFocus && typeof confirmReturnFocus.focus === 'function') {
      confirmReturnFocus.focus({ preventScroll: true });
    }
    confirmReturnFocus = null;
  }

  function cancelConfirmation() {
    pendingSubmit = null;
    finishConfirmation();
  }

  function acceptConfirmation() {
    const action = confirmAction;
    const submitInfo = pendingSubmit;
    finishConfirmation();
    pendingSubmit = null;
    if (typeof action === 'function') action();
    else if (submitInfo && submitInfo.form) {
      const form = submitInfo.form;
      const submitter = submitInfo.submitter;
      bypassSubmit.add(form);
      if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined);
      else form.submit();
    }
  }

  doc.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (bypassSubmit.has(form)) {
      bypassSubmit.delete(form);
      return;
    }
    const submitter = event.submitter || null;
    const message = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (!message) return;
    event.preventDefault();
    event.stopPropagation();
    pendingSubmit = { form, submitter };
    showConfirmation(message, null, submitter || doc.activeElement);
  }, true);

  doc.addEventListener('click', event => {
    const trigger = event.target.closest('[data-confirm]');
    if (!trigger || trigger instanceof HTMLFormElement) return;
    if (bypassClick.has(trigger)) {
      bypassClick.delete(trigger);
      return;
    }
    // Submit buttons are confirmed by the form submit handler above.
    if (trigger.matches('button[type="submit"],input[type="submit"]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const message = trigger.getAttribute('data-confirm');
    showConfirmation(message, () => {
      bypassClick.add(trigger);
      trigger.click();
    }, trigger);
  }, true);

  /* -------------------------------------------------------
     Shared partial-refresh helper for TV / live pages.

     Mark a page container with:
       data-refresh-container
       data-refresh-interval="3000"
       data-refresh-indicator="#refreshStatus" (optional)

     The helper fetches the current URL (including query string),
     replaces only that container's inner HTML, pauses when the
     tab is hidden or the user is editing, and keeps scroll state.
     ------------------------------------------------------- */
  function pageIsBeingEdited() {
    const active = doc.activeElement;
    if (active && (active.matches('input,select,textarea,[contenteditable="true"]') || active.closest('form'))) return true;
    if (doc.querySelector('.modal-backdrop.show, .cc-confirm-backdrop:not([hidden]), [data-refresh-pause="true"], .assignment-tools[open]')) return true;
    if (doc.querySelector('[data-manage-next-game].is-open, .manage-next-game.open, .manage-next-game[aria-expanded="true"]')) return true;
    return false;
  }

  function formatUpdatedTime(date) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function initPartialRefresh() {
    const target = doc.querySelector('[data-refresh-container]');
    if (!target || target.dataset.refreshReady === 'true') return;
    const interval = Math.max(3000, Number(target.dataset.refreshInterval || 15000));
    const indicatorSelector = target.dataset.refreshIndicator || '[data-refresh-indicator]';
    const indicator = doc.querySelector(indicatorSelector);
    let timer = null;
    let inFlight = false;

    function setIndicator(offline) {
      if (!indicator) return;
      indicator.classList.toggle('is-offline', Boolean(offline));
      indicator.textContent = offline ? 'Connection lost — retrying' : `Updated ${formatUpdatedTime(new Date())}`;
      indicator.setAttribute('role', 'status');
      indicator.setAttribute('aria-live', 'polite');
    }

    async function refresh() {
      if (inFlight || doc.hidden || pageIsBeingEdited()) return;
      inFlight = true;
      const scrollX = window.scrollX;
      const scrollY = window.scrollY;
      try {
        const response = await fetch(window.location.href, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'X-Requested-With': 'CourtCtrl-Partial-Refresh' }
        });
        if (!response.ok) throw new Error(`Refresh failed: ${response.status}`);
        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const freshTarget = parsed.querySelector('[data-refresh-container]');
        if (!freshTarget) throw new Error('Refresh container missing from response');
        // Do not overwrite an active edit that began while the request was running.
        if (pageIsBeingEdited()) return;
        target.innerHTML = freshTarget.innerHTML;
        window.scrollTo(scrollX, scrollY);
        setIndicator(false);
      } catch (error) {
        setIndicator(true);
      } finally {
        inFlight = false;
      }
    }

    function start() {
      if (timer || doc.hidden) return;
      timer = window.setInterval(refresh, interval);
    }
    function stop() {
      if (timer) window.clearInterval(timer);
      timer = null;
    }

    target.__courtCtrlRefreshNow = refresh;
    target.dataset.refreshReady = 'true';
    setIndicator(false);
    start();
    doc.addEventListener('visibilitychange', () => {
      if (doc.hidden) stop();
      else { start(); refresh(); }
    });
    window.addEventListener('online', refresh);
  }

  window.CourtCtrl = window.CourtCtrl || {};
  window.CourtCtrl.refresh = {
    init: initPartialRefresh,
    refreshNow: () => {
      const target = doc.querySelector('[data-refresh-container]');
      if (target && typeof target.__courtCtrlRefreshNow === 'function') target.__courtCtrlRefreshNow();
    }
  };
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', initPartialRefresh);
  else initPartialRefresh();

  /* -------------------------------------------------------
     Global keyboard behavior: modal focus trap and Escape.
     ------------------------------------------------------- */
  doc.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      if (confirmBackdrop && !confirmBackdrop.hidden) {
        event.preventDefault();
        cancelConfirmation();
        return;
      }
      if (backdrop && backdrop.classList.contains('show')) {
        event.preventDefault();
        closeModal();
        return;
      }
    }

    if (event.key !== 'Tab') return;
    if (confirmBackdrop && !confirmBackdrop.hidden) {
      const dialog = confirmBackdrop.querySelector('[role="dialog"]');
      const items = Array.from(dialog.querySelectorAll(focusableSelector)).filter(el => el.offsetParent !== null);
      if (!items.length) { event.preventDefault(); dialog.focus(); return; }
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
      return;
    }
    if (backdrop && backdrop.classList.contains('show') && modal) {
      const items = modalFocusable();
      if (!items.length) { event.preventDefault(); modal.focus(); return; }
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
})();
