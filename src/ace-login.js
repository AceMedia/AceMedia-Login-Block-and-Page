document.addEventListener('DOMContentLoaded', function () {
    if (typeof aceLoginBlock === 'undefined' || !aceLoginBlock.check2FAEndpoint || !aceLoginBlock.verify2FAEndpoint) {
        return;
    }

    // Handle login button click
    const loginButton = document.querySelector('.wp-block-acemedia-login-block form .wp-block-button__link');
    if (loginButton) {
        loginButton.addEventListener('click', handleLoginAttempt);
    }

    const STATUS_CLASS = 'acemedia-login-status-message';

    function getStatusElement(form) {
        if (!form) {
            return null;
        }

        let status = form.querySelector(`.${STATUS_CLASS}`);
        if (!status) {
            status = document.createElement('p');
            status.className = `${STATUS_CLASS} description`;
            status.style.marginTop = '10px';
            const submitRow = form.querySelector('.login-submit') || form.querySelector('.wp-block-button');
            if (submitRow && submitRow.parentNode) {
                submitRow.parentNode.insertBefore(status, submitRow);
            } else {
                form.appendChild(status);
            }
        }

        return status;
    }

    function showMessage(form, message, isError = true) {
        const status = getStatusElement(form);
        if (!status) {
            return;
        }

        status.textContent = message || '';
        status.style.color = isError ? '#b32d2e' : '#1d2327';
    }

    function extractApiMessage(payload, fallback) {
        if (payload && typeof payload === 'object') {
            if (typeof payload.message === 'string' && payload.message) {
                return payload.message;
            }

            if (payload.data && typeof payload.data.message === 'string' && payload.data.message) {
                return payload.data.message;
            }
        }

        return fallback;
    }

    function bufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        bytes.forEach((value) => {
            binary += String.fromCharCode(value);
        });

        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    function base64UrlToBuffer(base64Url) {
        const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
        const padded = base64 + '==='.slice((base64.length + 3) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);

        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }

        return bytes.buffer;
    }

    function normalizePublicKey(options) {
        const publicKey = options.publicKey || options;

        if (publicKey.challenge) {
            publicKey.challenge = base64UrlToBuffer(publicKey.challenge);
        }

        if (publicKey.user && publicKey.user.id) {
            publicKey.user.id = base64UrlToBuffer(publicKey.user.id);
        }

        if (publicKey.allowCredentials) {
            publicKey.allowCredentials = publicKey.allowCredentials.map((credential) => ({
                ...credential,
                id: base64UrlToBuffer(credential.id),
            }));
        }

        return publicKey;
    }

    async function startPasskeySecondFactor(username) {
        if (!window.PublicKeyCredential) {
            throw new Error('Passkey support is not available in this browser.');
        }

        if (!window.isSecureContext) {
            throw new Error('Passkeys require a secure connection (HTTPS or localhost).');
        }

        if (!aceLoginBlock.passkeyLoginOptionsEndpoint || !aceLoginBlock.passkeyLoginEndpoint) {
            throw new Error('Passkey endpoints are not configured.');
        }

        const optionsResponse = await fetch(aceLoginBlock.passkeyLoginOptionsEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ username, context: 'second_factor' }),
        });

        const optionsData = await optionsResponse.json();
        if (!optionsResponse.ok || !optionsData.options) {
            throw new Error(extractApiMessage(optionsData, 'Passkey verification is not available for this account.'));
        }

        let assertion = null;
        try {
            assertion = await navigator.credentials.get({ publicKey: normalizePublicKey(optionsData.options) });
        } catch (error) {
            if (error && error.name === 'NotAllowedError') {
                throw new Error('Passkey verification was cancelled or timed out. Please try again and complete the device prompt.');
            }

            if (error && error.name === 'AbortError') {
                throw new Error('Passkey verification was interrupted. Please try again.');
            }

            if (error && error.name === 'SecurityError') {
                throw new Error('Passkey verification failed security checks. Confirm HTTPS and browser/device support.');
            }

            throw new Error('Passkey verification failed due to a temporary browser/device issue. Please retry once.');
        }

        const payload = {
            state: optionsData.state,
            credential: {
                id: assertion.id,
                rawId: bufferToBase64Url(assertion.rawId),
                type: assertion.type,
                response: {
                    clientDataJSON: bufferToBase64Url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                    signature: bufferToBase64Url(assertion.response.signature),
                    userHandle: assertion.response.userHandle ? bufferToBase64Url(assertion.response.userHandle) : null,
                },
            },
        };

        const verifyResponse = await fetch(aceLoginBlock.passkeyLoginEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        const verifyData = await verifyResponse.json();
        if (!verifyResponse.ok || !verifyData.success || !verifyData.token) {
            throw new Error(extractApiMessage(verifyData, 'Passkey verification failed.'));
        }

        return verifyData.token;
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
                twoFactorVerified: createHiddenInput('two_factor_verified', 'false'),
                passkeyToken: createHiddenInput('acemedia_passkey_token', '')
            };
    
            Object.values(formInputs).forEach(input => {
                if (!form.querySelector(`input[name="${input.name}"]`)) {
                    form.appendChild(input);
                }
            });
    
            const usernameInput = form.querySelector('input[name="log"]');
            const username = usernameInput ? usernameInput.value : '';
            showMessage(form, '');
    
            if (!username) {
                showMessage(form, 'Please enter your username.');
                return;
            }
    
            // Start session timestamp
            const sessionStart = Date.now();
            const sessionInput = form.querySelector('input[name="session_start"]') || createHiddenInput('session_start', sessionStart);
            sessionInput.value = sessionStart;
            if (!sessionInput.parentNode) {
                form.appendChild(sessionInput);
            }
    
            // Check if 2FA is enabled for the user
            fetch(aceLoginBlock.check2FAEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
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
                    show2FAPrompt(form, username, formInputs, data);
                } else {
                    formInputs.twoFactorState.value = 'disabled';
                    formInputs.twoFactorVerified.value = 'true';
                    form.submit();
                }
            })
            .catch(error => {
                console.error('Error checking 2FA status:', error);
                showMessage(form, 'Could not verify your 2FA status. Please try again.');
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
            if (!twoFactorState || twoFactorState.value === 'verification') {
                showMessage(form, 'Please complete two-factor authentication.');
            }
            return;
        }
    
        // Check session expiration (30 minute limit)
        const sessionStart = form.querySelector('input[name="session_start"]');
        if (sessionStart && (Date.now() - parseInt(sessionStart.value, 10)) > 1800000) {
            e.preventDefault();
            showMessage(form, 'Session expired. Please refresh and try again.');
            return;
        }
    }

    function show2FAPrompt(form, username, formInputs, statusData) {
        let twoFAContainer = form.querySelector('.wp-block-acemedia-2fa-block');
        if (!twoFAContainer) {
            const requiresPasskeyByRole = !!(statusData && statusData.requiresPasskey2FA);
            const usesPasskeyMethod = !!(statusData && statusData.method === 'passkey');
            const requiresPasskeyOnly = !!(requiresPasskeyByRole || usesPasskeyMethod);
            // Find password field and related elements
            const pwdInput = form.querySelector('input[name="pwd"]');
            const pwdLabel = form.querySelector('label[for="pwd"]');
            const pwdShowToggle = form.querySelector('span[data-show-password="true"]');
    
            // Create 2FA label and input
            const twoFALabel = document.createElement('label');
            twoFALabel.setAttribute('for', '2fa_code');
            if (pwdLabel) {
                twoFALabel.textContent = aceLoginBlock.twoFALabel || '<strong>Enter 2FA Code:</strong>';
            }
    
            const twoFAInput = document.createElement('input');
            twoFAInput.type = 'text';
            twoFAInput.name = '2fa_code';
            twoFAInput.className = 'tfa-code-input';
            twoFAInput.placeholder = aceLoginBlock.twoFAPlaceholder || '2FA Code';
            twoFAInput.required = true;

            const rememberLabel = document.createElement('label');
            rememberLabel.style.display = 'block';
            rememberLabel.style.marginTop = '8px';
            const rememberCheckbox = document.createElement('input');
            rememberCheckbox.type = 'checkbox';
            rememberCheckbox.name = 'acemedia_trust_device';
            rememberCheckbox.value = '1';
            rememberLabel.appendChild(rememberCheckbox);
            rememberLabel.appendChild(document.createTextNode(' ' + (aceLoginBlock.rememberDeviceLabel || 'Remember this device for 2FA for 30 days')));

    
            // Hide password elements
            pwdInput.style.display = 'none';
            if (pwdLabel) pwdLabel.style.display = 'none';
            if (pwdShowToggle) pwdShowToggle.style.display = 'none';
    
            // Insert 2FA elements before password elements
            pwdInput.insertAdjacentElement('beforebegin', twoFAInput);
            pwdInput.parentElement.insertBefore(rememberLabel, twoFAInput.nextSibling);
            if (pwdLabel) {
                pwdLabel.insertAdjacentElement('beforebegin', twoFALabel);
            } else {
                pwdInput.parentElement.insertBefore(twoFALabel, pwdInput);
            }

            if (requiresPasskeyOnly) {
                twoFALabel.textContent = aceLoginBlock.passkeyTwoFALabel || 'Use Passkey for 2FA';
                twoFAInput.style.display = 'none';
                twoFAInput.required = false;
                if (requiresPasskeyByRole) {
                    rememberLabel.style.display = 'none';
                    showMessage(form, 'This account requires passkey verification for 2FA. Use your passkey to continue.', false);
                } else {
                    rememberLabel.style.display = 'block';
                    showMessage(form, 'This account is configured for passkey 2FA. Use your passkey to continue.', false);
                }
            }

            let passkeyButton = null;
            if (aceLoginBlock.passkeysEnabled && window.PublicKeyCredential) {
                passkeyButton = document.createElement('button');
                passkeyButton.type = 'button';
                passkeyButton.className = 'button';
                passkeyButton.textContent = aceLoginBlock.passkeyTwoFALabel || 'Use Passkey for 2FA';
                passkeyButton.style.marginTop = '8px';
                pwdInput.parentElement.insertBefore(passkeyButton, rememberLabel.nextSibling);
            }
    
            // Handle verification button click
            const verify2FA = () => {
                if (requiresPasskeyOnly) {
                    showMessage(form, 'This account requires passkey verification. Use the passkey button below.');
                    return;
                }

                const twoFACode = twoFAInput.value;
                if (!twoFACode) {
                    showMessage(form, 'Please enter your authentication code.');
                    return;
                }

                showMessage(form, '');
    
                fetch(aceLoginBlock.verify2FAEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ code: twoFACode, username }),
                })
                .then(async (response) => {
                    const data = await response.json();
                    if (!response.ok) {
                        throw new Error(extractApiMessage(data, 'Authentication failed. Please try again.'));
                    }
                    return data;
                })
                .then((data) => {
                    if (data.success) {
                        formInputs.twoFactorVerified.value = 'true';
                        form.dataset.twoFactorVerified = 'true';
                        form.submit();
                    } else {
                        showMessage(form, extractApiMessage(data, 'Invalid authentication code. Please try again.'));
                    }
                })
                .catch((error) => {
                    console.error('2FA verification failed:', error);
                    showMessage(form, error.message || 'An error occurred while verifying the authentication code.');
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

            if (passkeyButton) {
                passkeyButton.addEventListener('click', async (e) => {
                    e.preventDefault();
                    passkeyButton.disabled = true;
                    try {
                        const token = window.acemediaPasskeys && window.acemediaPasskeys.startSecondFactor
                            ? await window.acemediaPasskeys.startSecondFactor(username)
                            : await startPasskeySecondFactor(username);
                        if (!token) {
                            showMessage(form, 'Passkey verification failed.');
                            return;
                        }

                        formInputs.passkeyToken.value = token;
                        formInputs.twoFactorVerified.value = 'true';
                        form.submit();
                    } catch (error) {
                        const message = (error && (error.userMessage || error.message)) || 'An error occurred while verifying the passkey.';
                        showMessage(form, message);
                    } finally {
                        passkeyButton.disabled = false;
                    }
                });
            }
            
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
