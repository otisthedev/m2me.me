/**
 * Shared clipboard helper.
 * Uses navigator.clipboard when available, falls back to execCommand.
 */
(function () {
  'use strict';

  async function writeText(text) {
    const t = String(text || '');
    if (!t) return false;

    // Modern API (requires secure context)
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      try {
        await navigator.clipboard.writeText(t);
        return true;
      } catch (e) {
        // fall through
      }
    }

    // Fallback (deprecated but widely supported)
    try {
      const ta = document.createElement('textarea');
      ta.value = t;
      ta.setAttribute('readonly', 'true');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      ta.setSelectionRange(0, t.length);
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return !!ok;
    } catch (e) {
      return false;
    }
  }

  window.MatchMeClipboard = { writeText };
})();



