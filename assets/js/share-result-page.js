/**
 * Public share result page renderer.
 * Renders a human-friendly page instead of raw JSON.
 */
(function () {
  'use strict';

  async function main() {
    const token = window.matchMeShareToken;
    const root = document.getElementById('mm-share-result-root');
    if (!token || !root) return;

    root.innerHTML =
      '<div class="mm-result-loading">' +
      '<div class="mm-result-loading-title">Loading result…</div>' +
      '<div class="mm-spinner" aria-hidden="true"></div>' +
      '<div class="mm-result-loading-subtitle">One moment.</div>' +
      '</div>';

    try {
      const result = await window.MatchMeQuiz.getResult(String(token));
      root.innerHTML = '';
      window.MatchMeQuizUI.renderResult(result, root);
    } catch (e) {
      const msg =
        (e && e.message) ||
        (e && e.data && e.data.message) ||
        'Could not load this result.';
      root.innerHTML = `<div class="error-message">${escapeHtml(msg)}</div>`;
    }
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text || '');
    return div.innerHTML;
  }

  document.addEventListener('DOMContentLoaded', main);
})();


