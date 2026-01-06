/**
 * Public share result page renderer.
 * Renders a human-friendly page instead of raw JSON.
 */
(function () {
  'use strict';

  async function main() {
    const token = window.matchMeShareToken;
    const mode = window.matchMeShareMode || 'view';
    const comparisonToken = window.matchMeComparisonToken;
    const root = document.getElementById('mm-share-result-root');
    if ((!token && !comparisonToken) || !root) return;

    root.innerHTML =
      '<div class="mm-result-loading">' +
      '<div class="mm-result-loading-title">Loading result…</div>' +
      '<div class="mm-spinner" aria-hidden="true"></div>' +
      '<div class="mm-result-loading-subtitle">One moment.</div>' +
      '</div>';

    try {
      if (String(mode) === 'match') {
        const cmp = await window.MatchMeQuiz.getComparison(String(comparisonToken || ''));
        root.innerHTML = '';
        window.MatchMeQuizUI.renderMatchResult(cmp, root);
        return;
      }

      const result = await window.MatchMeQuiz.getResult(String(token));
      // Ensure share UI shows on past results too (API now returns share_token, but keep this as a safe fallback).
      result.share_token = result.share_token || String(token);
      root.innerHTML = '';

      if (String(mode) === 'compare') {
        if (!result || result.can_compare !== true) {
          root.innerHTML =
            '<div class="error-message">This result does not allow comparison.</div>';
          return;
        }

        const base = (window.matchMeTheme && window.matchMeTheme.homeUrl) ? String(window.matchMeTheme.homeUrl).replace(/\/+$/, '') : '';
        const quizSlug = String(result.quiz_slug || '').trim();
        const quizPath = quizSlug ? `/${encodeURIComponent(quizSlug)}/` : `/`;
        const quizUrl = `${base}${quizPath}?compare_token=${encodeURIComponent(String(token))}`;

        root.innerHTML = `
          <div class="match-me-compare-section">
            <h3>Compare Your Results</h3>
            <p>Take the quiz to compare your results and see how you match.</p>
            <a class="btn-compare-cta" href="${quizUrl}">Start Quiz</a>
          </div>
        `;
        return;
      }

      window.MatchMeQuizUI.renderResult(result, root);
    } catch (e) {
      const defaultMsg = 'Could not load this result.';
      const msg =
        (e && e.message) ||
        (e && e.data && e.data.message) ||
        defaultMsg;
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


