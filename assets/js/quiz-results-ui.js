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

        const shortSummary = result.textual_summary_short || result.textual_summary || 'Quiz completed successfully.';
        const longSummary = result.textual_summary_long || '';

        // Result summary
        const summaryEl = document.createElement('div');
        summaryEl.className = 'match-me-result-summary';
        summaryEl.innerHTML = `
            <h2>Your Results</h2>
            <div class="result-summary-text">
                ${renderLongSummary(longSummary || shortSummary)}
            </div>
            <div class="trait-breakdown">
                ${renderTraitBreakdown(result.trait_summary || {}, result.trait_labels || {})}
            </div>
        `;
        container.appendChild(summaryEl);

        // Share section
        if (result.share_token) {
            const shareSection = renderUnifiedShareSection({
                kind: 'result',
                title: 'Share',
                urls: {
                    view: (result.share_urls && result.share_urls.view) ? String(result.share_urls.view) : '',
                    compare: (result.share_urls && result.share_urls.compare) ? String(result.share_urls.compare) : '',
                },
                defaultKey: 'compare',
                instagramTitle: String(result.quiz_title || 'Quiz Results'),
                instagramSummary: String(result.textual_summary_short || result.textual_summary || 'My quiz results'),
            });
            container.appendChild(shareSection);
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
     * Render long-form summary text with simple formatting:
     * - section headings on their own line
     * - bullet lines starting with "- "
     */
    function renderLongSummary(text) {
        const raw = normalizeNarrativeText(String(text || '')).trim();
        if (!raw) return `<p>${escapeHtml('Quiz completed successfully.')}</p>`;

        const paragraphs = raw.split(/\n\s*\n/);
        const out = [];

        const isHeading = (line) => {
            const t = String(line || '').trim();
            return /^[A-Za-z][A-Za-z &/]+$/.test(t) && t.length <= 40;
        };

        for (const p of paragraphs) {
            const lines = p.split('\n').map(l => l.trim()).filter(Boolean);
            if (lines.length === 0) continue;

            if (lines.length === 1 && isHeading(lines[0])) {
                out.push(`<h3 class="mm-result-section-title">${escapeHtml(lines[0])}</h3>`);
                continue;
            }

            const bullets = lines.filter(l => l.startsWith('- '));
            const nonBullets = lines.filter(l => !l.startsWith('- '));

            if (nonBullets.length) {
                out.push(`<p>${escapeHtml(nonBullets.join(' '))}</p>`);
            }

            if (bullets.length) {
                out.push('<ul class="mm-result-bullets">' + bullets.map(b => `<li>${escapeHtml(b.replace(/^-\\s+/, ''))}</li>`).join('') + '</ul>');
            }
        }

        return out.join('');
    }

    /**
     * Normalize narrative text produced by generators so it reads more human:
     * - Remove separator-only lines like "---"
     * - Normalize bullet prefixes (•, ·, "--") to "- "
     * - Trim stray dash spam
     */
    function normalizeNarrativeText(input) {
        const s = String(input || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        const lines = s.split('\n');
        const out = [];

        for (let line of lines) {
            line = String(line || '').trimEnd();
            if (!line.trim()) {
                out.push('');
                continue;
            }

            const t = line.trim();

            // Drop pure separator lines like "---", "— — —", "--", etc.
            if (/^[-–—]{2,}$/.test(t)) continue;
            if (/^[-–—](\s+[-–—]){1,}$/.test(t)) continue;

            // Normalize common bullet prefixes into "- "
            // e.g., "-- item", "- - item", "• item", "· item", "— item"
            if (/^(?:--+|\-\s*\-+)\s+/.test(t)) {
                out.push('- ' + t.replace(/^(?:--+|\-\s*\-+)\s+/, ''));
                continue;
            }
            if (/^[•·]\s+/.test(t)) {
                out.push('- ' + t.replace(/^[•·]\s+/, ''));
                continue;
            }
            if (/^[–—]\s+/.test(t)) {
                out.push('- ' + t.replace(/^[–—]\s+/, ''));
                continue;
            }

            // Fix "- - item" specifically
            if (/^-\s*-\s+/.test(t)) {
                out.push('- ' + t.replace(/^-\s*-\s+/, ''));
                continue;
            }

            // Keep the line as-is (but trim trailing whitespace)
            out.push(line);
        }

        // Collapse 3+ blank lines into max 2
        return out.join('\n').replace(/\n{3,}/g, '\n\n');
    }

    /**
     * Unified share section for results + comparisons (same buttons everywhere).
     *
     * Buttons are consistent:
     * - Instagram Story (mobile only)
     * - Share (Web Share API fallback to copy)
     * - Copy link
     *
     * If multiple link types exist (e.g., view/compare), user can toggle which link is used.
     */
    function renderUnifiedShareSection(opts) {
        const section = document.createElement('div');
        section.className = 'match-me-share-section';

        const kind = opts && opts.kind ? String(opts.kind) : 'result';
        const title = opts && opts.title ? String(opts.title) : 'Share';
        const urls = (opts && opts.urls && typeof opts.urls === 'object') ? opts.urls : {};
        const defaultKey = opts && opts.defaultKey ? String(opts.defaultKey) : Object.keys(urls)[0];
        const instagramTitle = opts && opts.instagramTitle ? String(opts.instagramTitle) : 'Quiz Results';
        const instagramSummary = opts && opts.instagramSummary ? String(opts.instagramSummary) : '';

        const keys = Object.keys(urls).filter(k => urls[k]);
        const hasMultiple = keys.length > 1;
        let currentKey = keys.includes(defaultKey) ? defaultKey : (keys[0] || '');

        const labelFor = (k) => {
            if (k === 'compare') return 'Compare link';
            if (k === 'view') return 'Result link';
            if (k === 'match') return 'Match link';
            return 'Link';
        };

        const currentUrl = () => (currentKey && urls[currentKey]) ? String(urls[currentKey]) : '';

        const selectorHtml = hasMultiple ? `
            <div class="mm-share-selector" role="group" aria-label="Select link type">
                ${keys.map(k => `
                    <button type="button" class="mm-share-type ${k === currentKey ? 'is-active' : ''}" data-mm-share-type="${escapeHtml(k)}">
                        ${escapeHtml(labelFor(k))}
                    </button>
                `).join('')}
            </div>
        ` : '';

        section.innerHTML = `
            <h3>${escapeHtml(title)}</h3>
            ${selectorHtml}
            <div class="share-buttons">
                ${isMobileSharingContext() ? `<button type="button" class="btn-share-instagram">Share to Instagram Story</button>` : ''}
                <button type="button" class="btn-share-native">Share</button>
                <button type="button" class="btn-share-copy">Copy link</button>
            </div>
            <div class="share-url-display">
                <input type="text" readonly class="share-url-input" value="${escapeHtml(currentUrl())}">
            </div>
        `;

        const input = section.querySelector('.share-url-input');
        const updateInput = () => { if (input) input.value = currentUrl(); };

        if (hasMultiple) {
            section.querySelectorAll('[data-mm-share-type]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const k = String(this.getAttribute('data-mm-share-type') || '');
                    if (!k || !urls[k]) return;
                    currentKey = k;
                    section.querySelectorAll('[data-mm-share-type]').forEach(b => b.classList.remove('is-active'));
                    this.classList.add('is-active');
                    updateInput();
                });
            });
        }

        const copyBtn = section.querySelector('.btn-share-copy');
        if (copyBtn) {
            copyBtn.addEventListener('click', async function() {
                const u = currentUrl();
                if (!u) return;
                try {
                    await copyToClipboard(u);
                    showMessage('Link copied!', 'success');
                } catch (e) {
                    if (input) {
                        input.focus();
                        input.select();
                    }
                    showMessage('Select and copy the link.', 'warning');
                }
            });
        }

        const shareBtn = section.querySelector('.btn-share-native');
        if (shareBtn) {
            shareBtn.addEventListener('click', async function() {
                const u = currentUrl();
                if (!u) return;
                try {
                    if (navigator.share) {
                        const shareTitle = kind === 'match' ? 'Comparison Result' : 'Quiz Results';
                        const text = kind === 'match'
                            ? (instagramSummary || 'Comparison result')
                            : (instagramSummary || 'My quiz results');
                        await navigator.share({ title: shareTitle, text, url: u });
                    } else {
                        await copyToClipboard(u);
                        showMessage('Link copied. Paste it anywhere to share.', 'success');
                    }
                } catch (e) {
                    showMessage('Could not share. Try copying the link instead.', 'warning');
                }
            });
        }

        const instagramBtn = section.querySelector('.btn-share-instagram');
        if (instagramBtn) {
            instagramBtn.addEventListener('click', async function() {
                const u = currentUrl();
                if (!u) return;
                await handleInstagramStoryShare(instagramTitle, instagramSummary, u);
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
                showMessage('Select Instagram in the share sheet, then choose Story. Add a link sticker with the comparison URL shown on the image.', 'success');
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

        showMessage('Story image downloaded. Instagram should open. If it didn\'t, open Instagram → Story → select it from your Photos. Then add a link sticker with the comparison URL shown on the image.', 'success');
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
            background: ${type === 'success' ? '#1E2A44' : type === 'warning' ? '#EFEFEF' : '#F6F5F2'};
            color: ${type === 'success' ? '#F6F5F2' : '#2B2E34'};
            border: 1px solid #1E2A44;
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
        // For now, show a simple prompt.
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
        const a = (matchResult.participants && matchResult.participants.a) ? matchResult.participants.a : null;
        const b = (matchResult.participants && matchResult.participants.b) ? matchResult.participants.b : null;
        const nameA = (a && a.name) ? String(a.name) : 'Them';
        const nameB = (b && b.name) ? String(b.name) : 'You';
        const avatarA = (a && a.avatar_url) ? String(a.avatar_url) : '';
        const avatarB = (b && b.avatar_url) ? String(b.avatar_url) : '';
        const shareUrl = matchResult.share_urls && matchResult.share_urls.match ? String(matchResult.share_urls.match) : '';
        const cmpShort = matchResult.comparison_summary_short || '';
        const cmpLong = matchResult.comparison_summary_long || '';

        const matchEl = document.createElement('div');
        matchEl.className = 'match-me-match-result';
        matchEl.innerHTML = `
            <div class="mm-match-hero">
                <div class="mm-match-people">
                    <div class="mm-person">
                        <div class="mm-avatar">
                            ${avatarB ? `<img src="${escapeHtml(avatarB)}" alt="${escapeHtml(nameB)}">` : `<span>${escapeHtml((nameB || 'Y')[0] || 'Y')}</span>`}
                        </div>
                        <div class="mm-person-name">${escapeHtml(nameB)}</div>
                    </div>
                    <div class="mm-match-heart">+</div>
                    <div class="mm-person">
                        <div class="mm-avatar">
                            ${avatarA ? `<img src="${escapeHtml(avatarA)}" alt="${escapeHtml(nameA)}">` : `<span>${escapeHtml((nameA || 'T')[0] || 'T')}</span>`}
                        </div>
                        <div class="mm-person-name">${escapeHtml(nameA)}</div>
                    </div>
                </div>
                <div class="mm-match-score">
                    <div class="mm-match-score-value">${matchScore}%</div>
                    <div class="mm-match-score-label">Match</div>
                </div>
            </div>
            ${(cmpLong || cmpShort) ? `
              <div class="match-me-result-summary">
                <h2>Overview</h2>
                <div class="result-summary-text">
                  ${renderLongSummary(String(cmpLong || cmpShort))}
                </div>
              </div>
            ` : ''}
            <div class="mm-share-mount"></div>
            ${matchResult.you_result && matchResult.you_result.trait_summary ? `
              <div class="match-me-result-summary">
                <h2>Your Results</h2>
                <div class="trait-breakdown">
                  ${renderTraitBreakdown(matchResult.you_result.trait_summary || {}, matchResult.you_result.trait_labels || {})}
                </div>
              </div>
            ` : ''}
            <div class="match-breakdown">
                ${renderMatchBreakdown(matchResult.breakdown || {}, { aName: nameA, bName: nameB })}
            </div>
        `;
        container.appendChild(matchEl);

        if (shareUrl) {
            const mount = container.querySelector('.mm-share-mount');
            if (mount) {
                const shareSection = renderUnifiedShareSection({
                    kind: 'match',
                    title: 'Share',
                    urls: { match: shareUrl },
                    defaultKey: 'match',
                    instagramTitle: 'Comparison Result',
                    instagramSummary: `${nameB} + ${nameA}: ${matchScore}% match`,
                });
                mount.appendChild(shareSection);
            }
        }
    }

    async function copyToClipboard(text) {
        const t = String(text || '');
        if (!t) return;
        if (window.MatchMeClipboard && typeof window.MatchMeClipboard.writeText === 'function') {
            const ok = await window.MatchMeClipboard.writeText(t);
            if (ok) return;
        }
        // If helper is missing or copying failed, throw so caller can show a warning.
        throw new Error('copy_failed');
    }

    /**
     * Render match breakdown.
     */
    function renderMatchBreakdown(breakdown, opts = {}) {
        if (!breakdown.traits) {
            return '<p>No breakdown available.</p>';
        }

        const aName = opts.aName ? String(opts.aName) : 'Them';
        const bName = opts.bName ? String(opts.bName) : 'You';

        function bandFor(simPct) {
            if (simPct >= 97) return 'In sync';
            if (simPct >= 92) return 'Very close';
            if (simPct >= 85) return 'Strongly aligned';
            if (simPct >= 75) return 'Similar';
            if (simPct >= 65) return 'Some overlap';
            return 'Different';
        }

        function clamp01(n) {
            const x = Number(n);
            if (!Number.isFinite(x)) return 0;
            return Math.max(0, Math.min(1, x));
        }

        const traits = Object.entries(breakdown.traits).map(([trait, data]) => {
            const simPct = Math.round(clamp01(data.similarity) * 100);
            const aPct = Math.round(clamp01(data.a) * 100);
            const bPct = Math.round(clamp01(data.b) * 100);
            const gap = Math.abs(aPct - bPct);
            const label = trait.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return { trait, label, simPct, aPct, bPct, gap };
        });

        // Order by gap (largest first) so the content feels more informative,
        // even when similarity percentages are identical across traits.
        traits.sort((x, y) => (y.gap - x.gap) || (y.simPct - x.simPct) || x.label.localeCompare(y.label));

        const simValues = traits.map(t => t.simPct);
        const simMin = Math.min(...simValues);
        const simMax = Math.max(...simValues);
        const clustered = (simMax - simMin) <= 4; // e.g. everything ~95%

        const summary = clustered
            ? `<div class="match-breakdown-summary">You’re consistently aligned across traits (differences are small and steady).</div>`
            : '';

        const items = traits.map(t => {
            const band = bandFor(t.simPct);
            const gapText = t.gap === 0 ? 'Same score' : `Gap: ${t.gap} pts`;
            return `
                <div class="match-trait-item">
                    <div class="match-trait-top">
                        <div class="match-trait-label">${escapeHtml(t.label)}</div>
                        <div class="match-trait-badges">
                            <span class="match-badge match-badge-band">${escapeHtml(band)}</span>
                            <span class="match-badge match-badge-gap">${escapeHtml(gapText)}</span>
                        </div>
                    </div>
                    <div class="match-trait-bars" role="group" aria-label="${escapeHtml(t.label)} comparison">
                        <div class="match-trait-barrow">
                            <div class="match-trait-barlabel">${escapeHtml(bName)}</div>
                            <div class="match-trait-bar"><span class="match-trait-barfill" style="width:${t.bPct}%"></span></div>
                            <div class="match-trait-barvalue">${t.bPct}%</div>
                        </div>
                        <div class="match-trait-barrow">
                            <div class="match-trait-barlabel">${escapeHtml(aName)}</div>
                            <div class="match-trait-bar"><span class="match-trait-barfill is-a" style="width:${t.aPct}%"></span></div>
                            <div class="match-trait-barvalue">${t.aPct}%</div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        return `${summary}${items}`;
    }

    // Export to global scope
    window.MatchMeQuizUI = {
        renderResult: renderResult,
        renderMatchResult: renderMatchResult
    };
})();


