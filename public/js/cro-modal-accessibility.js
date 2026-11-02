// Keep keyboard users inside CRO dialogs, and return focus to the control that opened them.
(function () {
  let opener = null;
  let activeDialog = null;
  const selector = 'a[href],button:not([disabled]),input:not([type="hidden"]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  const visibleControls = dialog => [...(dialog.querySelector('.modal-box') || dialog).querySelectorAll(selector)]
    .filter(control => control.getClientRects().length && !control.readOnly);

  const restoreOpener = dialog => {
    if (activeDialog !== dialog) return;
    const target = opener;
    activeDialog = null;
    opener = null;
    requestAnimationFrame(() => {
      if (target?.isConnected) target.focus();
    });
  };

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-modal]');
    if (trigger) {
      const dialog = document.getElementById(trigger.dataset.modal);
      if (!dialog?.classList.contains('modal')) return;
      activeDialog = dialog;
      opener = trigger;
      // app.js opens and focuses the first field; run after its short focus delay.
      window.setTimeout(() => {
        if (!dialog.classList.contains('show')) return;
        const first = visibleControls(dialog)[0];
        (first || dialog).focus();
      }, 75);
      return;
    }

    const closeButton = event.target.closest('[data-close]');
    const backdrop = event.target.classList?.contains('modal') && event.target.classList.contains('show')
      ? event.target : null;
    const dialog = closeButton?.closest('.modal') || backdrop;
    if (dialog) restoreOpener(dialog);
  });

  document.addEventListener('keydown', event => {
    const dialog = activeDialog;
    if (event.key === 'Escape' && dialog) {
      // app.js closes the open modal on Escape; restore focus after its handler.
      restoreOpener(dialog);
      return;
    }
    if (!dialog?.classList.contains('show') || event.key !== 'Tab') return;

    const controls = visibleControls(dialog);
    if (!controls.length) {
      event.preventDefault();
      dialog.focus();
      return;
    }

    const first = controls[0];
    const last = controls[controls.length - 1];
    const focusOutside = !dialog.contains(document.activeElement);
    if (event.shiftKey && (document.activeElement === first || focusOutside)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || focusOutside)) {
      event.preventDefault();
      first.focus();
    }
  });
})();
