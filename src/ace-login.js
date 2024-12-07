document.addEventListener('DOMContentLoaded', function () {
    if (!aceLoginBlock.is2FAEnabled) {
        return;
    }

    console.log('2FA Enabled:', aceLoginBlock.is2FAEnabled);

    // Toggle password visibility
    document.querySelectorAll('[data-show-password]').forEach(function (span) {
        span.addEventListener('click', function () {
            const input = span.previousElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                span.textContent = 'Hide Password';
            } else {
                input.type = 'password';
                span.textContent = 'Show Password';
            }
        });
    });

    // Handle login button click
    const loginButton = document.querySelector('.wp-block-acemedia-login-block form .wp-block-button__link');
    if (loginButton) {
        loginButton.addEventListener('click', function (event) {
            event.preventDefault();
            const form = loginButton.closest('form');

            if (form) {
                const usernameInput = form.querySelector('input[name="log"]');
                const username = usernameInput ? usernameInput.value : '';

                if (!username) {
                    alert('Please enter your username.');
                    return;
                }

                // Check if 2FA is enabled for the user
                fetch(aceLoginBlock.check2FAEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': aceLoginBlock.nonce,
                    },
                    body: JSON.stringify({ username }),
                })
                .then((response) => response.json())
                .then((data) => {
                    if (data.needs2FASetup) {
                        // Allow login to proceed so they can set up 2FA
                        form.submit();
                    } else if (data.is2FAEnabled) {
                        // Create 2FA block dynamically
                        let twoFAContainer = form.querySelector('.wp-block-acemedia-2fa-block');
                        if (!twoFAContainer) {
                            twoFAContainer = document.createElement('div');
                            twoFAContainer.className = 'wp-block-acemedia-2fa-block';
                            twoFAContainer.innerHTML = `
                                <label for="2fa_code">
                                    ${aceLoginBlock.twoFALabel || 'Enter Authentication Code'}
                                </label>
                                <input 
                                    type="text" 
                                    name="2fa_code" 
                                    id="2fa_code" 
                                    placeholder="${aceLoginBlock.twoFAPlaceholder || 'Authentication Code'}" 
                                    required 
                                />
                                <button type="button" class="button verify-2fa">
                                    ${aceLoginBlock.submit2FA || 'Verify'}
                                </button>
                            `;
                            form.appendChild(twoFAContainer);

                            // Hide other fields to focus on 2FA
                            form.querySelectorAll('input, button, .wp-block-button').forEach((element) => {
                                if (!element.closest('.wp-block-acemedia-2fa-block')) {
                                    element.style.display = 'none';
                                }
                            });

                            // Handle 2FA verification
                            const verifyButton = form.querySelector('.verify-2fa');
                            verifyButton.addEventListener('click', function () {
                                const twoFAInput = form.querySelector('input[name="2fa_code"]');
                                if (!twoFAInput) {
                                    console.error('2FA input field not found.');
                                    alert('An error occurred. Please refresh and try again.');
                                    return;
                                }

                                const twoFACode = form.querySelector('#2fa_code').value;
                                if (!twoFACode) {
                                    alert('Please enter your authentication code.');
                                    return;
                                }

                                // Perform AJAX request to verify 2FA
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
                                        // Unhide other fields and submit the form
                                        form.querySelectorAll('input, button, .wp-block-button').forEach((element) => {
                                            element.style.display = '';
                                        });
                                        form.submit();
                                    } else {
                                        alert(data.message || 'Invalid authentication code. Please try again.');
                                    }
                                })
                                .catch((error) => {
                                    console.error('2FA verification failed:', error);
                                    alert('An error occurred while verifying the authentication code.');
                                });
                            });
                        }
                    } else {
                        // Submit the form directly if 2FA is not enabled
                        form.submit();
                    }
                })
                .catch((error) => {
                    console.error('Error checking 2FA status:', error);
                    alert('An error occurred while checking the 2FA status.');
                });
            }
        });
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