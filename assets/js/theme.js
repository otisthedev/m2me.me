/**
 * Match Me Theme JavaScript
 */
(function() {
    'use strict';

    // Mobile Menu Toggle
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.querySelector('.menu-toggle');
        const primaryMenu = document.querySelector('#primary-menu');
        
        if (menuToggle && primaryMenu) {
            menuToggle.addEventListener('click', function() {
                const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
                
                menuToggle.setAttribute('aria-expanded', !isExpanded);
                primaryMenu.classList.toggle('active');
            });

            // Close menu when clicking outside
            document.addEventListener('click', function(event) {
                if (!menuToggle.contains(event.target) && !primaryMenu.contains(event.target)) {
                    menuToggle.setAttribute('aria-expanded', 'false');
                    primaryMenu.classList.remove('active');
                }
            });

            // Close menu when clicking a menu link (mobile)
            const menuLinks = primaryMenu.querySelectorAll('a');
            menuLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        menuToggle.setAttribute('aria-expanded', 'false');
                        primaryMenu.classList.remove('active');
                    }
                });
            });
        }
    });
})();


