/**
 * Minimal Auth Modal controller
 */
(function () {
  'use strict';

  let lastActiveElement = null;
  let isOpen = false;

  function getPageRoot() {
    return document.getElementById('page');
  }

  function setBackgroundInert(enabled) {
    const page = getPageRoot();
    if (!page) return;
    try {
      page.inert = !!enabled;
    } catch (e) {
      // ignore (inert not supported)
    }
  }

  function getFocusable(modal) {
    const dialog = qs('.mm-auth-dialog', modal) || modal;
    const nodes = qsa(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      dialog
    ).filter((el) => {
      // Exclude elements that are not actually visible.
      const rect = el.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0;
    });
    return nodes;
  }

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function sanitizeRedirect(url) {
    try {
      // Only allow same-origin redirects.
      const u = new URL(url, window.location.origin);
      if (u.origin !== window.location.origin) return window.location.origin + '/';
      return u.toString();
    } catch (e) {
      return window.location.origin + '/';
    }
  }

  function stripAuthParams(url) {
    try {
      const u = new URL(url, window.location.origin);
      u.searchParams.delete('login');
      u.searchParams.delete('register');
      u.searchParams.delete('redirect_to');
      u.searchParams.delete('error');
      return u.toString();
    } catch (e) {
      return window.location.href.split('#')[0];
    }
  }

  function setRedirect(modal, redirectTo) {
    qsa('[data-mm-auth-redirect]', modal).forEach((el) => {
      el.value = redirectTo;
    });

    // Update social links with redirect_to param for first hop.
    qsa('[data-mm-auth-social]', modal).forEach((a) => {
      try {
        const u = new URL(a.getAttribute('href') || '/', window.location.origin);
        u.searchParams.set('redirect_to', redirectTo);
        a.setAttribute('href', u.toString());
      } catch (e) {
        // ignore
      }
    });
  }

  function setMode(modal, mode) {
    const tabs = qsa('[data-mm-auth-tab]', modal);
    const panels = qsa('[data-mm-auth-panel]', modal);

    tabs.forEach((t) => {
      const isActive = t.getAttribute('data-mm-auth-tab') === mode;
      t.classList.toggle('is-active', isActive);
      t.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    panels.forEach((p) => {
      const isActive = p.getAttribute('data-mm-auth-panel') === mode;
      p.classList.toggle('is-active', isActive);
    });
  }

  function setAlert(modal, errorCode) {
    const alert = qs('[data-mm-auth-alert]', modal);
    if (!alert) return;

    const code = (errorCode || '').toString();
    const messages = {
      invalid_email: 'Please enter a valid email address.',
      weak_password: 'Your password is too short. Use at least 8 characters.',
      email_exists: 'An account with this email already exists. Try logging in instead.',
      register_failed: 'Registration failed. Please try again in a moment.',
    };

    const msg = messages[code] || '';
    if (!msg) {
      alert.style.display = 'none';
      alert.textContent = '';
      return;
    }

    alert.textContent = msg;
    alert.style.display = 'block';
  }

  function openModal(mode, redirectTo) {
    const modal = qs('#mm-auth-modal');
    if (!modal) return;

    lastActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    const cleanRedirect = sanitizeRedirect(stripAuthParams(redirectTo || window.location.href));
    setRedirect(modal, cleanRedirect);
    setMode(modal, mode || 'login');

    // Show error message if present in URL (?error=...).
    try {
      const u = new URL(window.location.href);
      const err = u.searchParams.get('error') || '';
      setAlert(modal, err);
    } catch (e) {
      setAlert(modal, '');
    }

    modal.style.display = 'block';
    modal.setAttribute('aria-hidden', 'false');
    try { modal.inert = false; } catch (e) { /* ignore */ }
    document.documentElement.style.overflow = 'hidden';
    setBackgroundInert(true);
    isOpen = true;

    // Move focus into the dialog for accessibility.
    const firstField = qs('input[name="log"], input[name="email"], button[data-mm-auth-close]', modal);
    if (firstField && typeof firstField.focus === 'function') {
      firstField.focus();
    }
  }

  function closeModal() {
    const modal = qs('#mm-auth-modal');
    if (!modal) return;

    // If focus is inside the modal, move it out before hiding (prevents aria-hidden console warnings).
    try {
      const active = document.activeElement;
      if (active && modal.contains(active)) {
        if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
          lastActiveElement.focus();
        } else if (document.body && typeof document.body.focus === 'function') {
          document.body.focus();
        } else if (active && typeof active.blur === 'function') {
          active.blur();
        }
      }
    } catch (e) {
      // ignore
    }

    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    try { modal.inert = true; } catch (e) { /* ignore */ }
    document.documentElement.style.overflow = '';
    setBackgroundInert(false);
    isOpen = false;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const modal = qs('#mm-auth-modal');
    if (!modal) return;

    qsa('[data-mm-auth-close]', modal).forEach((el) => {
      el.addEventListener('click', function () {
        closeModal();
      });
    });

    qsa('[data-mm-auth-tab]', modal).forEach((tab) => {
      tab.addEventListener('click', function () {
        setMode(modal, tab.getAttribute('data-mm-auth-tab') || 'login');
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();

      if (e.key !== 'Tab') return;
      if (!isOpen) return;

      const modal = qs('#mm-auth-modal');
      if (!modal) return;

      const focusable = getFocusable(modal);
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;

      // Cycle focus within the modal.
      if (e.shiftKey) {
        if (active === first || !modal.contains(active)) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (active === last) {
          e.preventDefault();
          first.focus();
        }
      }
    });

    // Global click handler for auth links.
    document.addEventListener('click', function (e) {
      const target = e.target instanceof Element ? e.target.closest('[data-auth]') : null;
      if (!target) return;

      const mode = target.getAttribute('data-auth') || 'login';
      const href = target.getAttribute('href') || '';
      const redirectTo = stripAuthParams(window.location.href);

      // Keep no-JS functionality via href.
      e.preventDefault();
      openModal(mode, redirectTo);

      // Close mobile menu if open.
      const primaryMenu = qs('#primary-menu');
      if (primaryMenu) primaryMenu.classList.remove('active');
      const toggle = qs('.menu-toggle');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });

    // URL triggers (?login=1 / ?register=1)
    try {
      const u = new URL(window.location.href);
      const isLogin = u.searchParams.has('login');
      const isRegister = u.searchParams.has('register');
      if (isLogin || isRegister) {
        const mode = isRegister ? 'register' : 'login';
        const redirect = u.searchParams.get('redirect_to') || stripAuthParams(window.location.href);
        openModal(mode, redirect);
      }
    } catch (e) {
      // ignore
    }
  });
})();


