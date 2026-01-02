document.addEventListener('DOMContentLoaded', () => {
    // --- Configuration & Constants ---
    const SELECTORS = {
        quizContainer: '.cq-quiz-container',
        startScreen: '.cq-start-screen',
        questionsContainer: '.cq-questions',
        resultsContainer: '.cq-results',
        previousResultsContainer: '.cq-previous-results',
        startBtn: '.cq-start-btn',
        nextBtn: '.cq-next-btn',
        retakeBtn: '.cq-retake-btn',
        question: '.cq-question',
        answer: '.cq-answer', // Used for delegation target
        answerInput: 'input[type="radio"]', // Used within a question
        // Keep share selectors explicit so we can add new share targets without collisions.
        shareButton: '.share-btn',
        instagramStoryShareButton: '.cq-share-instagram',
        shareActions: '.cq-share-actions',
        postThumbnail: '.post-thumb-img-content',
        progressBar: '.cq-progress-bar', // Class added in initializeQuiz
        progressText: '.cq-progress-text', // Class added in initializeQuiz
        analyzingAnimation: '.cq-analyzing-animation',
        analyzingText: '.cq-analyzing-text',
        analyzingFill: '.cq-progress-fill',
        googleLoginBtn: '#loginGoogle',
        facebookLoginBtn: '#loginFacebook',

    };

    const CLASSES = {
        selectedAnswer: 'selected',
        inProgress: 'in-progress'
    };

    const DURATION = {
        analyzingTimeout: 50, // ms for animation start
        resultsDelay: 8000, // ms before showing results/login prompt (reduced from 8000 for snappiness)
        fadeTransition: 50 // ms for question fade-in
    };

    // --- DOM Element References ---
    const quizContainer = document.querySelector(SELECTORS.quizContainer);
    if (!quizContainer) {
        console.warn('Quiz container element not found.');
        return; // Exit if the main container isn't present
    }

    // Cache frequently accessed elements within the container
    const elements = {
        startScreen: quizContainer.querySelector(SELECTORS.startScreen),
        questionsContainer: quizContainer.querySelector(SELECTORS.questionsContainer),
        resultsContainer: quizContainer.querySelector(SELECTORS.resultsContainer),
        previousResultsContainer: quizContainer.querySelector(SELECTORS.previousResultsContainer),
        startBtn: quizContainer.querySelector(SELECTORS.startBtn),
        nextBtn: quizContainer.querySelector(SELECTORS.nextBtn),
        retakeBtn: quizContainer.querySelector(SELECTORS.retakeBtn),
        // Progress bar/text elements are created dynamically
        progressBar: null,
        progressText: null
    };

    // --- Global Data (from WordPress/backend) ---
    // Assign to local constants for clarity and slightly better performance
    const cqData = window.cqData;
    const cqVars = window.cqVars;
    const ajaxurl = window.ajaxurl; // Assuming this is globally available like cqVars

    if (!cqData || !cqVars || !ajaxurl) {
        console.error('Quiz data (cqData, cqVars, or ajaxurl) is missing.');
        return; // Cannot proceed without essential data
    }

    // --- State Management ---
    let state = {
        currentQuestionIndex: 0,
        scores: {},
        isRetaking: false,
        totalQuestions: cqData.questions.length,
        resultURL: null // Store the generated result URL
    };

    // --- Initialization ---
    function initializeQuizState() {
        state.currentQuestionIndex = 0;
        state.scores = {};
        state.resultURL = null;
        cqData.meta.indicators.forEach(indicator => {
            state.scores[indicator.id] = 0;
        });
    }

    function createProgressUI() {
        if (!elements.progressBar) {
            elements.progressBar = document.createElement('div');
            elements.progressBar.className = SELECTORS.progressBar.substring(1); // Remove leading '.'
            elements.progressText = document.createElement('div');
            elements.progressText.className = SELECTORS.progressText.substring(1); // Remove leading '.'
            quizContainer.prepend(elements.progressText, elements.progressBar); // Add to the top
        }
        elements.progressBar.style.setProperty('--progress-width', '0%');
        elements.progressText.textContent = '';
        elements.progressBar.style.display = 'block';
        elements.progressText.style.display = 'block';
    }

    // --- UI Update Functions ---
    function updateProgress() {
        if (!elements.progressBar || !elements.progressText) return;
        const percent = Math.round(((state.currentQuestionIndex + 1) / state.totalQuestions) * 100);
        elements.progressBar.style.setProperty('--progress-width', `${percent}%`);
        elements.progressText.textContent = `Question ${state.currentQuestionIndex + 1} of ${state.totalQuestions}`;
    }

    function showQuestion(index) {
        const questions = quizContainer.querySelectorAll(SELECTORS.question);
        questions.forEach((q, i) => {
            if (i === index) {
                q.style.display = 'block';
                q.style.opacity = 1;
                // Remove any error messages from previous question
                removeMessages(q);
                // Focus first answer for keyboard navigation
                const firstAnswer = q.querySelector(SELECTORS.answer);
                if (firstAnswer) {
                    const firstInput = firstAnswer.querySelector(SELECTORS.answerInput);
                    if (firstInput) {
                        setTimeout(() => firstInput.focus(), 100);
                    }
                }
            } else {
                q.style.display = 'none';
                q.style.opacity = 0;
            }
        });
        updateProgress();
        // Ensure the next button is only enabled if an answer is selected for the *newly* shown question
        elements.nextBtn.disabled = !quizContainer.querySelector(`${SELECTORS.question}:nth-of-type(${index + 1}) .${CLASSES.selectedAnswer}`);
    }

    function hideFeaturedImage() {
        const imgElement = document.querySelector(SELECTORS.postThumbnail);
        if (imgElement) {
            imgElement.style.display = "none";
        }
    }

    function displayAnalyzingAnimation() {
        quizContainer.classList.add(CLASSES.inProgress);
        if (elements.progressBar) elements.progressBar.style.display = 'none';
        if (elements.progressText) elements.progressText.style.display = 'none';
        if (elements.questionsContainer) elements.questionsContainer.style.display = 'none';

        // Remove existing animation if any
        const existingAnimation = quizContainer.querySelector(SELECTORS.analyzingAnimation);
        if (existingAnimation) existingAnimation.remove();

        const analyzingDiv = document.createElement('div');
        analyzingDiv.className = SELECTORS.analyzingAnimation.substring(1);
        
        // Get logo URL and site name from cqVars
        const logoUrl = cqVars.logoUrl || '';
        const siteName = cqVars.siteName || 'Quiz';
        
        // Create logo element (image or text)
        let logoElement = '';
        if (logoUrl) {
            logoElement = `<img src="${logoUrl}" alt="${siteName}" class="cq-analyzing-logo" decoding="async" loading="lazy">`;
        } else {
            logoElement = `<div class="cq-analyzing-site-name">${siteName}</div>`;
        }
        
        analyzingDiv.innerHTML = `
            ${logoElement}
            <div class="cq-analyzing-progress">
                <div class="cq-progress-fill"></div>
            </div>
            <div class="cq-analyzing-text">
                Analyzing your results<span class="cq-dots"><span>.</span><span>.</span><span>.</span></span>
            </div>
        `;
        quizContainer.appendChild(analyzingDiv);

        // Start the progress bar animation with smooth animation
        requestAnimationFrame(() => {
            setTimeout(() => {
                const fill = analyzingDiv.querySelector(SELECTORS.analyzingFill);
                if (fill) {
                    fill.style.transition = 'width 6s cubic-bezier(0.4, 0, 0.2, 1)';
                    fill.style.width = '100%';
                }
            }, 100);
        });

        return analyzingDiv; // Return the created element
    }

    function displayLoginPrompt(analyzingDiv) {
        const analyzingTextElement = analyzingDiv.querySelector(SELECTORS.analyzingText);
        if (!analyzingTextElement) return;
    
        analyzingTextElement.innerHTML = 'Your Quiz results are ready'; // Clear dots
    
        const message = document.createElement('p');
        message.textContent = 'To view results, please Log in or Register.';
    
        const loginBtn = document.createElement('div');
        loginBtn.id = SELECTORS.googleLoginBtn.substring(1); // Remove #
        loginBtn.innerHTML = '<i class="fab fa-google"></i> Log in with Google'; // Assuming Font Awesome
        loginBtn.style.cursor = 'pointer'; // Make it look clickable
    
        const facebookBtn = document.createElement('div');
        facebookBtn.id = SELECTORS.facebookLoginBtn.substring(1); // Remove #
        facebookBtn.innerHTML = '<i class="fab fa-facebook-f"></i> Log in with Facebook'; // Assuming Font Awesome
        facebookBtn.style.cursor = 'pointer';
    
        analyzingTextElement.parentNode.insertBefore(message, analyzingTextElement.nextSibling);
        analyzingTextElement.parentNode.insertBefore(loginBtn, message.nextSibling);
        analyzingTextElement.parentNode.insertBefore(facebookBtn, loginBtn.nextSibling);
    
        // Add listeners immediately
        loginBtn.addEventListener('click', handleGoogleLoginRedirect);
        facebookBtn.addEventListener('click', handleFacebookLoginRedirect);
    }    

    function displayResults(profileHTML, suggestionsHTML) {
        // Remove animation if it exists
        const analyzingDiv = quizContainer.querySelector(SELECTORS.analyzingAnimation);
        if (analyzingDiv) analyzingDiv.remove();

        // Remove any existing error messages
        removeMessages();

        elements.resultsContainer.innerHTML = profileHTML + suggestionsHTML;
        elements.resultsContainer.style.display = 'block';
        quizContainer.classList.remove(CLASSES.inProgress);

         // Make sure previous results display is hidden if we just finished
         if (elements.previousResultsContainer) elements.previousResultsContainer.style.display = 'none';

        ensureShareUi(elements.resultsContainer);

        // Scroll to results smoothly
        elements.resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function showErrorMessage(message, container = quizContainer) {
        removeMessages(container);
        const errorDiv = document.createElement('div');
        errorDiv.className = 'cq-error-message';
        errorDiv.setAttribute('role', 'alert');
        errorDiv.setAttribute('aria-live', 'polite');
        errorDiv.textContent = message;
        
        const insertPoint = container.querySelector('.cq-questions') || 
                           container.querySelector('.cq-results') || 
                           container.querySelector('.cq-start-screen') ||
                           container;
        insertPoint.insertBefore(errorDiv, insertPoint.firstChild);
        
        // Auto-remove after 8 seconds
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.style.opacity = '0';
                errorDiv.style.transition = 'opacity 0.3s ease-out';
                setTimeout(() => errorDiv.remove(), 300);
            }
        }, 8000);
    }

    function showSuccessMessage(message, container = quizContainer) {
        removeMessages(container);
        const successDiv = document.createElement('div');
        successDiv.className = 'cq-success-message';
        successDiv.setAttribute('role', 'status');
        successDiv.setAttribute('aria-live', 'polite');
        successDiv.textContent = message;
        
        const insertPoint = container.querySelector('.cq-questions') || 
                           container.querySelector('.cq-results') || 
                           container.querySelector('.cq-start-screen') ||
                           container;
        insertPoint.insertBefore(successDiv, insertPoint.firstChild);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (successDiv.parentNode) {
                successDiv.style.opacity = '0';
                successDiv.style.transition = 'opacity 0.3s ease-out';
                setTimeout(() => successDiv.remove(), 300);
            }
        }, 5000);
    }

    function showWarningMessage(message, container = quizContainer) {
        removeMessages(container);
        const warningDiv = document.createElement('div');
        warningDiv.className = 'cq-warning-message';
        warningDiv.setAttribute('role', 'alert');
        warningDiv.setAttribute('aria-live', 'polite');
        warningDiv.textContent = message;
        
        const insertPoint = container.querySelector('.cq-questions') || 
                           container.querySelector('.cq-results') || 
                           container.querySelector('.cq-start-screen') ||
                           container;
        insertPoint.insertBefore(warningDiv, insertPoint.firstChild);
        
        // Auto-remove after 6 seconds
        setTimeout(() => {
            if (warningDiv.parentNode) {
                warningDiv.style.opacity = '0';
                warningDiv.style.transition = 'opacity 0.3s ease-out';
                setTimeout(() => warningDiv.remove(), 300);
            }
        }, 6000);
    }

    function removeMessages(container = quizContainer) {
        const messages = container.querySelectorAll('.cq-error-message, .cq-success-message, .cq-warning-message');
        messages.forEach(msg => msg.remove());
    }

    function displayPreviousResults() {
        if (elements.startScreen) elements.startScreen.style.display = 'none';
        if (elements.previousResultsContainer) {
            elements.previousResultsContainer.style.display = 'block';
            ensureShareUi(elements.previousResultsContainer);
        } else {
            console.warn('Previous results container not found.');
            startQuizFlow(); // Fallback to starting quiz if container missing
        }
    }

    // --- Event Handlers ---
    function handleStartQuiz() {
        if (elements.startScreen) elements.startScreen.style.display = 'none';
        if (elements.questionsContainer) elements.questionsContainer.style.display = 'block';
        hideFeaturedImage();
        initializeQuizState();
        createProgressUI();
        showQuestion(0);
        // Initially disable next button until an answer is selected
        elements.nextBtn.disabled = true;
    }

    function handleRetakeQuiz() {
        state.isRetaking = true;
        if (elements.previousResultsContainer) elements.previousResultsContainer.style.display = 'none';
        // Remove results if they were displayed from a previous run in the same session
        if (elements.resultsContainer) {
            elements.resultsContainer.innerHTML = '';
            elements.resultsContainer.style.display = 'none';
        }
        if (elements.startScreen) elements.startScreen.style.display = 'block'; // Show start screen again
        // No need to call initializeQuiz/setupEventListeners again if they are already set up
        // Just reset the state and UI elements
        initializeQuizState(); // Reset scores etc.
        // Progress UI will be created by handleStartQuiz when the start button is clicked
    }

    function handleAnswerClick(event) {
        const targetAnswer = event.target.closest(SELECTORS.answer);
        if (!targetAnswer) return; // Click wasn't on an answer element

        const currentQuestionElement = targetAnswer.closest(SELECTORS.question);
        if (!currentQuestionElement) return;

        // Remove 'selected' from siblings within the same question
        currentQuestionElement.querySelectorAll(SELECTORS.answer).forEach(ans => {
            ans.classList.remove(CLASSES.selectedAnswer);
            // Ensure radio button reflects visual selection (though click usually handles this)
            const input = ans.querySelector(SELECTORS.answerInput);
            if (input) input.checked = false;
        });

        // Add 'selected' to the clicked answer
        targetAnswer.classList.add(CLASSES.selectedAnswer);
        const radioInput = targetAnswer.querySelector(SELECTORS.answerInput);
        if (radioInput) {
            radioInput.checked = true; // Ensure the radio is checked
            radioInput.setAttribute('aria-checked', 'true');
        }

        // Update other radio buttons in the question
        currentQuestionElement.querySelectorAll(SELECTORS.answerInput).forEach(input => {
            if (input !== radioInput) {
                input.setAttribute('aria-checked', 'false');
            }
        });

        // Enable the 'Next' button
        elements.nextBtn.disabled = false;
        elements.nextBtn.setAttribute('aria-disabled', 'false');
    }

    function handleNextQuestion() {
        const currentQuestionElement = quizContainer.querySelector(`${SELECTORS.question}:nth-of-type(${state.currentQuestionIndex + 1})`);
        if (!currentQuestionElement) return;

        // Find the selected radio button within the *current* question
        const selectedRadio = currentQuestionElement.querySelector(`${SELECTORS.answerInput}:checked`);

        if (selectedRadio && selectedRadio.dataset.scores) {
            try {
                const answerScores = JSON.parse(selectedRadio.dataset.scores);
                Object.entries(answerScores).forEach(([indicator, value]) => {
                    // Ensure value is treated as a number
                    state.scores[indicator] = (state.scores[indicator] || 0) + Number(value);
                });

                if (state.currentQuestionIndex < state.totalQuestions - 1) {
                    state.currentQuestionIndex++;
                    showQuestion(state.currentQuestionIndex);
                } else {
                    // Last question answered, show results process
                    elements.nextBtn.disabled = true; // Disable next after last question
                    finalizeQuiz();
                }
            } catch (error) {
                console.error("Error parsing scores data:", error, selectedRadio.dataset.scores);
                showErrorMessage("An error occurred processing your answer. Please try selecting a different answer.");
            }
        } else {
            // This case should ideally be prevented by disabling the button,
            // but added as a fallback.
            showWarningMessage('Please select an answer before proceeding.');
            console.warn('Next clicked without a selected answer.');
        }
    }

    async function handleShare(event) {
        event.preventDefault(); // Prevent default link behavior

        // Construct the final URL
        // Priority: Hash URL > Stored Result URL > Current Page URL
        let finalURL;
        if (window.location.hash.includes('rsID=')) {
            finalURL = window.location.href;
        } else if (state.resultURL) { // Use the URL saved after successful result save
            finalURL = state.resultURL;
        } else {
            // Fallback if state.resultURL isn't set (e.g., sharing before finishing)
            const storedID = localStorage.getItem('cqResultID'); // Keep LS as fallback? Or remove if state.resultURL is reliable
             finalURL = storedID ? `${window.location.origin}${window.location.pathname}#rsID=${storedID}` : window.location.href.split('#')[0]; // Remove existing hash if no ID
        }

        const pageTitle = document.title || 'Quiz Results';
        const pageDescription = document.querySelector('meta[name="description"]')?.getAttribute('content') || 'Check out my quiz results!';

        if (navigator.share) {
            try {
                await navigator.share({
                    title: pageTitle,
                    text: pageDescription,
                    url: finalURL
                });
                console.log('Content shared successfully');
            } catch (error) {
                // Handle share cancellation or error - often user cancelling is not an error we need to show
                if (error.name !== 'AbortError') {
                     console.error('Error sharing:', error);
                     // Fallback to copy if share API fails unexpectedly
                     copyToClipboard(finalURL, 'Sharing failed. Link copied instead!');
                } else {
                    console.log('Share cancelled by user.');
                }
            }
        } else {
            // Fallback for browsers without navigator.share
            copyToClipboard(finalURL);
        }
    }

    function isMobileSharingContext() {
        // Coarse pointer is the best general proxy for touch devices.
        // The viewport check helps avoid surfacing mobile-only options on larger screens.
        try {
            const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
            const smallish = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
            return Boolean(coarse && smallish);
        } catch (e) {
            return false;
        }
    }

    function ensureShareUi(container) {
        if (!container) return;

        // Only add once per container.
        if (container.querySelector(SELECTORS.shareActions)) return;

        const actions = document.createElement('div');
        actions.className = SELECTORS.shareActions.substring(1);

        // Keep existing share button if present (previous results block already contains it).
        const existingShareBtn = container.querySelector(SELECTORS.shareButton);
        if (existingShareBtn) {
            actions.appendChild(existingShareBtn);
        } else {
            const linkShare = document.createElement('button');
            linkShare.type = 'button';
            linkShare.className = 'share-btn cq-share-link';
            linkShare.textContent = 'Share';
            actions.appendChild(linkShare);
        }

        if (isMobileSharingContext()) {
            const ig = document.createElement('button');
            ig.type = 'button';
            ig.className = SELECTORS.instagramStoryShareButton.substring(1);
            ig.textContent = 'Share to Instagram Story';
            actions.appendChild(ig);
        }

        const note = document.createElement('div');
        note.className = 'cq-share-helper-note';
        note.textContent = isMobileSharingContext()
            ? 'Available on mobile only. On iPhone/iPad this requires HTTPS to open the share sheet. Otherwise we’ll download an image you can post to your Story from Photos/Gallery.'
            : 'Instagram Story sharing is available only on mobile.';
        actions.appendChild(note);

        container.appendChild(actions);
    }

    function extractResultSummaryText() {
        // Prefer profile summary if present.
        const root = (elements.resultsContainer && elements.resultsContainer.style.display !== 'none')
            ? elements.resultsContainer
            : elements.previousResultsContainer;

        if (!root) return '';

        const profile = root.querySelector('.cq-profile-summary');
        const profileText = profile ? (profile.textContent || '').trim() : '';

        // Collect a few “score-like” lines if present.
        const scoreItems = Array.from(root.querySelectorAll('.cq-score-item'))
            .slice(0, 6)
            .map(el => (el.textContent || '').trim())
            .filter(Boolean);

        const parts = [];
        if (profileText) parts.push(profileText);
        if (scoreItems.length) parts.push(scoreItems.join(' • '));

        const combined = parts.join('\n\n').trim();
        if (combined) return combined;

        // Fallback: take a compact chunk of the results text.
        const raw = (root.textContent || '').replace(/\s+/g, ' ').trim();
        return raw.slice(0, 280);
    }

    function wrapTextLines(ctx, text, maxWidth, maxLines) {
        const words = (text || '').split(/\s+/).filter(Boolean);
        const lines = [];
        let current = '';

        for (const w of words) {
            const next = current ? `${current} ${w}` : w;
            if (ctx.measureText(next).width <= maxWidth) {
                current = next;
                continue;
            }

            if (current) lines.push(current);
            current = w;

            if (lines.length >= maxLines) break;
        }

        if (lines.length < maxLines && current) lines.push(current);

        if (lines.length === maxLines && words.length) {
            // Add ellipsis to last line if there is overflow.
            let last = lines[lines.length - 1] || '';
            while (last && ctx.measureText(`${last}…`).width > maxWidth) {
                last = last.slice(0, -1);
            }
            lines[lines.length - 1] = `${last}…`;
        }

        return lines;
    }

    async function renderInstagramStoryPngBlob(title, summary) {
        const width = 1080;
        const height = 1920;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas not supported');

        // Background
        const bg = ctx.createLinearGradient(0, 0, 0, height);
        bg.addColorStop(0, '#fff4e6');
        bg.addColorStop(1, '#ffffff');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, width, height);

        // Top accent
        ctx.fillStyle = '#fd9800';
        ctx.fillRect(0, 0, width, 18);

        const padX = 90;
        let y = 160;

        // Title
        ctx.fillStyle = '#111111';
        ctx.font = '700 64px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        const titleLines = wrapTextLines(ctx, String(title || ''), width - padX * 2, 3);
        for (const line of titleLines) {
            ctx.fillText(line, padX, y);
            y += 78;
        }

        y += 20;

        // Divider
        ctx.fillStyle = 'rgba(0,0,0,0.08)';
        ctx.fillRect(padX, y, width - padX * 2, 2);
        y += 60;

        // Summary
        ctx.fillStyle = '#222222';
        ctx.font = '500 42px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        const summaryLines = wrapTextLines(ctx, String(summary || ''), width - padX * 2, 18);
        for (const line of summaryLines) {
            ctx.fillText(line, padX, y);
            y += 58;
            if (y > height - 260) break;
        }

        // Footer
        ctx.fillStyle = 'rgba(0,0,0,0.55)';
        ctx.font = '600 34px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        ctx.fillText('Match Me', padX, height - 120);

        return await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (!blob) return reject(new Error('Failed to generate image'));
                resolve(blob);
            }, 'image/png', 1);
        });
    }

    async function handleInstagramStoryShare(event) {
        event.preventDefault();

        if (!isMobileSharingContext()) {
            showWarningMessage('Instagram Story sharing is available only on mobile devices.');
            return;
        }

        const title = (cqData && cqData.meta && cqData.meta.title) ? String(cqData.meta.title) : String(document.title || 'Quiz Results');
        const summary = extractResultSummaryText() || 'My quiz results';

        let blob;
        try {
            blob = await renderInstagramStoryPngBlob(title, summary);
        } catch (e) {
            console.error('Failed to render story image:', e);
            showErrorMessage('Could not generate the story image. Please try again.');
            return;
        }

        const file = new File([blob], 'quiz-story.png', { type: 'image/png' });

        // Attempt Web Share API if available, even if canShare() returns false
        // Some browsers/devices support file sharing without canShare() check
        const hasShareAPI =
            typeof navigator !== 'undefined' &&
            typeof navigator.share === 'function' &&
            window.isSecureContext;

        if (hasShareAPI) {
            // Check canShare() as a hint, but don't require it
            const canShareFiles =
                typeof navigator.canShare === 'function' &&
                navigator.canShare({ files: [file] });

            // Try to share even if canShare() returns false
            try {
                await navigator.share({
                    title,
                    files: [file],
                });
                showSuccessMessage('Select Instagram in the share sheet, then choose Story.');
                return;
            } catch (error) {
                // User cancelled - don't show error or fallback
                if (error && error.name === 'AbortError') return;
                console.error('Share failed:', error);
                // Continue to fallback download below
            }
        }

        const isIOS = (() => {
            try {
                const ua = String(navigator.userAgent || '');
                return /iPad|iPhone|iPod/i.test(ua);
            } catch (e) {
                return false;
            }
        })();

        if (isIOS && !window.isSecureContext) {
            showWarningMessage('Direct Instagram sharing from the browser requires HTTPS on iPhone/iPad. Downloading the story image instead.');
        }

        // Fallback: download the image (especially needed on LAN http where Web Share may be unavailable).
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'quiz-story.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);

        // Best-effort: open Instagram Story camera (cannot prefill image from web; user selects from Photos).
        if (isIOS) {
            try {
                // Must be in a user gesture context; this handler is click-driven.
                window.location.href = 'instagram://story-camera';
            } catch (e) {
                // ignore
            }
        }

        showSuccessMessage('Story image downloaded. Instagram should open. If it didn’t, open Instagram → Story → select it from your Photos.');
    }

    function handleGoogleLoginRedirect() {
        if (state.resultURL) {
            sessionStorage.setItem('quizRedirectURL', state.resultURL);
            window.location.href = 'https://quizalyze.com/?google_auth=1'; // Hardcoded URL - maybe move to cqVars?
        } else {
            console.warn('Cannot redirect to Google Login: Result URL not available.');
            showErrorMessage('Could not prepare login redirect. Please try finishing the quiz again.');
        }
    }

    function handleFacebookLoginRedirect() {
        if (state.resultURL) {
            sessionStorage.setItem('quizRedirectURL', state.resultURL);
            window.location.href = 'https://quizalyze.com/?facebook_auth=1'; // Hardcoded URL - maybe move to cqVars?
        } else {
            console.warn('Cannot redirect to Facebook Login: Result URL not available.');
            showErrorMessage('Could not prepare login redirect. Please try finishing the quiz again.');
        }
    }

    // --- Logic Functions ---
    function evaluateCondition(rule, scores) {
        // Basic validation
        if (!rule || typeof rule.indicator === 'undefined' || typeof rule.operator === 'undefined' || typeof rule.value === 'undefined') {
            console.warn('Invalid rule format:', rule);
            return false;
        }
        if (typeof scores[rule.indicator] === 'undefined') {
             console.warn(`Indicator ${rule.indicator} not found in scores.`);
             return false; // Cannot evaluate if indicator score is missing
        }

        // Ensure consistent numeric comparison
        const indicatorValue = Number(scores[rule.indicator]);
        const comparisonValue = Number(rule.value);

        // Check for NaN after conversion
        if (isNaN(indicatorValue) || isNaN(comparisonValue)) {
             console.warn('Invalid numeric values for comparison:', rule, scores[rule.indicator]);
             return false;
        }

        switch (rule.operator) {
            case '>': return indicatorValue > comparisonValue;
            case '<': return indicatorValue < comparisonValue;
            case '>=': return indicatorValue >= comparisonValue;
            case '<=': return indicatorValue <= comparisonValue;
            case '==': // Use '==' for numeric equality after Number() conversion
            case '=':  // Allow '=' as an alias for '=='
                return indicatorValue === comparisonValue;
            default:
                console.warn(`Unsupported operator: ${rule.operator}`);
                return false;
        }
    }

    function evaluateGroup(conditionGroup, scores) {
        // Check if it's a single rule (base case)
        if (!conditionGroup.rules || !conditionGroup.operator) {
            return evaluateCondition(conditionGroup, scores);
        }

        // It's a group, evaluate its rules recursively
        const method = conditionGroup.operator.toUpperCase() === 'AND' ? 'every' : 'some';

        // Check if 'every' or 'some' exists on the rules array
        if (typeof conditionGroup.rules[method] !== 'function') {
            console.warn(`Invalid operator or rules format in condition group:`, conditionGroup);
            return false; // Cannot process if the structure is wrong
        }

        return conditionGroup.rules[method](ruleOrGroup => {
            // If the item has 'rules', it's a nested group, recurse
            // Otherwise, it's a single rule
            return ruleOrGroup.rules ? evaluateGroup(ruleOrGroup, scores) : evaluateCondition(ruleOrGroup, scores);
        });
    }

    function generateProfileDescription(scores) {
        // Ensure cqData and indicators are accessible
        if (!window.cqData || !window.cqData.meta || !window.cqData.meta.indicators) {
            console.error("Quiz indicator metadata is missing.");
            return "Could not generate profile description due to missing data.";
        }
    
        // Sort indicators by the absolute value of the score (descending) to find the strongest alignments
        // Or sort by score if you only want the highest positive scores first: .sort((a, b) => b.score - a.score);
        const sortedIndicators = window.cqData.meta.indicators
            .map(ind => ({
                ...ind,
                score: scores[ind.id] || 0 // Ensure score exists, default to 0
            }))
            // Sorting by absolute value ranks the *strength* of the trait alignment, regardless of direction
             .sort((a, b) => Math.abs(b.score) - Math.abs(a.score));
            // Alternative: Sort by raw score if you prefer highest positive first
            // .sort((a, b) => b.score - a.score);
    
        // Get top 3 indicators based on the sorting
        const topIndicators = sortedIndicators.slice(0, 3);
    
        // Filter out indicators with a score of 0, as they don't indicate a leaning
        const significantIndicators = topIndicators.filter(ind => ind.score !== 0);
    
        // Handle cases with no significant indicators
        if (significantIndicators.length === 0) {
            return "Your profile appears balanced across different areas, or more information is needed to determine key strengths.";
        }
    
        // Generate the list of dominant trait names based on score sign
        const dominantTraits = significantIndicators.map(ind => {
            const parts = ind.label.split(' ↔ ');
            // Ensure parts has two elements before accessing
            if (parts.length === 2) {
                // Determine the dominant trait based on the score's sign
                // Positive score usually means the right side, negative means the left (adjust if convention differs)
                // Assuming Positive score -> Right side trait, Negative score -> Left side trait
                return ind.score > 0 ? parts[1].trim() : parts[0].trim();
            }
            // Fallback if label format is unexpected (doesn't contain ' ↔ ')
            return ind.label;
        });
    
        // Format the list of traits for the sentence
        let traitListString = '';
        if (dominantTraits.length === 1) {
            traitListString = dominantTraits[0];
        } else if (dominantTraits.length === 2) {
            traitListString = `${dominantTraits[0]} and ${dominantTraits[1]}`;
        } else { // 3 or more (though we sliced at 3)
            traitListString = `${dominantTraits.slice(0, -1).join(', ')}, and ${dominantTraits[dominantTraits.length - 1]}`;
        }
    
        // Construct the final description using the trait list only once
        const description = `Your profile highlights key traits such as ${traitListString}. This suggests a professional style influenced by these characteristics.`;
    
        return description;
    }

    function processResults() {
        let matchedResult = null;
        for (const result of cqData.results) {
             // Ensure the result has conditions before trying to evaluate
             if (result.conditions && evaluateGroup(result.conditions, state.scores)) {
                 matchedResult = result;
                 break; // Found the first match
             }
        }

        // Generate Profile Summary HTML
        const profileHTML = `
            <div class="cq-profile-summary">
                <h2>Professional Profile Summary</h2>
                <p>${generateProfileDescription(state.scores)}</p>
            </div>
        `;

        // Generate Suggestions HTML
        let suggestionsHTML = '';
        if (matchedResult && matchedResult.suggestions && matchedResult.suggestions.length > 0) {
            suggestionsHTML = matchedResult.suggestions.map(suggestion => `
                <div class="cq-suggestion">
                    <h3>${suggestion.title || 'Suggestion'}</h3>
                    ${suggestion.description || '<p>No description provided.</p>'}
                </div>`).join('');
        } else {
            // Fallback if no result matched or matched result has no suggestions
            suggestionsHTML = `
                <div class="cq-no-results">
                    <h3>Broad Career Potential</h3>
                    <p>Your results suggest adaptability across various professional domains. Consider exploring roles in Project Management, Business Analysis, or Operations based on your interests.</p>
                    </div>
            `;
        }

        return { profileHTML, suggestionsHTML };
    }

    async function saveResultsToServer() {
        const resultsContent = elements.resultsContainer.innerHTML; // Get HTML after processing

        // Use FormData for potentially simpler structure if needed, or URLSearchParams
        const body = new URLSearchParams({
            action: 'save_quiz_results',
            security: cqVars.nonce,
            quiz_id: quizContainer.dataset.quizId,
            scores: JSON.stringify(state.scores),
            content: resultsContent // Send the generated HTML
        });

        try {
            const response = await fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString() // Convert URLSearchParams to string
            });

            if (!response.ok) {
                // Handle HTTP errors (e.g., 404, 500)
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.data && data.data.inserted_id) {
                // Use the result URL from the server response
                if (data.data.result_url) {
                    state.resultURL = data.data.result_url;
                } else {
                    // Fallback: construct URL client-side if server didn't provide it
                    console.warn('Server did not provide result_url, falling back to client-side construction');
                    const currentUrl = window.location.href;
                    const urlParts = currentUrl.split('/');
                    const lastPart = urlParts[urlParts.length - 2];
                    let baseUrl = currentUrl;
                    
                    if (lastPart && !isNaN(parseInt(lastPart))) {
                        baseUrl = urlParts.slice(0, urlParts.length - 2).join('/');
                    } else {
                        baseUrl = currentUrl.substring(0, currentUrl.lastIndexOf('/'));
                    }
                    
                    state.resultURL = baseUrl + '/' + data.data.inserted_id + '/';
                }
                return { success: true };
            } else {
                 // Handle cases where success is false or data format is wrong
                 console.error('Failed to save results:', data.data?.message || 'Unknown error from server');
                 throw new Error(data.data?.message || 'Failed to save results.');
            }
        } catch (error) {
            console.error('Error saving quiz results:', error);
            const errorMessage = error.message || 'Unknown error';
            showErrorMessage(`Unable to save your results: ${errorMessage}. Please check your connection and try again.`);
            return { success: false, error: error };
        }
    }

    async function finalizeQuiz() {
        const analyzingDiv = displayAnalyzingAnimation();

        // Process results locally first to generate HTML content
        const { profileHTML, suggestionsHTML } = processResults();
        // Temporarily store the HTML in the results container to be sent to the server
        elements.resultsContainer.innerHTML = profileHTML + suggestionsHTML;

        // Save results to the server (includes generating the result URL)
        const saveOutcome = await saveResultsToServer();

        // Wait for the minimum analyzing time to pass
        await new Promise(resolve => setTimeout(resolve, DURATION.resultsDelay));

        if (saveOutcome.success && state.resultURL) {
             // Check if login is required.
             // cqVars.requireLogin may arrive as: true/false, 1/0, "1"/"0".
             const requireLogin = (typeof cqVars.requireLogin === 'undefined')
                ? true
                : Boolean(Number(cqVars.requireLogin)) || cqVars.requireLogin === true;
             
             if (cqVars.isLoggedIn) {
                 // Redirect logged-in users to the new result URL
                 window.location.href = state.resultURL;
             } else if (requireLogin) {
                 // Show login prompt for non-logged-in users if login is required
                 displayLoginPrompt(analyzingDiv);
             } else {
                 // Login not required, show results directly
                 if (analyzingDiv) analyzingDiv.remove();
                 quizContainer.classList.remove(CLASSES.inProgress);
                 displayResults(profileHTML, suggestionsHTML);
             }
        } else {
             // Handle save failure or missing result URL
             // Remove animation, show error or maybe the locally processed results without saving?
             if (analyzingDiv) analyzingDiv.remove();
             quizContainer.classList.remove(CLASSES.inProgress);
             // Display the generated results even if saving failed? Or show an error message?
             // Let's display results but warn the user.
             displayResults(profileHTML, suggestionsHTML); // Show results anyway
             showWarningMessage("Your results were processed, but couldn't be saved. Results are displayed below, but you may want to try again to save them permanently.");
        }
    }

    // --- Utility Functions ---
    async function copyToClipboard(textToCopy, successMessage = 'Link copied to clipboard!') {
        if (navigator.clipboard && window.isSecureContext) { // Use modern API in secure contexts (HTTPS)
            try {
                await navigator.clipboard.writeText(textToCopy);
                alert(successMessage + '\n' + textToCopy);
            } catch (err) {
                console.error('Failed to copy with navigator.clipboard:', err);
                // Fallback to execCommand if modern API fails
                copyToClipboardExecCommand(textToCopy, successMessage);
            }
        } else {
            // Fallback for insecure contexts or older browsers
             copyToClipboardExecCommand(textToCopy, successMessage);
        }
    }

    function copyToClipboardExecCommand(textToCopy, successMessage){
         const tempInput = document.createElement('textarea'); // Use textarea for potentially long URLs
         tempInput.style.position = 'absolute';
         tempInput.style.left = '-9999px'; // Move off-screen
         tempInput.value = textToCopy;
         document.body.appendChild(tempInput);
         tempInput.select();
         tempInput.setSelectionRange(0, 99999); // For mobile devices

         try {
             const successful = document.execCommand('copy');
             if (successful) {
                 alert(successMessage + '\n' + textToCopy);
             } else {
                 console.error('execCommand copy failed');
                 alert('Failed to copy the link automatically. Please copy it manually.');
             }
         } catch (err) {
             console.error('execCommand copy error:', err);
             alert('Failed to copy the link automatically. Please copy it manually.');
         } finally {
             document.body.removeChild(tempInput);
         }
    }


    // --- Event Listener Setup ---
    function setupEventListeners() {
        // Start button
        if (elements.startBtn) {
            elements.startBtn.addEventListener('click', handleStartQuiz);
        } else {
            console.warn('Start button not found.');
        }

        // Next button
        if (elements.nextBtn) {
            elements.nextBtn.addEventListener('click', handleNextQuestion);
        } else {
            console.warn('Next button not found.');
        }

        // Retake button (if exists)
        if (elements.retakeBtn) {
             elements.retakeBtn.addEventListener('click', handleRetakeQuiz);
        } // No warning if not found, it's optional

        // Use event delegation for answer clicks within the questions container
        if (elements.questionsContainer) {
            elements.questionsContainer.addEventListener('click', handleAnswerClick);
        }

        // Use event delegation for share buttons within the main quiz container
        quizContainer.addEventListener('click', (event) => {
            const igButton = event.target.closest(SELECTORS.instagramStoryShareButton);
            if (igButton) {
                handleInstagramStoryShare(event);
                return;
            }

            const shareButton = event.target.closest(SELECTORS.shareButton);
            if (shareButton) {
                handleShare(event);
            }
            // Note: Google Login button listener is added dynamically when the prompt appears
        });
    }

    // --- Main Execution Logic ---
    function runQuiz() {
        // Pre-inject share UI so it’s ready as soon as results/previous-results are shown.
        // (Some pages may not hit displayPreviousResults/displayResults immediately.)
        ensureShareUi(elements.previousResultsContainer);
        ensureShareUi(elements.resultsContainer);

        if (cqVars.hasPrevious && !state.isRetaking) {
            displayPreviousResults();
        } else {
            // Either no previous results, or user clicked retake
             if (elements.previousResultsContainer) elements.previousResultsContainer.style.display = 'none'; // Hide prev results if retaking
             if (elements.startScreen) elements.startScreen.style.display = 'block'; // Ensure start screen is visible
             // State is reset in handleRetakeQuiz or handleStartQuiz
        }
        // Always set up listeners, ensures they're active after retake etc.
        setupEventListeners();
    }

    // --- Start the application ---
    runQuiz();

});