(() => {
  const root = document.documentElement;
  let screenshotSuspected = false;
  let manuallyHidden = false;
  const syncButton = () => {
    const toggle = document.getElementById('bsn-privacy-toggle');
    if (toggle) {
      const hidden = root.classList.contains('bsn-hidden');
      toggle.textContent = hidden ? 'BSN tonen' : 'BSN verbergen';
      toggle.setAttribute('aria-pressed', String(hidden));
    }
  };
  const hide = () => {
    root.classList.add('bsn-hidden');
    syncButton();
  };
  const update = () => {
    root.classList.toggle('bsn-hidden', manuallyHidden || screenshotSuspected || document.hidden || !document.hasFocus());
    syncButton();
  };
  window.addEventListener('blur', hide);
  document.addEventListener('visibilitychange', update);
  window.addEventListener('focus', update);
  window.addEventListener('pagehide', hide);
  window.addEventListener('pageshow', event => {
    // Do not reuse a report with BSNs restored from the browser's back/forward cache.
    if (event.persisted) {
      hide();
      window.location.reload();
    } else {
      update();
    }
  });
  const detectShortcut = event => {
    if (event.key === 'PrintScreen'
        || (event.metaKey && event.shiftKey && ['3', '4', '5'].includes(event.key))
        || (event.metaKey && event.shiftKey && event.key.toLowerCase() === 's')) {
      screenshotSuspected = true;
      hide();
    }
  };
  document.addEventListener('keydown', detectShortcut, true);
  document.addEventListener('keyup', detectShortcut, true);
  document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('bsn-privacy-toggle');
    toggle?.addEventListener('click', () => {
      manuallyHidden = !root.classList.contains('bsn-hidden');
      screenshotSuspected = false;
      update();
    });
    update();
  });
})();
