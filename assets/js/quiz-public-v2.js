/**
 * Minimal mobile-first quiz runner (v2) that submits answers to the backend.
 *
 * Requires:
 * - window.matchMeQuizData (quiz json)
 * - window.matchMeQuizVars (nonce, etc.)
 * - window.MatchMeQuiz (AJAX client)
 * - window.MatchMeQuizUI (results UI)
 */
(function () {
  'use strict';

  const quizData = window.matchMeQuizData;
  const quizVars = window.matchMeQuizVars || {};

  const root = document.querySelector('[data-match-me-quiz]');
  if (!root || !quizData) return;

  const quizId = root.getAttribute('data-quiz-id');
  const questions = Array.isArray(quizData.questions) ? quizData.questions : [];

  const els = {
    screen: root.querySelector('.mmq-screen'),
    progress: root.querySelector('.mmq-progress'),
    questionText: root.querySelector('.mmq-question-text'),
    options: root.querySelector('.mmq-options'),
    nextBtn: root.querySelector('.mmq-next'),
    backBtn: root.querySelector('.mmq-back'),
    results: root.querySelector('.mmq-results'),
    error: root.querySelector('.mmq-error'),
  };

  let index = 0;
  const answers = []; // index-aligned {question_id, option_id}

  function setError(msg) {
    if (!els.error) return;
    els.error.textContent = msg || '';
    els.error.style.display = msg ? 'block' : 'none';
  }

  function render() {
    setError('');
    if (!questions[index]) return;

    const q = questions[index];
    const opts = Array.isArray(q.options_json) ? q.options_json : [];

    if (els.progress) {
      els.progress.textContent = `Question ${index + 1} of ${questions.length}`;
    }
    if (els.questionText) {
      els.questionText.textContent = q.text || '';
    }
    if (els.options) {
      els.options.innerHTML = '';
      for (const opt of opts) {
        const label = document.createElement('label');
        label.className = 'mmq-option';

        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'mmq_option';
        input.value = String(opt.id || '');

        const span = document.createElement('span');
        span.textContent = String(opt.text || '');

        label.appendChild(input);
        label.appendChild(span);
        els.options.appendChild(label);
      }

      // Re-select previous answer if present.
      const prev = answers[index];
      if (prev && prev.option_id) {
        const sel = els.options.querySelector(`input[value="${CSS.escape(prev.option_id)}"]`);
        if (sel) {
          sel.checked = true;
          sel.closest('.mmq-option')?.classList.add('selected');
        }
      }

      // Add click handlers for visual feedback
      els.options.querySelectorAll('.mmq-option input[type="radio"]').forEach(input => {
        input.addEventListener('change', function() {
          // Remove selected from all options
          els.options.querySelectorAll('.mmq-option').forEach(opt => {
            opt.classList.remove('selected');
          });
          // Add selected to current option
          if (this.checked) {
            this.closest('.mmq-option')?.classList.add('selected');
          }
        });
      });
    }

    if (els.backBtn) {
      els.backBtn.disabled = index === 0;
    }
    if (els.nextBtn) {
      els.nextBtn.textContent = index === questions.length - 1 ? 'Finish' : 'Next';
    }
  }

  function readSelection() {
    if (!els.options) return null;
    const checked = els.options.querySelector('input[type="radio"]:checked');
    return checked ? checked.value : null;
  }

  async function submit() {
    setError('');
    root.classList.add('mmq-loading');
    try {
      const submitRes = await window.MatchMeQuiz.submitQuiz(quizId, answers, {
        share_mode: 'share_match',
        anonymous_meta: {},
      });

      // Fetch view representation for textual summary + permissions.
      const result = await window.MatchMeQuiz.getResult(submitRes.share_token);
      result.share_token = submitRes.share_token;
      result.share_urls = submitRes.share_urls;

      if (els.results) {
        els.results.style.display = 'block';
        if (els.screen) els.screen.style.display = 'none';
        window.MatchMeQuizUI.renderResult(result, els.results);
      }
    } catch (e) {
      const msg =
        (e && e.message) ||
        (e && e.data && e.data.message) ||
        'Failed to submit quiz. Please try again.';
      setError(msg);
    } finally {
      root.classList.remove('mmq-loading');
    }
  }

  if (els.nextBtn) {
    els.nextBtn.addEventListener('click', async () => {
      const selected = readSelection();
      if (!selected) {
        setError('Please select an answer.');
        return;
      }

      const q = questions[index];
      answers[index] = { question_id: String(q.id || ''), option_id: String(selected) };

      if (index < questions.length - 1) {
        index++;
        render();
      } else {
        await submit();
      }
    });
  }

  if (els.backBtn) {
    els.backBtn.addEventListener('click', () => {
      if (index === 0) return;
      index--;
      render();
    });
  }

  render();
})();


