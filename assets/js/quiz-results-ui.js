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
            <p class="result-summary-text">${escapeHtml(result.textual_summary || 'Quiz completed successfully.')}</p>
            <div class="trait-breakdown">
                ${renderTraitBreakdown(result.trait_summary || {}, result.trait_labels || {})}
            </div>
        `;
        container.appendChild(summaryEl);

        // Share section
        if (result.share_token) {
            const shareSection = renderShareSection(result.share_token, result.share_urls, result);
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
    function renderTraitBreakdown(traitSummary, traitLabels = {}) {
        const traits = Object.entries(traitSummary);
        if (traits.length === 0) {
            return '<p>No trait data available.</p>';
        }

        return traits.map(([trait, value]) => {
            const percentage = Math.round(value * 100);
            // Use trait label from API if available, otherwise format the trait ID
            const label = traitLabels[trait] || trait.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return `
                <div class="trait-item">
                    <div class="trait-label">
                        <span>${escapeHtml(label)}</span>
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
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Render share section.
     */
    function renderShareSection(shareToken, shareUrls, result) {
        const section = document.createElement('div');
        section.className = 'match-me-share-section';
        
        const isMobile = isMobileSharingContext();
        const viewUrl = shareUrls?.view || '';
        const instagramButton = isMobile ? `
            <button class="btn-share-instagram" data-quiz-title="${escapeHtml(result.quiz_title || 'Quiz Results')}" data-summary="${escapeHtml(result.textual_summary || 'My quiz results')}" data-url="${escapeHtml(viewUrl)}">
                Share to Instagram Story
            </button>
        ` : '';
        
        section.innerHTML = `
            <h3>Share Your Results</h3>
            <div class="share-buttons">
                ${instagramButton}
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

        // Add Instagram Stories sharing handler
        const instagramBtn = section.querySelector('.btn-share-instagram');
        if (instagramBtn) {
            instagramBtn.addEventListener('click', async function() {
                const title = this.getAttribute('data-quiz-title') || 'Quiz Results';
                const summary = this.getAttribute('data-summary') || 'My quiz results';
                const shareUrl = this.getAttribute('data-url') || '';
                await handleInstagramStoryShare(title, summary, shareUrl);
            });
        }

        return section;
    }

    /**
     * Check if current context supports mobile sharing
     */
    function isMobileSharingContext() {
        try {
            const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
            const smallish = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
            return !!(coarse && smallish);
        } catch (e) {
            return false;
        }
    }

    /**
     * Wrap text into lines for canvas rendering
     */
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
            let last = lines[lines.length - 1] || '';
            while (last && ctx.measureText(`${last}…`).width > maxWidth) {
                last = last.slice(0, -1);
            }
            lines[lines.length - 1] = `${last}…`;
        }

        return lines;
    }

    /**
     * Generate Instagram Story image as PNG blob
     */
    async function renderInstagramStoryPngBlob(title, summary, shareUrl = '') {
        const width = 1080;
        const height = 1920;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas not supported');

        // Background gradient
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
            if (y > height - 400) break; // Leave more space for URL
        }

        // URL section
        if (shareUrl) {
            y += 40;
            
            // Divider before URL
            ctx.fillStyle = 'rgba(0,0,0,0.08)';
            ctx.fillRect(padX, y, width - padX * 2, 2);
            y += 50;

            // URL label
            ctx.fillStyle = 'rgba(0,0,0,0.6)';
            ctx.font = '500 32px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            ctx.fillText('Take the quiz:', padX, y);
            y += 50;

            // URL text (shortened if needed)
            let urlText = shareUrl;
            if (urlText.length > 40) {
                // Try to extract domain and path
                try {
                    const urlObj = new URL(urlText);
                    urlText = urlObj.hostname.replace('www.', '') + urlObj.pathname;
                    if (urlText.length > 40) {
                        urlText = urlText.substring(0, 37) + '...';
                    }
                } catch (e) {
                    urlText = urlText.substring(0, 37) + '...';
                }
            }

            ctx.fillStyle = '#000000';
            ctx.font = '600 36px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            const urlLines = wrapTextLines(ctx, urlText, width - padX * 2, 2);
            for (const line of urlLines) {
                ctx.fillText(line, padX, y);
                y += 45;
            }

            // Call to action
            y += 20;
            ctx.fillStyle = 'rgba(0,0,0,0.5)';
            ctx.font = '500 28px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            ctx.fillText('Compare your results!', padX, y);
        }

        // Footer
        ctx.fillStyle = 'rgba(0,0,0,0.55)';
        ctx.font = '600 34px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        ctx.fillText('m2me.me', padX, height - 120);

        return await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (!blob) return reject(new Error('Failed to generate image'));
                resolve(blob);
            }, 'image/png', 1);
        });
    }

    /**
     * Handle Instagram Stories sharing
     */
    async function handleInstagramStoryShare(title, summary, shareUrl = '') {
        if (!isMobileSharingContext()) {
            showMessage('Instagram Story sharing is available only on mobile devices.', 'warning');
            return;
        }

        let blob;
        try {
            blob = await renderInstagramStoryPngBlob(title, summary, shareUrl);
        } catch (e) {
            console.error('Failed to render story image:', e);
            showMessage('Could not generate the story image. Please try again.', 'error');
            return;
        }

        const file = new File([blob], 'quiz-story.png', { type: 'image/png' });

        // Attempt Web Share API if available
        const hasShareAPI =
            typeof navigator !== 'undefined' &&
            typeof navigator.share === 'function' &&
            window.isSecureContext;

        if (hasShareAPI) {
            try {
                const shareData = {
                    title: title,
                    files: [file],
                };
                
                // Add URL if available (some browsers/platforms support this)
                if (shareUrl) {
                    shareData.url = shareUrl;
                }
                
                await navigator.share(shareData);
                showMessage('Select Instagram in the share sheet, then choose Story. Add a link sticker with the URL shown on the image.', 'success');
                return;
            } catch (error) {
                // User cancelled - don't show error
                if (error && error.name === 'AbortError') return;
                console.error('Share failed:', error);
                // Continue to fallback
            }
        }

        // Detect platform
        const isIOS = /iPad|iPhone|iPod/i.test(navigator.userAgent);
        const isAndroid = /Android/i.test(navigator.userAgent);

        if ((isIOS || isAndroid) && !window.isSecureContext) {
            showMessage('Direct Instagram sharing from the browser requires HTTPS. Downloading the story image instead.', 'warning');
        }

        // Fallback: download the image
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'quiz-story.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);

        // Best-effort: open Instagram Story camera
        if (isIOS) {
            try {
                window.location.href = 'instagram://story-camera';
            } catch (e) {
                // ignore
            }
        } else if (isAndroid) {
            try {
                // Try Android intent URL
                window.location.href = 'intent://story-camera/#Intent;scheme=instagram;package=com.instagram.android;end';
            } catch (e) {
                // ignore
            }
        }

        showMessage('Story image downloaded. Instagram should open. If it didn\'t, open Instagram → Story → select it from your Photos. Then add a link sticker with the URL shown on the image.', 'success');
    }

    /**
     * Show message to user
     */
    function showMessage(message, type = 'info') {
        // Simple implementation - could be enhanced with better UI
        const msgEl = document.createElement('div');
        msgEl.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 8px;
            background: ${type === 'success' ? '#8FAEA3' : type === 'warning' ? '#EFEFEF' : '#F6F5F2'};
            color: ${type === 'success' ? '#F6F5F2' : '#2B2E34'};
            border: 1px solid ${type === 'warning' ? '#8FAEA3' : '#1E2A44'};
            z-index: 10000;
            font-size: 14px;
            max-width: 90%;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        `;
        msgEl.textContent = message;
        document.body.appendChild(msgEl);
        setTimeout(() => {
            msgEl.remove();
        }, 5000);
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


