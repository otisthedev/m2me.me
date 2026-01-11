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
  const urlParams = (() => {
    try {
      return new URL(window.location.href).searchParams;
    } catch (e) {
      return null;
    }
  })();
  const compareToken = urlParams ? (urlParams.get('compare_token') || '').trim() : '';
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

  const intro = root.querySelector('.mmq-intro');
  const startBtn = root.querySelector('.mmq-start');
  const isGated = Boolean(intro && startBtn && els.screen);
  let hasStarted = !isGated;
  const requireLogin = Boolean(quizVars && quizVars.requireLogin);
  const isLoggedIn = Boolean(quizVars && quizVars.isLoggedIn);

  function updateUrlFlag(key, valueOrNull) {
    try {
      const u = new URL(window.location.href);
      if (valueOrNull === null) {
        u.searchParams.delete(key);
      } else {
        u.searchParams.set(key, String(valueOrNull));
      }
      window.history.replaceState({}, '', u.toString());
    } catch (e) {
      // ignore
    }
  }

  function openAuth(mode) {
    // Use the existing global [data-auth] click handler to open the auth modal
    // without navigation.
    const a = document.createElement('a');
    a.setAttribute('href', '#');
    a.setAttribute('data-auth', mode || 'register');
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function startQuiz() {
    hasStarted = true;
    if (intro) intro.style.display = 'none';
    if (els.screen) els.screen.style.display = 'block';

    // Add body class to trigger CSS rules that hide WordPress post content
    document.body.classList.add('quiz-active');

    // Clean up the auto-start flag so refreshing doesn't keep forcing behavior.
    updateUrlFlag('mm_start_quiz', null);
    render();
  }

  let index = 0;
  const answers = []; // index-aligned {question_id, option_id}
  let pendingAdvanceTimer = null;
  let isAdvancing = false;

  function setError(msg) {
    if (!els.error) return;
    els.error.textContent = msg || '';
    els.error.style.display = msg ? 'block' : 'none';
  }

  function render() {
    setError('');
    if (!questions[index]) return;

    // Reset any pending auto-advance when re-rendering.
    if (pendingAdvanceTimer) {
      clearTimeout(pendingAdvanceTimer);
      pendingAdvanceTimer = null;
    }
    isAdvancing = false;

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
          if (isAdvancing) return;

          // Remove selected from all options
          els.options.querySelectorAll('.mmq-option').forEach(opt => {
            opt.classList.remove('selected');
          });
          // Add selected to current option
          if (this.checked) {
            this.closest('.mmq-option')?.classList.add('selected');
          }

          // Persist answer for this question
          const qNow = questions[index];
          answers[index] = { question_id: String(qNow.id || ''), option_id: String(this.value) };

          // Auto-advance on selection (except last question -> user hits Finish),
          // but wait 0.5s so the user sees it was selected.
          if (index < questions.length - 1) {
            isAdvancing = true;

            // Temporarily disable inputs to prevent double taps during the delay.
            els.options.querySelectorAll('input[type="radio"]').forEach((el) => {
              el.disabled = true;
            });

            pendingAdvanceTimer = setTimeout(() => {
              pendingAdvanceTimer = null;
              isAdvancing = false;
              index++;
              render();
            }, 500);
          }
        });
      });
    }

    if (els.backBtn) {
      els.backBtn.disabled = index === 0;
    }
    if (els.nextBtn) {
      // UX: Skip on all but last question; Finish only on last.
      els.nextBtn.textContent = index === questions.length - 1 ? 'Finish' : 'Skip';
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
      // If results require login, don't even attempt submit while logged out.
      if (requireLogin && !isLoggedIn) {
        root.classList.remove('mmq-loading');
        setError('Please log in to see your results.');
        updateUrlFlag('mm_start_quiz', '1');
        openAuth('register');
        return;
      }
      // Show generating/loading state immediately to avoid blank screen.
      if (els.results) {
        els.results.style.display = 'block';
        els.results.innerHTML =
          '<div class="mm-result-loading">' +
          '<div class="mm-result-loading-title">Generating your results…</div>' +
          '<div class="mm-spinner" aria-hidden="true"></div>' +
          '<div class="mm-result-loading-subtitle">This usually takes a moment.</div>' +
          '</div>';
      }
      if (els.screen) els.screen.style.display = 'none';

      // Safety check: Verify MatchMeQuiz is available
      if (!window.MatchMeQuiz) {
        root.classList.remove('mmq-loading');
        const msg = 'Quiz system not initialized. Please refresh the page.';
        setError(msg);
        console.error('MatchMeQuiz is not defined. Check if quiz-ajax-client.js loaded correctly.');
        if (els.screen) els.screen.style.display = 'block';
        if (els.results) els.results.style.display = 'none';
        return;
      }

      // Safety check: Verify MatchMeQuizUI is available
      if (!window.MatchMeQuizUI) {
        root.classList.remove('mmq-loading');
        const msg = 'Results display not available. Please refresh the page.';
        setError(msg);
        console.error('MatchMeQuizUI is not defined. Check if quiz-results-ui.js loaded correctly.');
        if (els.screen) els.screen.style.display = 'block';
        if (els.results) els.results.style.display = 'none';
        return;
      }

      const payloadAnswers = answers.filter(Boolean);
      if (compareToken) {
        // Safety check: Verify compareResults method exists
        if (typeof window.MatchMeQuiz.compareResults !== 'function') {
          root.classList.remove('mmq-loading');
          const msg = 'Comparison feature not available. Please refresh the page.';
          setError(msg);
          console.error('MatchMeQuiz.compareResults is not a function.');
          if (els.screen) els.screen.style.display = 'block';
          if (els.results) els.results.style.display = 'none';
          return;
        }

        const matchResult = await window.MatchMeQuiz.compareResults(compareToken, {
          answers: payloadAnswers,
          quiz_id: quizId,
        });
        
        // Safety check: Verify renderMatchResult method exists
        if (els.results) {
          if (typeof window.MatchMeQuizUI.renderMatchResult !== 'function') {
            root.classList.remove('mmq-loading');
            const msg = 'Results display not available. Please refresh the page.';
            setError(msg);
            console.error('MatchMeQuizUI.renderMatchResult is not a function. Check if quiz-results-ui.js loaded correctly.');
            if (els.screen) els.screen.style.display = 'block';
            els.results.style.display = 'none';
            return;
          }
          window.MatchMeQuizUI.renderMatchResult(matchResult, els.results);
        }
      } else {
        // Safety check: Verify submitQuiz method exists
        if (typeof window.MatchMeQuiz.submitQuiz !== 'function') {
          root.classList.remove('mmq-loading');
          const msg = 'Quiz submission not available. Please refresh the page.';
          setError(msg);
          console.error('MatchMeQuiz.submitQuiz is not a function.');
          if (els.screen) els.screen.style.display = 'block';
          if (els.results) els.results.style.display = 'none';
          return;
        }

        const submitRes = await window.MatchMeQuiz.submitQuiz(quizId, payloadAnswers, {
          share_mode: 'share_match',
          anonymous_meta: {},
        });

        // Safety check: Verify getResult method exists
        if (typeof window.MatchMeQuiz.getResult !== 'function') {
          root.classList.remove('mmq-loading');
          const msg = 'Results retrieval not available. Please refresh the page.';
          setError(msg);
          console.error('MatchMeQuiz.getResult is not a function.');
          if (els.screen) els.screen.style.display = 'block';
          if (els.results) els.results.style.display = 'none';
          return;
        }

        // Fetch view representation for textual summary + permissions.
        const result = await window.MatchMeQuiz.getResult(submitRes.share_token);
        result.share_token = submitRes.share_token;
        result.share_urls = submitRes.share_urls;

        // Safety check: Verify renderResult method exists
        if (els.results) {
          if (typeof window.MatchMeQuizUI.renderResult !== 'function') {
            root.classList.remove('mmq-loading');
            const msg = 'Results display not available. Please refresh the page.';
            setError(msg);
            console.error('MatchMeQuizUI.renderResult is not a function. Check if quiz-results-ui.js loaded correctly.');
            if (els.screen) els.screen.style.display = 'block';
            els.results.style.display = 'none';
            return;
          }
          window.MatchMeQuizUI.renderResult(result, els.results);
        }
      }
    } catch (e) {
      const msg =
        (e && e.message) ||
        (e && e.data && e.data.message) ||
        'Failed to submit quiz. Please try again.';
      setError(msg);
      // Restore quiz screen if we couldn't fetch results.
      if (els.screen) els.screen.style.display = 'block';
      if (els.results) els.results.style.display = 'none';
    } finally {
      root.classList.remove('mmq-loading');
    }
  }

  if (els.nextBtn) {
    els.nextBtn.addEventListener('click', async () => {
      if (pendingAdvanceTimer) {
        clearTimeout(pendingAdvanceTimer);
        pendingAdvanceTimer = null;
      }
      isAdvancing = false;

      const selected = readSelection();
      // Last step: must select to finish.
      if (index === questions.length - 1) {
        if (!selected) {
          setError('Please select an answer.');
          return;
        }
        const q = questions[index];
        answers[index] = { question_id: String(q.id || ''), option_id: String(selected) };
        await submit();
        return;
      }

      // Intermediate steps: button acts as Skip (no selection required).
      if (selected) {
        const q = questions[index];
        answers[index] = { question_id: String(q.id || ''), option_id: String(selected) };
      } else {
        answers[index] = null;
      }

      index++;
      render();
    });
  }

  if (els.backBtn) {
    els.backBtn.addEventListener('click', () => {
      if (index === 0) return;
      if (pendingAdvanceTimer) {
        clearTimeout(pendingAdvanceTimer);
        pendingAdvanceTimer = null;
      }
      isAdvancing = false;
      index--;
      render();
    });
  }

  if (isGated) {
    // Hide quiz UI until user explicitly starts.
    if (els.screen) els.screen.style.display = 'none';
    if (els.results) els.results.style.display = 'none';

    const shouldAutoStart = urlParams ? (urlParams.get('mm_start_quiz') === '1') : false;

    if (shouldAutoStart) {
      if (requireLogin && !isLoggedIn) {
        // User intended to start, but must authenticate first.
        openAuth('register');
      } else {
        startQuiz();
      }
    }

    startBtn.addEventListener('click', () => {
      if (requireLogin && !isLoggedIn) {
        // Persist intent so after login/registration redirect, the quiz starts immediately.
        updateUrlFlag('mm_start_quiz', '1');
        openAuth('register');
        return;
      }
      startQuiz();
    });
  } else {
    render();
  }
})();


