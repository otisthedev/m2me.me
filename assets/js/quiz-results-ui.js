/**
 * Mobile-first UI components for quiz results and matching.
 */
(function() {
    'use strict';

    /**
     * Render quiz result display.
     *
     * @param {Object} result - Result data from API
     * @param {HTMLElement} container - Container element
     */
    function renderResult(result, container) {
        container.innerHTML = '';

        // Result summary
        const summaryEl = document.createElement('div');
        summaryEl.className = 'match-me-result-summary';
        summaryEl.innerHTML = `
            <h2>Your Results</h2>
            <p class="result-summary-text">${result.textual_summary || 'Quiz completed successfully.'}</p>
            <div class="trait-breakdown">
                ${renderTraitBreakdown(result.trait_summary || {})}
            </div>
        `;
        container.appendChild(summaryEl);

        // Share section
        if (result.share_token) {
            const shareSection = renderShareSection(result.share_token, result.share_urls);
            container.appendChild(shareSection);
        }

        // Compare CTA
        if (result.can_compare) {
            const compareSection = renderCompareSection(result.share_token);
            container.appendChild(compareSection);
        }
    }

    /**
     * Render trait breakdown visualization.
     */
    function renderTraitBreakdown(traitSummary) {
        const traits = Object.entries(traitSummary);
        if (traits.length === 0) {
            return '<p>No trait data available.</p>';
        }

        return traits.map(([trait, value]) => {
            const percentage = Math.round(value * 100);
            const label = trait.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return `
                <div class="trait-item">
                    <div class="trait-label">
                        <span>${label}</span>
                        <span class="trait-value">${percentage}%</span>
                    </div>
                    <div class="trait-bar">
                        <div class="trait-bar-fill" style="width: ${percentage}%"></div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Render share section.
     */
    function renderShareSection(shareToken, shareUrls) {
        const section = document.createElement('div');
        section.className = 'match-me-share-section';
        section.innerHTML = `
            <h3>Share Your Results</h3>
            <div class="share-buttons">
                <button class="btn-share-view" data-url="${shareUrls?.view || ''}">
                    Share View Link
                </button>
                <button class="btn-share-compare" data-url="${shareUrls?.compare || ''}">
                    Share Compare Link
                </button>
            </div>
            <div class="share-url-display" style="display: none;">
                <input type="text" readonly class="share-url-input">
                <button class="btn-copy-url">Copy</button>
            </div>
        `;

        // Add event listeners
        section.querySelectorAll('.btn-share-view, .btn-share-compare').forEach(btn => {
            btn.addEventListener('click', function() {
                const url = this.getAttribute('data-url');
                showShareUrl(section, url);
            });
        });

        const copyBtn = section.querySelector('.btn-copy-url');
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                const input = section.querySelector('.share-url-input');
                if (input) {
                    input.select();
                    document.execCommand('copy');
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = 'Copy';
                    }, 2000);
                }
            });
        }

        return section;
    }

    /**
     * Show share URL input.
     */
    function showShareUrl(section, url) {
        const display = section.querySelector('.share-url-display');
        const input = section.querySelector('.share-url-input');
        if (display && input) {
            input.value = url;
            display.style.display = 'block';
        }
    }

    /**
     * Render compare section.
     */
    function renderCompareSection(shareToken) {
        const section = document.createElement('div');
        section.className = 'match-me-compare-section';
        section.innerHTML = `
            <h3>Compare with Others</h3>
            <p>Share your results link or have someone take the quiz to see how you match!</p>
            <button class="btn-compare-cta">Compare Results</button>
        `;

        const btn = section.querySelector('.btn-compare-cta');
        if (btn) {
            btn.addEventListener('click', () => {
                showCompareDialog(shareToken);
            });
        }

        return section;
    }

    /**
     * Show compare dialog.
     */
    function showCompareDialog(shareToken) {
        // In a real implementation, this would show a dialog/modal
        // For now, just log
        console.log('Compare dialog for token:', shareToken);
        alert('Compare feature: Enter the other person\'s share token or have them take the quiz.');
    }

    /**
     * Render match comparison result.
     *
     * @param {Object} matchResult - Match result from API
     * @param {HTMLElement} container - Container element
     */
    function renderMatchResult(matchResult, container) {
        container.innerHTML = '';

        const matchScore = Math.round(matchResult.match_score || 0);
        const matchEl = document.createElement('div');
        matchEl.className = 'match-me-match-result';
        matchEl.innerHTML = `
            <h2>Match Score: ${matchScore}%</h2>
            <div class="match-breakdown">
                ${renderMatchBreakdown(matchResult.breakdown || {})}
            </div>
        `;
        container.appendChild(matchEl);
    }

    /**
     * Render match breakdown.
     */
    function renderMatchBreakdown(breakdown) {
        if (!breakdown.traits) {
            return '<p>No breakdown available.</p>';
        }

        const traits = Object.entries(breakdown.traits);
        return traits.map(([trait, data]) => {
            const similarity = Math.round((data.similarity || 0) * 100);
            const label = trait.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return `
                <div class="match-trait-item">
                    <div class="match-trait-label">${label}</div>
                    <div class="match-trait-similarity">${similarity}% similar</div>
                    <div class="match-trait-values">
                        <span>You: ${Math.round((data.a || 0) * 100)}%</span>
                        <span>Other: ${Math.round((data.b || 0) * 100)}%</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Export to global scope
    window.MatchMeQuizUI = {
        renderResult: renderResult,
        renderMatchResult: renderMatchResult
    };
})();


