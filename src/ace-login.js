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
            twoFAContainer = document.createElement('div');
            twoFAContainer.className = 'wp-block-acemedia-2fa-block';
            twoFAContainer.innerHTML = `
                <label for="tfa_code">
                    ${aceLoginBlock.twoFALabel || 'Enter Authentication Code'}
                </label>
                <input 
                    type="text" 
                    name="2fa_code" 
                    class="tfa-code-input"
                    placeholder="${aceLoginBlock.twoFAPlaceholder || 'Authentication Code'}" 
                    required 
                />
                <button type="button" class="button verify-2fa">
                    ${aceLoginBlock.submit2FA || 'Verify'}
                </button>
            `;
            form.appendChild(twoFAContainer);

            // Hide other fields
            form.querySelectorAll('input, button, .wp-block-button').forEach((element) => {
                if (!element.closest('.wp-block-acemedia-2fa-block')) {
                    element.style.display = 'none';
                }
            });

            // Handle verification button click
            const verifyButton = twoFAContainer.querySelector('.verify-2fa');
            const twoFAInput = twoFAContainer.querySelector('.tfa-code-input');

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

            verifyButton.addEventListener('click', verify2FA);
            
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