document.addEventListener('DOMContentLoaded', function () {
    if (!aceLoginBlock.is2FAEnabled) {
        return;
    }

    // Handle login button click
    const loginButton = document.querySelector('.wp-block-acemedia-login-block form .wp-block-button__link');
    if (loginButton) {
        loginButton.addEventListener('click', handleLoginAttempt);
    }

    function handleLoginAttempt(event) {
        event.preventDefault();
        const form = event.target.closest('form');
    
        if (form) {
            // Remove existing handler to prevent duplicates
            form.removeEventListener('submit', handleFormSubmit);
            form.addEventListener('submit', handleFormSubmit);
    
            // Add hidden inputs for 2FA state and CSRF
            const formInputs = {
                twoFactorState: createHiddenInput('two_factor_state', 'pending'),
                csrfToken: createHiddenInput('csrf_token', aceLoginBlock.csrfToken),
                twoFactorNonce: createHiddenInput('two_factor_nonce', ''),
                twoFactorVerified: createHiddenInput('two_factor_verified', 'false')
            };
    
            Object.values(formInputs).forEach(input => {
                if (!form.querySelector(`input[name="${input.name}"]`)) {
                    form.appendChild(input);
                }
            });
    
            const usernameInput = form.querySelector('input[name="log"]');
            const username = usernameInput ? usernameInput.value : '';
    
            if (!username) {
                alert('Please enter your username.');
                return;
            }
    
            // Start session timestamp
            const sessionStart = Date.now();
            createHiddenInput('session_start', sessionStart);
    
            // Check if 2FA is enabled for the user
            fetch(aceLoginBlock.check2FAEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': aceLoginBlock.nonce,
                },
                body: JSON.stringify({ 
                    username,
                    timestamp: sessionStart,
                    csrf_token: aceLoginBlock.csrfToken
                }),
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.needs2FASetup) {
                    formInputs.twoFactorState.value = 'setup';
                    formInputs.twoFactorVerified.value = 'true';
                    form.submit();
                } else if (data.is2FAEnabled) {
                    formInputs.twoFactorState.value = 'verification';
                    formInputs.twoFactorNonce.value = data.nonce;
                    show2FAPrompt(form, username, formInputs);
                } else {
                    formInputs.twoFactorState.value = 'disabled';
                    formInputs.twoFactorVerified.value = 'true';
                    form.submit();
                }
            })
            .catch(error => {
                console.error('Error checking 2FA status:', error);
                alert('An error occurred while checking the 2FA status.');
                // Reset form state
                formInputs.twoFactorState.value = 'error';
            });
        }
    }
    
    function createHiddenInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }
    
    function handleFormSubmit(e) {
        const form = e.target;
        const twoFactorVerified = form.querySelector('input[name="two_factor_verified"]');
        const twoFactorState = form.querySelector('input[name="two_factor_state"]');
        
        if (!twoFactorVerified || twoFactorVerified.value !== 'true') {
            e.preventDefault();
            alert('Please complete two-factor authentication.');
            return;
        }
    
        // Check session expiration (30 minute limit)
        const sessionStart = form.querySelector('input[name="session_start"]');
        if (sessionStart && (Date.now() - parseInt(sessionStart.value, 10)) > 1800000) {
            e.preventDefault();
            alert('Session expired. Please refresh and try again.');
            return;
        }
    }

    function show2FAPrompt(form, username) {
        let twoFAContainer = form.querySelector('.wp-block-acemedia-2fa-block');
        if (!twoFAContainer) {
            // Find password field and related elements
            const pwdInput = form.querySelector('input[name="pwd"]');
            const pwdLabel = form.querySelector('label[for="pwd"]');
            const pwdShowToggle = form.querySelector('span[data-show-password="true"]');
    
            // Create 2FA label and input
            const twoFALabel = document.createElement('label');
            twoFALabel.setAttribute('for', '2fa_code');
            twoFALabel.textContent = aceLoginBlock.twoFALabel || 'Enter Authentication Code';
    
            const twoFAInput = document.createElement('input');
            twoFAInput.type = 'text';
            twoFAInput.name = '2fa_code';
            twoFAInput.className = 'tfa-code-input';
            twoFAInput.placeholder = aceLoginBlock.twoFAPlaceholder || 'Authentication Code';
            twoFAInput.required = true;

    
            // Hide password elements
            pwdInput.style.display = 'none';
            if (pwdLabel) pwdLabel.style.display = 'none';
            if (pwdShowToggle) pwdShowToggle.style.display = 'none';
    
            // Insert 2FA elements after password elements
            pwdInput.insertAdjacentElement('afterend', twoFAInput);
            if (pwdLabel) {
                pwdLabel.insertAdjacentElement('afterend', twoFALabel);
            } else {
                pwdInput.parentElement.insertBefore(twoFALabel, pwdInput);
            }
    
            // Handle verification button click
            const verify2FA = () => {
                const twoFACode = twoFAInput.value;
                if (!twoFACode) {
                    alert('Please enter your authentication code.');
                    return;
                }
    
                fetch(aceLoginBlock.verify2FAEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': aceLoginBlock.nonce,
                    },
                    body: JSON.stringify({ code: twoFACode, username }),
                })
                .then((response) => response.json())
                .then((data) => {
                    if (data.success) {
                        form.dataset.twoFactorVerified = 'true';
                        form.submit();
                    } else {
                        alert(data.message || 'Invalid authentication code. Please try again.');
                    }
                })
                .catch((error) => {
                    console.error('2FA verification failed:', error);
                    alert('An error occurred while verifying the authentication code.');
                });
            };
    
            // Replace verify button click handler with login button handler:
const loginButton = form.querySelector('.wp-block-button__link');
loginButton.textContent = aceLoginBlock.submit2FA || 'Verify';
loginButton.removeEventListener('click', handleLoginAttempt);
loginButton.addEventListener('click', (e) => {
    e.preventDefault();
    verify2FA();
});
            
            // Handle enter key in 2FA input
            twoFAInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    verify2FA();
                }
            });
        }
    }

    // Set form action and add nonce
    const loginForm = document.querySelector('.wp-block-login-form form');
    if (loginForm) {
        loginForm.action = aceLoginBlock.loginUrl;
        let redirectInput = loginForm.querySelector('input[name="redirect_to"]');
        if (!redirectInput) {
            redirectInput = document.createElement('input');
            redirectInput.type = 'hidden';
            redirectInput.name = 'redirect_to';
            loginForm.appendChild(redirectInput);
        }
        redirectInput.value = aceLoginBlock.redirectUrl || '/wp-admin';
    }
});