document.addEventListener('DOMContentLoaded', () => {
    // Check URL for ?register parameter
    const urlParams = new URLSearchParams(window.location.search);
    const showRegisterPopup = urlParams.has('register');

    if (showRegisterPopup) {
        createAndShowPopup();
    }

    function createAndShowPopup() {
        // --- Create Popup Elements ---

        // 1. Overlay
        const overlay = document.createElement('div');
        overlay.id = 'registerPopupOverlay';
        overlay.className = 'popup-overlay';

        // 2. Content Container
        const content = document.createElement('div');
        content.className = 'popup-content cq-analyzing-animation';

        // 3. Close Button
        const closeBtn = document.createElement('button');
        closeBtn.className = 'popup-close-btn';
        closeBtn.setAttribute('aria-label', 'Close Registration Popup');
        closeBtn.innerHTML = '&times;';

        // 4. Logo
        const logo = document.createElement('img');
        logo.className = 'popup-logo custom-logo';
        logo.src = 'https://quizalyze.com/wp-content/uploads/2025/03/1-removebg-preview-1-120x36.png';
        logo.alt = 'Quizalyze.com Logo';
        logo.width = 120;
        logo.height = 36;
        logo.loading = 'lazy';
        logo.decoding = 'async';

        // 5. Title
        const title = document.createElement('div');
        title.className = 'cq-analyzing-text';
        title.textContent = 'Register Your Account';

        // 6. Description
        const description = document.createElement('p');
        description.className = 'popup-description';
        description.textContent = 'Choose a method below to create your account:';

        // 7. Google Button
        const googleBtn = document.createElement('button');
        googleBtn.id = 'loginGoogle';
        googleBtn.style.cursor = 'pointer';
        
        const googleIcon = document.createElement('i');
        googleIcon.className = 'fab fa-google';
        googleIcon.style.marginRight = '8px';

        googleBtn.appendChild(googleIcon);
        googleBtn.appendChild(document.createTextNode(' Register with Google'));

        // 8. Facebook Button
        const facebookBtn = document.createElement('button');
        facebookBtn.id = 'loginFacebook';
        facebookBtn.style.cursor = 'pointer';
        
        const facebookIcon = document.createElement('i');
        facebookIcon.className = 'fab fa-facebook-f';
        facebookIcon.style.marginRight = '8px';

        facebookBtn.appendChild(facebookIcon);
        facebookBtn.appendChild(document.createTextNode(' Register with Facebook'));

        // --- Assemble Popup ---
        // content.appendChild(closeBtn);
        content.appendChild(logo);
        content.appendChild(title);
        content.appendChild(description);
        content.appendChild(googleBtn);
        content.appendChild(facebookBtn);
        overlay.appendChild(content);

        // --- Add to Page ---
        document.body.appendChild(overlay);

        // --- Add Event Listeners ---
        const hidePopup = () => {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        };

        // closeBtn.addEventListener('click', hidePopup);

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                hidePopup();
            }
        });

        content.addEventListener('click', (event) => {
            event.stopPropagation();
        });

        googleBtn.addEventListener('click', () => {
            sessionStorage.setItem('quizRedirectURL', window.location.href);
            window.location.href = 'https://quizalyze.com/?google_auth=1';
        });

        facebookBtn.addEventListener('click', () => {
            sessionStorage.setItem('quizRedirectURL', window.location.href);
            window.location.href = 'https://quizalyze.com/?facebook_auth=1';
        });
    }
});