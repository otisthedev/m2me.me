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

        const shareableHeadline = result.shareable_headline || result.textual_summary_short || 'Quiz completed successfully.';
        const shortSummary = result.textual_summary_short || result.textual_summary || 'Quiz completed successfully.';
        const longSummary = result.textual_summary_long || '';

        // ADD: Prominent headline section (viral hook)
        const headlineEl = document.createElement('div');
        headlineEl.className = 'match-me-result-headline';
        headlineEl.innerHTML = `
            <h2 class="result-headline-text">${escapeHtml(shareableHeadline)}</h2>
            <button type="button" class="btn-copy-headline" data-headline="${escapeHtml(shareableHeadline)}">
                Copy this insight
            </button>
        `;
        container.appendChild(headlineEl);

        // Add copy functionality
        headlineEl.querySelector('.btn-copy-headline')?.addEventListener('click', async function() {
            const headline = this.getAttribute('data-headline');
            try {
                await navigator.clipboard.writeText(headline);
                showMessage('Copied! Share this insight anywhere.', 'success');
            } catch (e) {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = headline;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                showMessage('Copied! Share this insight anywhere.', 'success');
            }
        });

        // ADD: Comparison teaser section (creates curiosity)
        const comparisonTeaser = document.createElement('div');
        comparisonTeaser.className = 'match-me-comparison-teaser';
        const canCompare = result.share_urls && result.share_urls.compare;
        comparisonTeaser.innerHTML = `
            <div class="teaser-content">
                <h3>Want to see how you compare?</h3>
                <p>Invite someone to take this quiz and discover your compatibility, communication style match, or relationship dynamics.</p>
                <div class="teaser-benefits">
                    <span class="benefit-item">See your match percentage</span>
                    <span class="benefit-item">Understand your differences</span>
                    <span class="benefit-item">Discover relationship insights</span>
                </div>
                <button type="button" class="btn-teaser-compare" ${canCompare ? '' : 'disabled aria-disabled="true"'} data-share-token="${escapeHtml(result.share_token || '')}">
                    ${canCompare ? 'Compare with someone' : 'Comparison not available'}
                </button>
            </div>
        `;

        // Add click handler
        comparisonTeaser.querySelector('.btn-teaser-compare')?.addEventListener('click', function() {
            if (!canCompare) return;
            showComparisonInviteDialog(result);
        });

        container.appendChild(comparisonTeaser);

        // Result summary
        const summaryEl = document.createElement('div');
        summaryEl.className = 'match-me-result-summary';
        summaryEl.innerHTML = `
            <div class="result-summary-text">
                ${renderLongSummary(longSummary || shortSummary)}
            </div>
            <div class="trait-breakdown">
                ${renderTraitBreakdown(result.trait_summary || {}, result.trait_labels || {})}
            </div>
        `;
        container.appendChild(summaryEl);

        // Build a nicer story headline from trait summary (top trait)
        const storyTop = (() => {
            try {
                const entries = Object.entries(result.trait_summary || {});
                if (!entries.length) return null;
                entries.sort((a, b) => (Number(b[1]) - Number(a[1])));
                const [trait, val] = entries[0];
                const pct = Math.round(Number(val) * 100);
                const label = (result.trait_labels && result.trait_labels[trait]) ? String(result.trait_labels[trait]) : String(trait).replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                return { label, pct };
            } catch (e) {
                return null;
            }
        })();

        // Share section with updated CTAs
        if (result.share_token) {
            // Get top traits for story visualization
            const topTraits = (() => {
                try {
                    const entries = Object.entries(result.trait_summary || {});
                    if (!entries.length) return [];
                    entries.sort((a, b) => (Number(b[1]) - Number(a[1])));
                    return entries.slice(0, 3).map(([trait, value]) => ({
                        trait,
                        label: (result.trait_labels && result.trait_labels[trait]) || trait.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()),
                        value: Number(value)
                    }));
                } catch (e) {
                    return [];
                }
            })();

            const shareSection = renderUnifiedShareSection({
                kind: 'result',
                title: 'Other ways to share',
                urls: {
                    view: (result.share_urls && result.share_urls.view) ? String(result.share_urls.view) : '',
                    compare: (result.share_urls && result.share_urls.compare) ? String(result.share_urls.compare) : '',
                },
                defaultKey: 'compare',
                instagramTitle: String(result.quiz_title || 'Quiz Results'),
                instagramSummary: String(result.shareable_headline || result.textual_summary_short || result.textual_summary || 'My quiz results'),
                shareableHeadline: result.shareable_headline || '',
                quizTitle: result.quiz_title || '',
                quizAspect: result.quiz_aspect || 'default',
                storyData: {
                    headline: result.shareable_headline || 'Result',
                    bigValue: storyTop ? `${storyTop.pct}%` : '',
                    secondary: storyTop ? `${storyTop.label}` : '',
                    topTraits: topTraits,
                    name: (window.matchMeTheme && window.matchMeTheme.currentUser && window.matchMeTheme.currentUser.name) ? String(window.matchMeTheme.currentUser.name) : 'You',
                    avatarUrl: (window.matchMeTheme && window.matchMeTheme.currentUser && window.matchMeTheme.currentUser.avatarUrl) ? String(window.matchMeTheme.currentUser.avatarUrl) : '',
                },
            });
            container.appendChild(shareSection);
        }
    }

    /**
     * Show comparison invite dialog
     */
    function showComparisonInviteDialog(result) {
        const dialog = document.createElement('div');
        dialog.className = 'match-me-invite-dialog';
        const shareableHeadline = result.shareable_headline || result.textual_summary_short || '';
        const quizTitle = result.quiz_title || 'quiz';
        const compareUrl = (result.share_urls && result.share_urls.compare) ? String(result.share_urls.compare) : '';
        
        dialog.innerHTML = `
            <div class="dialog-overlay"></div>
            <div class="dialog-content">
                <h3>Invite someone to compare</h3>
                <p class="dialog-description">Share this link with someone you want to compare results with. When they complete the quiz, you'll both see your match breakdown.</p>
                
                <div class="invite-message-preview">
                    <p class="preview-label">They'll receive this message:</p>
                    <div class="preview-text">
                        "I took the ${escapeHtml(quizTitle)} and thought you might find it interesting. Want to see how we compare? ${escapeHtml(shareableHeadline)}"
                    </div>
                </div>
                
                <div class="invite-actions">
                    <button type="button" class="btn-invite-copy" data-url="${escapeHtml(compareUrl)}">
                        Copy comparison link
                    </button>
                    <button type="button" class="btn-invite-share" data-url="${escapeHtml(compareUrl)}" data-headline="${escapeHtml(shareableHeadline)}" data-quiz-title="${escapeHtml(quizTitle)}">
                        Share via message
                    </button>
                    <button type="button" class="btn-invite-close">Cancel</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(dialog);
        
        // Copy link handler
        dialog.querySelector('.btn-invite-copy')?.addEventListener('click', async function() {
            const url = this.getAttribute('data-url');
            if (!url) {
                showMessage('Comparison link not available.', 'warning');
                return;
            }
            try {
                await navigator.clipboard.writeText(url);
                showMessage('Link copied! Send it to someone to compare.', 'success');
                dialog.remove();
            } catch (e) {
                showMessage('Could not copy link.', 'warning');
            }
        });
        
        // Share handler
        dialog.querySelector('.btn-invite-share')?.addEventListener('click', async function() {
            const url = this.getAttribute('data-url');
            const headline = this.getAttribute('data-headline') || '';
            const quizTitle = this.getAttribute('data-quiz-title') || 'quiz';
            if (!url) {
                showMessage('Comparison link not available.', 'warning');
                return;
            }
            const message = `I took the ${quizTitle} and thought you might find it interesting. Want to see how we compare? ${headline}\n\n${url}`;
            try {
                if (navigator.share) {
                    await navigator.share({ text: message });
                    dialog.remove();
                } else {
                    // Fallback to copy
                    await navigator.clipboard.writeText(message);
                    showMessage('Message copied! Paste it in your messaging app.', 'success');
                    dialog.remove();
                }
            } catch (e) {
                showMessage('Could not share.', 'warning');
            }
        });
        
        // Close handler
        dialog.querySelector('.btn-invite-close')?.addEventListener('click', () => dialog.remove());
        dialog.querySelector('.dialog-overlay')?.addEventListener('click', () => dialog.remove());
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
     * Generate context-aware CTA text based on quiz type and aspect.
     * Makes CTAs more relevant and compelling.
     * 
     * @param {string} aspect - Quiz aspect (communication, decision-making, etc.)
     * @param {string} quizTitle - Quiz title
     * @return {object} CTA text variations
     */
    function generateContextualCTAText(aspect, quizTitle) {
        const ctaTexts = {
            'communication-style': {
                primary: 'See how we communicate',
                subtitle: 'Discover your communication style match',
                benefit: 'Understand how your styles align or differ',
            },
            'decision-making-cognitive-style': {
                primary: 'Compare our decision styles',
                subtitle: 'See how we make decisions together',
                benefit: 'Learn how your approaches complement each other',
            },
            'values-belief-systems': {
                primary: 'See our value alignment',
                subtitle: 'Discover what matters most to both of us',
                benefit: 'Understand your shared values and differences',
            },
            'communication': {
                primary: 'See how we communicate',
                subtitle: 'Discover your communication style match',
                benefit: 'Understand how your styles align or differ',
            },
            'decision-making': {
                primary: 'Compare our decision styles',
                subtitle: 'See how we make decisions together',
                benefit: 'Learn how your approaches complement each other',
            },
            'values': {
                primary: 'See our value alignment',
                subtitle: 'Discover what matters most to both of us',
                benefit: 'Understand your shared values and differences',
            },
            'personality': {
                primary: 'Compare our personalities',
                subtitle: 'See how our traits match',
                benefit: 'Discover your compatibility and differences',
            },
            'default': {
                primary: 'Compare with someone',
                subtitle: 'See how you match',
                benefit: 'Discover your compatibility',
            }
        };
        
        return ctaTexts[aspect] || ctaTexts['default'];
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
        const instagramTitle = opts && opts.instagramTitle ? String(opts.instagramTitle) : 'Quiz Results';
        const instagramSummary = opts && opts.instagramSummary ? String(opts.instagramSummary) : '';
        const storyData = (opts && opts.storyData && typeof opts.storyData === 'object') ? opts.storyData : null;

        // Always show the same buttons everywhere:
        // 1) Share as image (Instagram Story)
        // 2) Share comparison link
        // 3) Share result link
        //
        // Mapping:
        // - "comparison link" = match (comparison result) OR compare (take-quiz link)
        // - "result link" = view (result page)
        const comparisonUrl = (urls.match ? String(urls.match) : (urls.compare ? String(urls.compare) : ''));
        const resultUrl = (urls.view ? String(urls.view) : '');

        // Get contextual CTA text
        const aspect = opts?.quizAspect || 'default';
        const quizTitle = opts?.quizTitle || '';
        const ctaText = generateContextualCTAText(aspect, quizTitle);
        const shareableHeadline = opts?.shareableHeadline || instagramSummary || '';

        section.innerHTML = `
            <div class="share-section-header">
                <h3>${escapeHtml(title)}</h3>
                <p class="share-section-subtitle">Discover how you match with friends, partners, or colleagues</p>
            </div>
            <div class="share-buttons">
                <!-- PRIMARY CTA: Comparison (most viral potential) -->
                <button type="button" class="btn-share-compare btn-primary-cta" ${comparisonUrl ? '' : 'disabled aria-disabled="true"'}>
                    <span class="btn-icon">🔗</span>
                    <span class="btn-main-text">${comparisonUrl ? ctaText.primary : 'Comparison not available'}</span>
                    <span class="btn-subtitle">${comparisonUrl ? ctaText.subtitle : 'Complete the quiz first'}</span>
                </button>
                
                <!-- SECONDARY CTA: Instagram Story (visual sharing) -->
                <button type="button" class="btn-share-instagram btn-secondary-cta">
                    <span class="btn-icon">📸</span>
                    <span class="btn-main-text">Share as Instagram Story</span>
                    <span class="btn-subtitle">Create a visual story with your results</span>
                </button>
                
                <!-- TERTIARY CTA: Direct result sharing (less viral) -->
                <button type="button" class="btn-share-view btn-tertiary-cta" ${resultUrl ? '' : 'disabled aria-disabled="true"'}>
                    <span class="btn-icon">🔗</span>
                    <span class="btn-main-text">${resultUrl ? 'Share my results' : 'Result not available'}</span>
                    <span class="btn-subtitle">${resultUrl ? 'Send a link to view your quiz results' : 'Complete the quiz first'}</span>
                </button>
            </div>
            <div class="share-privacy-note">
                <span class="privacy-icon">🔒</span>
                <span>All sharing is private and voluntary. You control who sees your results.</span>
            </div>
        `;

        const instagramBtn = section.querySelector('.btn-share-instagram');
        if (instagramBtn) {
            instagramBtn.addEventListener('click', async function() {
                // Prefer sharing a comparison-capable link in the story (match > compare > view)
                const u = comparisonUrl || resultUrl;
                if (!u) return;
                await handleInstagramStoryShareV2({
                    kind,
                    title: instagramTitle,
                    summary: instagramSummary,
                    shareUrl: u,
                    storyData,
                });
            });
        }

        async function shareLink(url, shareType = 'compare') {
            const u = String(url || '');
            if (!u) return;
            try {
                if (navigator.share) {
                    const shareTitle = kind === 'match' ? 'Our Comparison Result' : 'My Quiz Results';
                    
                    // Generate context-rich share text based on type
                    let text = '';
                    if (shareType === 'compare') {
                        const headline = opts?.shareableHeadline || shareableHeadline || '';
                        const quizTitle = opts?.quizTitle || '';
                        text = `I took the ${quizTitle || 'quiz'} and thought you might find it interesting. Want to see how we compare? ${headline}`;
                    } else if (shareType === 'view') {
                        text = shareableHeadline || instagramSummary || 'Check out my quiz results';
                    } else {
                        text = instagramSummary || 'My quiz results';
                    }
                    
                    await navigator.share({ title: shareTitle, text, url: u });
                    return;
                }
            } catch (e) {
                // fall through to copy
            }
            try {
                await copyToClipboard(u);
                showMessage('Link copied. Paste it anywhere to share.', 'success');
            } catch (e) {
                showMessage('Could not share. Please try again.', 'warning');
            }
        }

        const compareBtn = section.querySelector('.btn-share-compare');
        if (compareBtn) {
            compareBtn.addEventListener('click', async function() {
                if (!comparisonUrl) return;
                await shareLink(comparisonUrl, 'compare');
            });
        }

        const viewBtn = section.querySelector('.btn-share-view');
        if (viewBtn) {
            viewBtn.addEventListener('click', async function() {
                if (!resultUrl) return;
                await shareLink(resultUrl, 'view');
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
    async function loadImageToCanvas(ctx, url, x, y, size, clipCircle = true) {
        const u = String(url || '');
        if (!u) return false;
        return await new Promise((resolve) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    if (clipCircle) {
                        ctx.save();
                        ctx.beginPath();
                        ctx.arc(x + size / 2, y + size / 2, size / 2, 0, Math.PI * 2);
                        ctx.closePath();
                        ctx.clip();
                    }
                    // cover
                    const iw = img.naturalWidth || size;
                    const ih = img.naturalHeight || size;
                    const scale = Math.max(size / iw, size / ih);
                    const dw = iw * scale;
                    const dh = ih * scale;
                    const dx = x + (size - dw) / 2;
                    const dy = y + (size - dh) / 2;
                    ctx.drawImage(img, dx, dy, dw, dh);
                    if (clipCircle) ctx.restore();
                    resolve(true);
                } catch (e) {
                    resolve(false);
                }
            };
            img.onerror = () => resolve(false);
            img.src = u;
        });
    }

    /**
     * Get trait color for visual appeal
     */
    function getTraitColor(trait) {
        const colors = {
            'leader': '#3b82f6',
            'organizer': '#10b981',
            'explorer': '#f59e0b',
            'harmonizer': '#ec4899',
            'directness': '#3b82f6',
            'empathy': '#10b981',
            'clarity': '#f59e0b',
        };
        return colors[trait] || '#667eea';
    }

    async function renderInstagramStoryPngBlobV2(data) {
        const width = 1080;
        const height = 1920;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas not supported');

        const kind = data && data.kind ? String(data.kind) : 'result';
        const title = data && data.title ? String(data.title) : 'Quiz Results';
        const sd = data && data.storyData && typeof data.storyData === 'object' ? data.storyData : null;
        
        // Get shareable headline if available
        const headline = (sd && sd.headline) ? String(sd.headline) : (data.summary || title);

        // Enhanced brand background with richer gradients
        const bg = ctx.createLinearGradient(0, 0, width, height);
        bg.addColorStop(0, '#1E2A44');
        bg.addColorStop(0.35, '#2A3A5A');
        bg.addColorStop(0.55, '#6FAFB3');
        bg.addColorStop(0.75, '#7FA9AD');
        bg.addColorStop(1, '#8FAEA3');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, width, height);

        // Enhanced mesh-like blobs with better positioning and colors
        ctx.save();
        try { ctx.filter = 'blur(80px)'; } catch (e) { /* ignore */ }
        ctx.globalAlpha = 0.65;
        drawBlob(ctx, 180, 240, 320, 'rgba(246,245,242,0.24)');
        drawBlob(ctx, 900, 340, 340, 'rgba(111,175,179,0.30)');
        drawBlob(ctx, 680, 1100, 400, 'rgba(143,174,163,0.26)');
        drawBlob(ctx, 500, 1600, 280, 'rgba(111,175,179,0.20)');
        ctx.globalAlpha = 1;
        try { ctx.filter = 'none'; } catch (e) { /* ignore */ }
        ctx.restore();

        // Headline (largest, most prominent)
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 72px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        
        const headlineLines = wrapTextLines(ctx, headline, 900, 3); // Max 3 lines, 900px wide
        
        let yPos = 200;
        headlineLines.forEach((line, i) => {
            ctx.fillText(line, 540, yPos + (i * 90));
        });
        
        // Trait visualization (visual bars instead of just text)
        const topTraits = (sd && sd.topTraits && Array.isArray(sd.topTraits)) ? sd.topTraits : [];
        if (topTraits.length > 0) {
            yPos = 600;
            topTraits.slice(0, 3).forEach((trait, i) => {
                const label = trait.label || trait.trait || '';
                const pct = Math.round((trait.value || 0) * 100);
                
                // Trait label
                ctx.font = '36px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                ctx.fillStyle = '#ffffff';
                ctx.textAlign = 'left';
                ctx.fillText(label, 100, yPos + (i * 180));
                
                // Percentage
                ctx.font = 'bold 48px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText(`${pct}%`, 980, yPos + (i * 180));
                
                // Visual bar
                const barWidth = (pct / 100) * 880;
                const barX = 100;
                const barY = yPos + (i * 180) + 50;
                const barHeight = 20;
                
                // Bar background
                ctx.fillStyle = 'rgba(255, 255, 255, 0.2)';
                ctx.fillRect(barX, barY, 880, barHeight);
                
                // Bar fill
                ctx.fillStyle = getTraitColor(trait.trait || '');
                ctx.fillRect(barX, barY, barWidth, barHeight);
            });
        } else if (sd && sd.bigValue) {
            // Fallback to old format if topTraits not available
            yPos = 600;
            ctx.font = 'bold 120px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(sd.bigValue || '', 540, yPos);
            
            if (sd.secondary) {
                ctx.font = '48px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                ctx.fillText(sd.secondary, 540, yPos + 150);
            }
        }
        
        // CTA at bottom
        yPos = 1600;
        ctx.font = '32px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.fillText('See how you compare', 540, yPos);
        ctx.fillText('Take the quiz →', 540, yPos + 50);
        
        // Subtle branding
        ctx.font = '24px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
        ctx.fillText('match.me', 540, 1850);
        let y = topY + 130;
        for (const line of titleLines) {
            // Add subtle text shadow for depth
            ctx.save();
            ctx.shadowColor = 'rgba(11,18,32,0.15)';
            ctx.shadowBlur = 8;
            ctx.shadowOffsetY = 2;
            ctx.fillText(line, padX, y);
            ctx.restore();
            y += 80;
        }

        const headline = sd && sd.headline ? String(sd.headline) : (kind === 'match' ? 'Match' : 'Result');
        const bigValue = sd && sd.bigValue ? String(sd.bigValue) : '';
        const secondary = sd && sd.secondary ? String(sd.secondary) : '';

        // Enhanced glass card with better glassmorphism
        const cardX = 70;
        const cardY = 540;
        const cardW = width - 140;
        const cardH = 960;
        drawGlassCard(ctx, cardX, cardY, cardW, cardH, 50);

        // Enhanced stat header with better typography
        ctx.fillStyle = 'rgba(246,245,242,0.92)';
        ctx.font = '850 30px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        ctx.textBaseline = 'top';
        ctx.save();
        ctx.shadowColor = 'rgba(11,18,32,0.12)';
        ctx.shadowBlur = 6;
        ctx.shadowOffsetY = 2;
        ctx.fillText(headline, cardX + 50, cardY + 90);
        ctx.restore();

        if (bigValue) {
            ctx.fillStyle = '#F6F5F2';
            ctx.font = '950 120px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            ctx.save();
            ctx.shadowColor = 'rgba(11,18,32,0.20)';
            ctx.shadowBlur = 12;
            ctx.shadowOffsetY = 4;
            ctx.fillText(bigValue, cardX + 50, cardY + 200);
            ctx.restore();
        }

        if (secondary) {
            ctx.fillStyle = 'rgba(246,245,242,0.95)';
            ctx.font = '900 48px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            const secLines = wrapTextLines(ctx, secondary, cardW - 100, 2);
            let sy = cardY + 270;
            for (const line of secLines) {
                ctx.save();
                ctx.shadowColor = 'rgba(11,18,32,0.10)';
                ctx.shadowBlur = 4;
                ctx.shadowOffsetY = 2;
                ctx.fillText(line, cardX + 50, sy);
                ctx.restore();
                sy += 58;
            }
        }

        // Avatars
        if (kind === 'match') {
            const aName = sd && sd.aName ? String(sd.aName) : 'Them';
            const bName = sd && sd.bName ? String(sd.bName) : 'You';
            const aAvatar = sd && sd.aAvatar ? String(sd.aAvatar) : '';
            const bAvatar = sd && sd.bAvatar ? String(sd.bAvatar) : '';

            const cy = cardY + 610;
            const leftX = cardX + cardW * 0.34;
            const rightX = cardX + cardW * 0.66;
            const size = 260;

            drawAvatarRing(ctx, leftX, cy, size);
            drawAvatarRing(ctx, rightX, cy, size);
            await loadImageToCanvas(ctx, aAvatar, leftX - size / 2, cy - size / 2, size, true);
            await loadImageToCanvas(ctx, bAvatar, rightX - size / 2, cy - size / 2, size, true);

            // Connector
            ctx.save();
            ctx.shadowColor = 'rgba(11,18,32,0.26)';
            ctx.shadowBlur = 18;
            ctx.shadowOffsetY = 10;
            const cx = cardX + cardW / 2;
            roundRect(ctx, cx - 40, cy - 30, 80, 60, 30);
            ctx.fillStyle = 'rgba(246,245,242,0.18)';
            ctx.fill();
            ctx.lineWidth = 1;
            ctx.strokeStyle = 'rgba(246,245,242,0.26)';
            ctx.stroke();
            ctx.shadowBlur = 0;
            ctx.shadowOffsetY = 0;
            ctx.fillStyle = '#F6F5F2';
            ctx.font = '950 26px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            ctx.textAlign = 'center';
            ctx.fillText('+', cx, cy + 10);
            ctx.textAlign = 'left';
            ctx.restore();

            // Names
            ctx.fillStyle = 'rgba(246,245,242,0.92)';
            ctx.font = '850 28px system-ui, -apple-system, Segoe UI, Roboto, Arial';
            ctx.textAlign = 'center';
            ctx.fillText(aName, leftX, cy + 190);
            ctx.fillText(bName, rightX, cy + 190);
            ctx.textAlign = 'left';
        } else {
            const name = sd && sd.name ? String(sd.name) : ((window.matchMeTheme && window.matchMeTheme.currentUser && window.matchMeTheme.currentUser.name) ? String(window.matchMeTheme.currentUser.name) : 'You');
            const avatarUrl = sd && sd.avatarUrl ? String(sd.avatarUrl) : ((window.matchMeTheme && window.matchMeTheme.currentUser && window.matchMeTheme.currentUser.avatarUrl) ? String(window.matchMeTheme.currentUser.avatarUrl) : '');

            const cx = cardX + cardW / 2;
            const cy = cardY + 650;
            const size = 320;

            drawAvatarRing(ctx, cx, cy, size);
            await loadImageToCanvas(ctx, avatarUrl, cx - size / 2, cy - size / 2, size, true);
            drawNamePill(ctx, cx, cardY + 870, name);
        }

        // Logo (only branding)
        const themeUrl = (window.matchMeTheme && window.matchMeTheme.themeUrl) ? String(window.matchMeTheme.themeUrl) : '';
        const logoUrl = themeUrl ? `${themeUrl.replace(/\/+$/, '')}/assets/img/M2me.me-white.svg` : '';
        if (logoUrl) {
            await loadImageContain(ctx, logoUrl, padX, height - 164, 240, 70);
        }

        return await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    return reject(new Error('Failed to generate image'));
                }
                resolve(blob);
            }, 'image/png', 1);
        });
    }

    function drawBlob(ctx, x, y, r, color) {
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.closePath();
        ctx.fill();
    }

    function drawGrain(ctx, w, h, count) {
        const n = Math.max(0, Math.min(3000, count || 0));
        ctx.save();
        ctx.globalAlpha = 0.08;
        ctx.fillStyle = '#F6F5F2';
        for (let i = 0; i < n; i++) {
            const x = Math.random() * w;
            const y = Math.random() * h;
            const s = Math.random() < 0.86 ? 1 : 2;
            ctx.fillRect(x, y, s, s);
        }
        ctx.restore();
    }

    function drawPill(ctx, x, y, text) {
        ctx.save();
        ctx.font = '900 22px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        const padX = 20;
        const padY = 14;
        const tw = ctx.measureText(text).width;
        const w = tw + padX * 2;
        const h = 24 + padY * 2;
        roundRect(ctx, x, y - h + 6, w, h, h / 2);
        // Enhanced glassmorphism effect
        ctx.fillStyle = 'rgba(246,245,242,0.20)';
        ctx.fill();
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = 'rgba(246,245,242,0.32)';
        ctx.stroke();
        // Add subtle inner highlight
        ctx.fillStyle = 'rgba(246,245,242,0.08)';
        roundRect(ctx, x + 1, y - h + 7, w - 2, h - 2, (h - 2) / 2);
        ctx.fill();
        ctx.fillStyle = 'rgba(246,245,242,0.96)';
        ctx.fillText(text, x + padX, y);
        ctx.restore();
    }

    function drawGlassCard(ctx, x, y, w, h, r) {
        ctx.save();
        // Enhanced shadow for depth
        ctx.shadowColor = 'rgba(11,18,32,0.32)';
        ctx.shadowBlur = 32;
        ctx.shadowOffsetY = 20;
        roundRect(ctx, x, y, w, h, r);
        // Enhanced glassmorphism with gradient overlay
        ctx.fillStyle = 'rgba(246,245,242,0.12)';
        ctx.fill();
        // Add subtle inner border highlight
        ctx.shadowBlur = 0;
        ctx.shadowOffsetY = 0;
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = 'rgba(246,245,242,0.24)';
        ctx.stroke();
        // Inner glow effect
        roundRect(ctx, x + 2, y + 2, w - 4, h - 4, r - 2);
        ctx.fillStyle = 'rgba(246,245,242,0.04)';
        ctx.fill();
        ctx.restore();
    }

    function drawAvatarRing(ctx, cx, cy, size) {
        ctx.save();
        const r = size / 2;
        // Enhanced outer ring with better shadows
        ctx.shadowColor = 'rgba(11,18,32,0.28)';
        ctx.shadowBlur = 30;
        ctx.shadowOffsetY = 16;
        ctx.beginPath(); ctx.arc(cx, cy, r + 20, 0, Math.PI * 2); ctx.closePath();
        ctx.fillStyle = 'rgba(246,245,242,0.14)'; ctx.fill();
        ctx.lineWidth = 2.5; ctx.strokeStyle = 'rgba(246,245,242,0.30)'; ctx.stroke();
        // Enhanced middle ring
        ctx.shadowBlur = 0;
        ctx.shadowOffsetY = 0;
        ctx.beginPath(); ctx.arc(cx, cy, r + 8, 0, Math.PI * 2); ctx.closePath();
        ctx.fillStyle = 'rgba(30,42,68,0.26)'; ctx.fill();
        // Inner highlight ring
        ctx.beginPath(); ctx.arc(cx, cy, r + 3, 0, Math.PI * 2); ctx.closePath();
        ctx.fillStyle = 'rgba(246,245,242,0.06)'; ctx.fill();
        ctx.restore();
    }

    function drawNamePill(ctx, cx, y, name) {
        ctx.save();
        const text = String(name || '').trim() || 'You';
        ctx.font = '950 34px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        const tw = ctx.measureText(text).width;
        const w = Math.min(760, tw + 72);
        const h = 88;
        const x = cx - w / 2;
        // Enhanced shadow
        ctx.shadowColor = 'rgba(11,18,32,0.24)';
        ctx.shadowBlur = 24;
        ctx.shadowOffsetY = 16;
        roundRect(ctx, x, y, w, h, 44);
        // Enhanced fill with subtle gradient effect
        ctx.fillStyle = 'rgba(246,245,242,0.95)';
        ctx.fill();
        ctx.shadowBlur = 0;
        ctx.shadowOffsetY = 0;
        // Subtle border
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = 'rgba(30,42,68,0.12)';
        ctx.stroke();
        ctx.fillStyle = '#1E2A44';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, cx, y + h / 2);
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.restore();
    }

    async function loadImageContain(ctx, url, x, y, w, h) {
        const u = String(url || '');
        if (!u) return false;
        return await new Promise((resolve) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    const iw = img.naturalWidth || w;
                    const ih = img.naturalHeight || h;
                    const scale = Math.min(w / iw, h / ih);
                    const dw = iw * scale;
                    const dh = ih * scale;
                    const dx = x + (w - dw) / 2;
                    const dy = y + (h - dh) / 2;
                    ctx.drawImage(img, dx, dy, dw, dh);
                    resolve(true);
                } catch (e) {
                    resolve(false);
                }
            };
            img.onerror = () => resolve(false);
            img.src = u;
        });
    }

    function roundRect(ctx, x, y, w, h, r) {
        const rr = Math.max(0, Math.min(r, Math.min(w, h) / 2));
        ctx.beginPath();
        ctx.moveTo(x + rr, y);
        ctx.arcTo(x + w, y, x + w, y + h, rr);
        ctx.arcTo(x + w, y + h, x, y + h, rr);
        ctx.arcTo(x, y + h, x, y, rr);
        ctx.arcTo(x, y, x + w, y, rr);
        ctx.closePath();
    }

    /**
     * Handle Instagram Stories sharing
     */
    async function handleInstagramStoryShareV2(data) {
        const isMobile = isMobileSharingContext();

        let blob;
        try {
            blob = await renderInstagramStoryPngBlobV2(data);
        } catch (e) {
            console.error('Failed to render story image:', e);
            showMessage('Could not generate the story image. Please try again.', 'error');
            return;
        }

        const file = new File([blob], 'm2me-story.png', { type: 'image/png' });

        // Desktop/non-mobile: just download (keeps the same button everywhere).
        if (!isMobile) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'm2me-story.png';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 2000);
            showMessage('Image downloaded. Upload it to Instagram Story and add a link sticker.', 'success');
            return;
        }

        // Attempt Web Share API if available
        const hasShareAPI =
            typeof navigator !== 'undefined' &&
            typeof navigator.share === 'function' &&
            window.isSecureContext;

        if (hasShareAPI) {
            try {
                const shareData = {
                    title: (data && data.title) ? String(data.title) : 'Quiz Results',
                    files: [file],
                };
                
                // Add URL if available (some browsers/platforms support this)
                if (data && data.shareUrl) {
                    shareData.url = String(data.shareUrl);
                }
                
                await navigator.share(shareData);
                showMessage('Select Instagram in the share sheet, then choose Story. Add a link sticker with the link you’re sharing.', 'success');
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
        a.download = 'm2me-story.png';
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

        showMessage('Story image downloaded. Instagram should open. If it didn\'t, open Instagram → Story → select it from your Photos. Then add a link sticker with the link you’re sharing.', 'success');
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

    // (Share UI is fully button-based now; no URL input needed.)

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
                    storyData: {
                        headline: 'Match',
                        bigValue: `${matchScore}%`,
                        aName: nameA,
                        bName: nameB,
                        aAvatar: avatarA,
                        bAvatar: avatarB,
                    },
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


