/**
 * Minimal Auth Modal controller
 */
(function () {
  'use strict';

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

  function openModal(mode, redirectTo) {
    const modal = qs('#mm-auth-modal');
    if (!modal) return;

    const cleanRedirect = sanitizeRedirect(stripAuthParams(redirectTo || window.location.href));
    setRedirect(modal, cleanRedirect);
    setMode(modal, mode || 'login');

    modal.style.display = 'block';
    modal.setAttribute('aria-hidden', 'false');
    document.documentElement.style.overflow = 'hidden';
  }

  function closeModal() {
    const modal = qs('#mm-auth-modal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.documentElement.style.overflow = '';
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


