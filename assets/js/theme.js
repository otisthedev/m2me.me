/**
 * Match Me Theme JavaScript
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const header = document.querySelector('#masthead');
        const menuToggle = document.querySelector('.menu-toggle');
        const mobileMenu = document.querySelector('.mm-mobile-menu');
        const mobileOverlay = document.querySelector('.mm-mobile-menu-overlay');
        const mobileList = document.querySelector('#primary-menu-mobile');
        
        if (!menuToggle || !mobileMenu || !mobileOverlay) return;

        function updateHeaderOffset() {
            if (!header) return;
            const h = Math.max(0, Math.round(header.getBoundingClientRect().height || 0));
            if (h > 0) {
                document.documentElement.style.setProperty('--mm-header-offset', h + 'px');
            }
        }

        updateHeaderOffset();
        // Fonts/layout can shift after load; do a delayed measurement too.
        setTimeout(updateHeaderOffset, 150);
        setTimeout(updateHeaderOffset, 600);

        function openMenu() {
            updateHeaderOffset();
            document.body.classList.add('mm-menu-open');
            menuToggle.setAttribute('aria-expanded', 'true');
            mobileMenu.style.display = 'block';
            mobileOverlay.style.display = 'block';
            mobileMenu.setAttribute('aria-hidden', 'false');
        }

        function closeMenu() {
            document.body.classList.remove('mm-menu-open');
            menuToggle.setAttribute('aria-expanded', 'false');
            mobileMenu.style.display = 'none';
            mobileOverlay.style.display = 'none';
            mobileMenu.setAttribute('aria-hidden', 'true');
        }

        menuToggle.addEventListener('click', function() {
            const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
            if (isExpanded) closeMenu();
            else openMenu();
        });

        mobileOverlay.addEventListener('click', closeMenu);
        mobileOverlay.addEventListener('touchstart', closeMenu, {passive: true});

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMenu();
        });

        if (mobileList) {
            const links = mobileList.querySelectorAll('a');
            links.forEach(function(link) {
                link.addEventListener('click', function() {
                    closeMenu();
                });
            });
        }

        // Ensure menu is closed when switching to desktop widths.
        window.addEventListener('resize', function() {
            updateHeaderOffset();
            if (window.innerWidth >= 768) {
                closeMenu();
            }
        });
    });

    // Header notifications (unseen comparisons)
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.querySelector('[data-mm-notifications-btn]');
        const panel = document.querySelector('[data-mm-notifications-panel]');
        const list = document.querySelector('[data-mm-notifications-list]');
        const badge = document.querySelector('[data-mm-notifications-badge]');

        if (!btn || !panel || !list || !badge) return;

        const cfg = window.matchMeTheme || {};
        const restUrl = cfg.restUrl || '';
        const nonce = cfg.restNonce || '';

        function setBadge(n) {
            const count = Number(n) || 0;
            badge.textContent = String(count);
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
        }

        function render(items) {
            const arr = Array.isArray(items) ? items : [];
            if (arr.length === 0) {
                list.innerHTML = '<div class="mm-notifications-empty">No new notifications.</div>';
                return;
            }

            list.innerHTML = arr.map(function(it) {
                const name = it && it.viewer && it.viewer.name ? it.viewer.name : 'Someone';
                const avatar = it && it.viewer && it.viewer.avatar_url ? it.viewer.avatar_url : '';
                const ago = it && it.ago ? it.ago : '';
                const url = it && it.share_url ? it.share_url : '#';
                const match = typeof it.match_score === 'number' ? Math.round(it.match_score) : null;
                const sub = [ago, match !== null ? (match + '% match') : ''].filter(Boolean).join(' • ');

                return (
                    '<a class="mm-notifications-item" href="' + String(url).replace(/"/g, '&quot;') + '">' +
                        '<span class="mm-notifications-avatar">' +
                            (avatar ? '<img src="' + String(avatar).replace(/"/g, '&quot;') + '" alt="" loading="lazy" decoding="async">' : '<span>' + String(name).slice(0,1) + '</span>') +
                        '</span>' +
                        '<span class="mm-notifications-text">' +
                            '<span class="mm-notifications-line"><strong>' + String(name) + '</strong> did a comparison</span>' +
                            (sub ? '<span class="mm-notifications-sub">' + String(sub) + '</span>' : '') +
                        '</span>' +
                    '</a>'
                );
            }).join('');
        }

        function api(path, opts) {
            if (!restUrl) return Promise.reject(new Error('Missing restUrl'));
            const url = restUrl.replace(/\/+$/, '') + '/match-me/v1' + path;
            const o = Object.assign({ credentials: 'same-origin' }, opts || {});
            o.headers = Object.assign({ 'Content-Type': 'application/json' }, o.headers || {});
            if (nonce) o.headers['X-WP-Nonce'] = nonce;
            return fetch(url, o).then(function(r) { return r.json(); });
        }

        function refresh() {
            return api('/notifications/comparisons?limit=10', { method: 'GET' })
                .then(function(data) {
                    setBadge(data && data.unseen_count ? data.unseen_count : 0);
                    render(data && data.items ? data.items : []);
                })
                .catch(function() {
                    setBadge(0);
                });
        }

        function markSeen() {
            return api('/notifications/comparisons/seen', { method: 'POST', body: '{}' })
                .then(function() { setBadge(0); })
                .catch(function() {});
        }

        function open() {
            btn.setAttribute('aria-expanded', 'true');
            panel.style.display = 'block';
            panel.setAttribute('aria-hidden', 'false');
            markSeen();
        }

        function close() {
            btn.setAttribute('aria-expanded', 'false');
            panel.style.display = 'none';
            panel.setAttribute('aria-hidden', 'true');
        }

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const isOpen = btn.getAttribute('aria-expanded') === 'true';
            if (isOpen) close();
            else open();
        });

        document.addEventListener('click', function(e) {
            if (!panel.contains(e.target) && !btn.contains(e.target)) {
                close();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') close();
        });

        refresh();
        // light polling while on page
        setInterval(refresh, 60000);
    });

})();


