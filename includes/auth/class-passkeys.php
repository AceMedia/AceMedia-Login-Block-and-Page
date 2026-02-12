<?php
namespace AceLoginBlock\Auth;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use AceLoginBlock\Utils\Logging;

if (!defined('ABSPATH')) {
    exit;
}

class Passkeys {
    private const META_KEY = '_acemedia_passkeys';
    private const TRANSIENT_REGISTER = 'acemedia_passkey_register_';
    private const TRANSIENT_LOGIN = 'acemedia_passkey_login_';
    private const TRANSIENT_2FA = 'acemedia_passkey_2fa_';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('login_form', [$this, 'render_login_ui'], 20);
        add_action('show_user_profile', [$this, 'render_profile_ui'], 20);
        add_action('edit_user_profile', [$this, 'render_profile_ui'], 20);
    }

    public function register_routes() {
        register_rest_route('acemedia/v1', '/passkeys/register-options', [
            'methods' => 'POST',
            'callback' => [$this, 'begin_registration'],
            'permission_callback' => [$this, 'require_logged_in'],
        ]);

        register_rest_route('acemedia/v1', '/passkeys/register', [
            'methods' => 'POST',
            'callback' => [$this, 'finish_registration'],
            'permission_callback' => [$this, 'require_logged_in'],
        ]);

        register_rest_route('acemedia/v1', '/passkeys/login-options', [
            'methods' => 'POST',
            'callback' => [$this, 'begin_login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('acemedia/v1', '/passkeys/login', [
            'methods' => 'POST',
            'callback' => [$this, 'finish_login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('acemedia/v1', '/passkeys/remove', [
            'methods' => 'POST',
            'callback' => [$this, 'remove_passkey'],
            'permission_callback' => [$this, 'require_logged_in'],
        ]);
    }

    public function render_login_ui() {
        if (!$this->is_enabled()) {
            return;
        }
        ?>
        <p class="acemedia-passkey-login" style="margin-top: 12px;">
            <button type="button" class="button button-secondary" id="acemedia-passkey-login">
                <?php esc_html_e('Use Passkey for Password', 'acemedia-login-block'); ?>
            </button>
            <span class="description" id="acemedia-passkey-message" style="display: block; margin-top: 6px;"></span>
        </p>
        <script type="text/javascript">
            (function() {
                const passkeyPasswordLabel = (window.aceLoginBlock && aceLoginBlock.passkeyPasswordLabel)
                    ? aceLoginBlock.passkeyPasswordLabel
                    : '<?php echo esc_js(__('Use Passkey for Password', 'acemedia-login-block')); ?>';
                const passkeyTwoFALabel = (window.aceLoginBlock && aceLoginBlock.passkeyTwoFALabel)
                    ? aceLoginBlock.passkeyTwoFALabel
                    : '<?php echo esc_js(__('Use Passkey for 2FA', 'acemedia-login-block')); ?>';
                const passkeysEnabled = <?php echo $this->is_enabled() ? 'true' : 'false'; ?>;
                const passkeyLoginOptionsEndpoint = '<?php echo esc_url(rest_url('acemedia/v1/passkeys/login-options')); ?>';
                const passkeyLoginEndpoint = '<?php echo esc_url(rest_url('acemedia/v1/passkeys/login')); ?>';

                if (!window.PublicKeyCredential || !passkeysEnabled) {
                    return;
                }

                const loginButton = document.getElementById('acemedia-passkey-login');
                const messageEl = document.getElementById('acemedia-passkey-message');
                const form = document.getElementById('loginform');
                const usernameInput = form ? form.querySelector('input[name="log"]') : null;
                const check2FAEndpoint = window.aceLoginBlock ? aceLoginBlock.check2FAEndpoint : null;
                const restNonce = window.aceLoginBlock ? aceLoginBlock.nonce : '';

                if (loginButton) {
                    loginButton.textContent = passkeyPasswordLabel;
                    loginButton.dataset.passkeyPasswordLabel = passkeyPasswordLabel;
                    loginButton.dataset.passkeyTwoFALabel = passkeyTwoFALabel;
                }

                const showMessage = (message, isError = true) => {
                    if (!messageEl) return;
                    messageEl.textContent = message;
                    messageEl.style.color = isError ? '#b32d2e' : '#1d2327';
                };

                const makeError = (message, code) => {
                    const error = new Error(code || message);
                    error.userMessage = message;
                    return error;
                };

                const bufferToBase64Url = (buffer) => {
                    const bytes = new Uint8Array(buffer);
                    let binary = '';
                    bytes.forEach((b) => { binary += String.fromCharCode(b); });
                    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
                };

                const base64UrlToBuffer = (base64Url) => {
                    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
                    const padded = base64 + '==='.slice((base64.length + 3) % 4);
                    const binary = atob(padded);
                    const bytes = new Uint8Array(binary.length);
                    for (let i = 0; i < binary.length; i++) {
                        bytes[i] = binary.charCodeAt(i);
                    }
                    return bytes.buffer;
                };

                const normalizePublicKey = (options) => {
                    const publicKey = options.publicKey || options;
                    if (publicKey.challenge) {
                        publicKey.challenge = base64UrlToBuffer(publicKey.challenge);
                    }
                    if (publicKey.user && publicKey.user.id) {
                        publicKey.user.id = base64UrlToBuffer(publicKey.user.id);
                    }
                    if (publicKey.excludeCredentials) {
                        publicKey.excludeCredentials = publicKey.excludeCredentials.map((cred) => ({
                            ...cred,
                            id: base64UrlToBuffer(cred.id),
                        }));
                    }
                    if (publicKey.allowCredentials) {
                        publicKey.allowCredentials = publicKey.allowCredentials.map((cred) => ({
                            ...cred,
                            id: base64UrlToBuffer(cred.id),
                        }));
                    }
                    return publicKey;
                };

                window.acemediaPasskeys = {
                    startSecondFactor: async (username) => {
                        return startLogin(username, 'second_factor');
                    },
                };

                const supportInfoLink = 'https://en.wikipedia.org/wiki/WebAuthn';
                const showSupportInfo = () => {
                    const msg = '<?php echo esc_js(__('Touch ID or built-in passkeys are not supported in this browser. You can still use a hardware security key.', 'acemedia-login-block')); ?>';
                    showMessage(msg + ' ' + supportInfoLink, false);
                };

                const showInsecureContext = () => {
                    const msg = '<?php echo esc_js(__('Passkeys require a secure connection (HTTPS or localhost).', 'acemedia-login-block')); ?>';
                    showMessage(msg + ' ' + supportInfoLink, true);
                };

                if (!window.PublicKeyCredential) {
                    showSupportInfo();
                    return;
                }

                if (window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
                    window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
                        .then((available) => {
                            if (!available) {
                                showSupportInfo();
                            }
                        })
                        .catch(() => {
                            showSupportInfo();
                        });
                } else {
                    showSupportInfo();
                }

                const setPasskeyButtonVisible = (visible) => {
                    if (!loginButton) {
                        return;
                    }
                    loginButton.style.display = visible ? '' : 'none';
                };

                const shouldHidePasskeyForUser = (data) => {
                    if (!data) {
                        return false;
                    }
                    if (data.needs2FASetup) {
                        return true;
                    }
                    if (data.requires2FA && !data.passkeyPasswordlessAllowed) {
                        return true;
                    }
                    return false;
                };

                const checkUserPasskeyAvailability = async (username) => {
                    if (!check2FAEndpoint || !username) {
                        return;
                    }

                    const response = await fetch(check2FAEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ username }),
                    });

                    if (!response.ok) {
                        return;
                    }

                    const data = await response.json();
                    setPasskeyButtonVisible(!shouldHidePasskeyForUser(data));
                };

                let debounceId = null;
                const debounceCheck = () => {
                    if (!usernameInput) {
                        return;
                    }

                    const username = usernameInput.value || '';
                    if (!username) {
                        setPasskeyButtonVisible(true);
                        return;
                    }

                    if (debounceId) {
                        clearTimeout(debounceId);
                    }

                    debounceId = setTimeout(() => {
                        checkUserPasskeyAvailability(username).catch(() => {
                            // Ignore fetch errors to avoid blocking UI.
                        });
                    }, 350);
                };

                if (usernameInput) {
                    usernameInput.addEventListener('input', debounceCheck);
                    usernameInput.addEventListener('blur', debounceCheck);
                    if (usernameInput.value) {
                        debounceCheck();
                    }
                }

                async function startLogin(username, context, options = {}) {
                    if (!window.isSecureContext) {
                        showInsecureContext();
                        throw makeError('<?php echo esc_js(__('Insecure context.', 'acemedia-login-block')); ?>', 'insecure_context');
                    }

                    const discoverable = !!options.discoverable;
                    const mediation = options.mediation || undefined;
                    const silent = !!options.silent;

                    if (!username && !discoverable) {
                        const msg = '<?php echo esc_js(__('Please enter your username first.', 'acemedia-login-block')); ?>';
                        if (!silent) {
                            showMessage(msg);
                        }
                        throw makeError(msg, 'missing_username');
                    }

                    const optionsResponse = await fetch(passkeyLoginOptionsEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ username, context, discoverable }),
                    });

                    const optionsData = await optionsResponse.json();
                    if (!optionsResponse.ok || !optionsData.options) {
                        const msg = optionsData && optionsData.message ? optionsData.message : '<?php echo esc_js(__('Passkey login is not available for this account.', 'acemedia-login-block')); ?>';
                        if (!silent) {
                            showMessage(msg);
                        }
                        throw makeError(msg, 'options_error');
                    }

                    const publicKey = normalizePublicKey(optionsData.options);
                    const assertion = await navigator.credentials.get({ publicKey, mediation });

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

                    const verifyResponse = await fetch(passkeyLoginEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(payload),
                    });

                    const verifyData = await verifyResponse.json();
                    if (!verifyResponse.ok || !verifyData.success) {
                        const msg = verifyData && verifyData.message ? verifyData.message : '<?php echo esc_js(__('Passkey verification failed.', 'acemedia-login-block')); ?>';
                        if (!silent) {
                            showMessage(msg);
                        }
                        throw makeError(msg, 'verify_failed');
                    }

                    if (context === 'second_factor') {
                        return verifyData.token;
                    }

                    if (verifyData.redirect) {
                        window.location.href = verifyData.redirect;
                    }

                    return verifyData;
                }

                if (loginButton) {
                    loginButton.addEventListener('click', async function(event) {
                        event.preventDefault();
                        const username = usernameInput ? usernameInput.value : '';
                        showMessage('');
                        loginButton.disabled = true;
                        try {
                            if (!username) {
                                await startLogin('', 'passwordless', { discoverable: true });
                            } else {
                                await startLogin(username, 'passwordless');
                            }
                        } catch (e) {
                            // message handled above
                        } finally {
                            loginButton.disabled = false;
                        }
                    });
                }

                if (window.PublicKeyCredential.isConditionalMediationAvailable) {
                    window.PublicKeyCredential.isConditionalMediationAvailable()
                        .then((available) => {
                            if (!available) {
                                return;
                            }

                            startLogin('', 'passwordless', {
                                discoverable: true,
                                mediation: 'conditional',
                                silent: true,
                            }).catch(() => {
                                // Silent for conditional UI.
                            });
                        })
                        .catch(() => {
                            // Ignore conditional capability failures.
                        });
                }
            })();
        </script>
        <?php
    }

    public function render_profile_ui($user) {
        if (!$this->is_enabled()) {
            return;
        }

        $passkeys = $this->get_passkeys($user->ID);
        ?>
        <h3><?php esc_html_e('Passkeys / Security Keys', 'acemedia-login-block'); ?></h3>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Registered Passkeys', 'acemedia-login-block'); ?></th>
                <td>
                    <?php if (empty($passkeys)) : ?>
                        <p class="description"><?php esc_html_e('No passkeys registered yet.', 'acemedia-login-block'); ?></p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($passkeys as $passkey) : ?>
                                <li style="margin-bottom: 8px;">
                                    <strong><?php echo esc_html($passkey['name'] ?? __('Passkey', 'acemedia-login-block')); ?></strong>
                                    <br />
                                    <span class="description">
                                        <?php
                                        $created = isset($passkey['created']) ? (int) $passkey['created'] : 0;
                                        $last_used = isset($passkey['last_used']) ? (int) $passkey['last_used'] : 0;
                                        ?>
                                        <?php if ($created) : ?>
                                            <?php printf(esc_html__('Created: %s', 'acemedia-login-block'), esc_html(wp_date(get_option('date_format'), $created))); ?>
                                        <?php endif; ?>
                                        <?php if ($last_used) : ?>
                                            <?php echo ' | '; ?>
                                            <?php printf(esc_html__('Last used: %s', 'acemedia-login-block'), esc_html(wp_date(get_option('date_format'), $last_used))); ?>
                                        <?php endif; ?>
                                    </span>
                                    <br />
                                    <button type="button" class="button acemedia-passkey-remove" data-passkey-id="<?php echo esc_attr($passkey['id']); ?>">
                                        <?php esc_html_e('Remove', 'acemedia-login-block'); ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p>
                        <input type="text" id="acemedia-passkey-name" placeholder="<?php esc_attr_e('Passkey name (optional)', 'acemedia-login-block'); ?>" />
                        <button type="button" class="button button-primary" id="acemedia-passkey-register">
                            <?php esc_html_e('Register Passkey', 'acemedia-login-block'); ?>
                        </button>
                    </p>
                    <p class="description" id="acemedia-passkey-status"></p>
                </td>
            </tr>
        </table>
        <script type="text/javascript">
            (function() {
                if (!window.PublicKeyCredential) {
                    return;
                }

                const registerButton = document.getElementById('acemedia-passkey-register');
                const nameInput = document.getElementById('acemedia-passkey-name');
                const statusEl = document.getElementById('acemedia-passkey-status');
                const removeButtons = document.querySelectorAll('.acemedia-passkey-remove');

                const showStatus = (message, isError = false) => {
                    if (!statusEl) return;
                    statusEl.textContent = message;
                    statusEl.style.color = isError ? '#b32d2e' : '#1d2327';
                };

                const bufferToBase64Url = (buffer) => {
                    const bytes = new Uint8Array(buffer);
                    let binary = '';
                    bytes.forEach((b) => { binary += String.fromCharCode(b); });
                    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
                };

                const base64UrlToBuffer = (base64Url) => {
                    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
                    const padded = base64 + '==='.slice((base64.length + 3) % 4);
                    const binary = atob(padded);
                    const bytes = new Uint8Array(binary.length);
                    for (let i = 0; i < binary.length; i++) {
                        bytes[i] = binary.charCodeAt(i);
                    }
                    return bytes.buffer;
                };

                const normalizePublicKey = (options) => {
                    const publicKey = options.publicKey || options;
                    if (publicKey.challenge) {
                        publicKey.challenge = base64UrlToBuffer(publicKey.challenge);
                    }
                    if (publicKey.user && publicKey.user.id) {
                        publicKey.user.id = base64UrlToBuffer(publicKey.user.id);
                    }
                    if (publicKey.excludeCredentials) {
                        publicKey.excludeCredentials = publicKey.excludeCredentials.map((cred) => ({
                            ...cred,
                            id: base64UrlToBuffer(cred.id),
                        }));
                    }
                    return publicKey;
                };

                async function beginRegistration() {
                    const response = await fetch('<?php echo esc_url(rest_url('acemedia/v1/passkeys/register-options')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>',
                        },
                        body: JSON.stringify({ name: nameInput ? nameInput.value : '' }),
                    });

                    const data = await response.json();
                    if (!response.ok || !data.options) {
                        const msg = data && data.message ? data.message : '<?php echo esc_js(__('Could not start passkey registration.', 'acemedia-login-block')); ?>';
                        showStatus(msg, true);
                        return;
                    }

                    const publicKey = normalizePublicKey(data.options);
                    let credential = null;
                    try {
                        credential = await navigator.credentials.create({ publicKey });
                    } catch (error) {
                        if (error && error.name === 'InvalidStateError') {
                            showStatus('<?php echo esc_js(__('A passkey already exists for this device. Try removing it or use a different authenticator.', 'acemedia-login-block')); ?>', true);
                            return;
                        }
                        showStatus('<?php echo esc_js(__('Passkey registration failed.', 'acemedia-login-block')); ?>', true);
                        return;
                    }

                    const payload = {
                        state: data.state,
                        name: nameInput ? nameInput.value : '',
                        credential: {
                            id: credential.id,
                            rawId: bufferToBase64Url(credential.rawId),
                            type: credential.type,
                            response: {
                                clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                                attestationObject: bufferToBase64Url(credential.response.attestationObject),
                            },
                            transports: credential.response.getTransports ? credential.response.getTransports() : [],
                        },
                    };

                    const finishResponse = await fetch('<?php echo esc_url(rest_url('acemedia/v1/passkeys/register')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>',
                        },
                        body: JSON.stringify(payload),
                    });

                    const finishData = await finishResponse.json();
                    if (!finishResponse.ok || !finishData.success) {
                        const msg = finishData && finishData.message ? finishData.message : '<?php echo esc_js(__('Passkey registration failed.', 'acemedia-login-block')); ?>';
                        showStatus(msg, true);
                        return;
                    }

                    showStatus('<?php echo esc_js(__('Passkey registered. Reloading…', 'acemedia-login-block')); ?>', false);
                    window.location.reload();
                }

                if (registerButton) {
                    registerButton.addEventListener('click', async function(event) {
                        event.preventDefault();
                        showStatus('');
                        registerButton.disabled = true;
                        try {
                            await beginRegistration();
                        } catch (e) {
                            showStatus('<?php echo esc_js(__('Passkey registration failed.', 'acemedia-login-block')); ?>', true);
                        } finally {
                            registerButton.disabled = false;
                        }
                    });
                }

                if (removeButtons.length) {
                    removeButtons.forEach((button) => {
                        button.addEventListener('click', async function(event) {
                            event.preventDefault();
                            if (!confirm('<?php echo esc_js(__('Remove this passkey?', 'acemedia-login-block')); ?>')) {
                                return;
                            }
                            const id = button.getAttribute('data-passkey-id');
                            const response = await fetch('<?php echo esc_url(rest_url('acemedia/v1/passkeys/remove')); ?>', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>',
                                },
                                body: JSON.stringify({ id }),
                            });
                            const data = await response.json();
                            if (!response.ok || !data.success) {
                                const msg = data && data.message ? data.message : '<?php echo esc_js(__('Failed to remove passkey.', 'acemedia-login-block')); ?>';
                                showStatus(msg, true);
                                return;
                            }
                            window.location.reload();
                        });
                    });
                }
            })();
        </script>
        <?php
    }

    public function begin_registration($request) {
        if (!$this->is_enabled()) {
            return new \WP_Error('passkeys_disabled', __('Passkeys are disabled.', 'acemedia-login-block'), ['status' => 403]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new \WP_Error('not_logged_in', __('Not logged in.', 'acemedia-login-block'), ['status' => 401]);
        }

        $passkeys = $this->get_passkeys($user->ID);
        $exclude = [];
        foreach ($passkeys as $passkey) {
            if (!empty($passkey['id'])) {
                $exclude[] = ByteBuffer::fromBase64Url($passkey['id']);
            }
        }

        $webauthn = $this->get_webauthn();
        $attestation = $this->get_attestation_preference();
        $createArgs = $webauthn->getCreateArgs(
            (string) $user->ID,
            $user->user_login,
            $user->display_name ?: $user->user_login,
            60,
            $attestation,
            'preferred',
            null,
            $exclude
        );

        $state = $this->generate_state();
        $this->store_state(self::TRANSIENT_REGISTER, $state, [
            'user_id' => $user->ID,
            'challenge' => $this->buffer_to_base64url($webauthn->getChallenge()),
        ], 10 * MINUTE_IN_SECONDS);

        return rest_ensure_response([
            'options' => $createArgs,
            'state' => $state,
        ]);
    }

    public function finish_registration($request) {
        if (!$this->is_enabled()) {
            return new \WP_Error('passkeys_disabled', __('Passkeys are disabled.', 'acemedia-login-block'), ['status' => 403]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new \WP_Error('not_logged_in', __('Not logged in.', 'acemedia-login-block'), ['status' => 401]);
        }

        $state = sanitize_text_field($request->get_param('state'));
        $state_data = $this->get_state(self::TRANSIENT_REGISTER, $state);
        if (!$state_data || (int) $state_data['user_id'] !== (int) $user->ID) {
            return new \WP_Error('invalid_state', __('Registration session expired.', 'acemedia-login-block'), ['status' => 400]);
        }

        $credential = $request->get_param('credential');
        if (!is_array($credential) || empty($credential['response'])) {
            return new \WP_Error('invalid_credential', __('Invalid credential payload.', 'acemedia-login-block'), ['status' => 400]);
        }

        $clientDataJSON = $this->base64url_decode($credential['response']['clientDataJSON'] ?? '');
        $attestationObject = $this->base64url_decode($credential['response']['attestationObject'] ?? '');
        $challenge = $this->base64url_decode($state_data['challenge']);

        if (!$clientDataJSON || !$attestationObject) {
            return new \WP_Error('invalid_payload', __('Invalid registration payload.', 'acemedia-login-block'), ['status' => 400]);
        }

        try {
            $webauthn = $this->get_webauthn();
            $createData = $webauthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true);
        } catch (\Exception $e) {
            return new \WP_Error('registration_failed', $e->getMessage(), ['status' => 400]);
        }

        $credential_id = $this->extract_credential_id($createData);
        $public_key = $this->extract_public_key($createData);
        $counter = $this->extract_counter($createData, $webauthn);

        if (!$credential_id || !$public_key) {
            return new \WP_Error('registration_failed', __('Could not read passkey data.', 'acemedia-login-block'), ['status' => 400]);
        }

        $name = sanitize_text_field($request->get_param('name')) ?: __('Passkey', 'acemedia-login-block');
        $transports = $credential['transports'] ?? [];

        $this->save_passkey($user->ID, [
            'id' => $credential_id,
            'publicKey' => $public_key,
            'counter' => $counter,
            'name' => $name,
            'created' => time(),
            'last_used' => 0,
            'transports' => is_array($transports) ? $transports : [],
        ]);

        Logging::log_event($user->ID, 'passkey_registered', [
            'name' => $name,
            'transports' => is_array($transports) ? $transports : [],
        ], true);

        $this->delete_state(self::TRANSIENT_REGISTER, $state);

        return rest_ensure_response(['success' => true]);
    }

    public function begin_login($request) {
        if (!$this->is_enabled()) {
            return new \WP_Error('passkeys_disabled', __('Passkeys are disabled.', 'acemedia-login-block'), ['status' => 403]);
        }

        $username = sanitize_text_field($request->get_param('username'));
        $context = sanitize_text_field($request->get_param('context')) ?: 'passwordless';
        $discoverable = (bool) $request->get_param('discoverable');

        $user = null;
        $allow_list = [];

        if (!$username) {
            if (!$discoverable) {
                return new \WP_Error('missing_username', __('Username is required.', 'acemedia-login-block'), ['status' => 400]);
            }
        } else {
            $user = $this->get_user_from_login($username);
            if (!$user) {
                return new \WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
            }

            if ($this->is_locked_out($user->ID)) {
                return new \WP_Error('locked_out', __('Account is temporarily locked.', 'acemedia-login-block'), ['status' => 403]);
            }

            $requires_2fa = $this->user_requires_2fa($user);
            if ($context === 'passwordless' && $requires_2fa && !$this->user_allows_passwordless($user)) {
                return new \WP_Error('passkey_requires_password', __('This account requires password login before passkey verification.', 'acemedia-login-block'), ['status' => 403]);
            }

            $passkeys = $this->get_passkeys($user->ID);
            if (empty($passkeys)) {
                return new \WP_Error('no_passkeys', __('No passkeys registered for this account.', 'acemedia-login-block'), ['status' => 404]);
            }

            foreach ($passkeys as $passkey) {
                if (!empty($passkey['id'])) {
                    $allow_list[] = ByteBuffer::fromBase64Url($passkey['id']);
                }
            }
        }

        $webauthn = $this->get_webauthn();
        $allow_credentials = empty($allow_list) ? null : $allow_list;
        $getArgs = $webauthn->getGetArgs($allow_credentials, 60, true, true, true, true, true, 'preferred');

        $state = $this->generate_state();
        $this->store_state(self::TRANSIENT_LOGIN, $state, [
            'user_id' => $user ? $user->ID : 0,
            'challenge' => $this->buffer_to_base64url($webauthn->getChallenge()),
            'context' => $context,
            'discoverable' => $discoverable,
        ], 10 * MINUTE_IN_SECONDS);

        return rest_ensure_response([
            'options' => $getArgs,
            'state' => $state,
        ]);
    }

    public function finish_login($request) {
        if (!$this->is_enabled()) {
            return new \WP_Error('passkeys_disabled', __('Passkeys are disabled.', 'acemedia-login-block'), ['status' => 403]);
        }

        $state = sanitize_text_field($request->get_param('state'));
        $state_data = $this->get_state(self::TRANSIENT_LOGIN, $state);
        if (!$state_data) {
            return new \WP_Error('invalid_state', __('Login session expired.', 'acemedia-login-block'), ['status' => 400]);
        }

        $credential = $request->get_param('credential');
        if (!is_array($credential) || empty($credential['response'])) {
            return new \WP_Error('invalid_credential', __('Invalid credential payload.', 'acemedia-login-block'), ['status' => 400]);
        }

        $user_id = (int) ($state_data['user_id'] ?? 0);
        $user = $user_id ? get_user_by('id', $user_id) : null;

        $credential_id = sanitize_text_field($credential['id'] ?? '');
        if (!$credential_id) {
            return new \WP_Error('invalid_credential', __('Invalid credential payload.', 'acemedia-login-block'), ['status' => 400]);
        }

        $passkey = null;
        if (!$user) {
            $match = $this->find_user_by_credential_id($credential_id);
            if ($match) {
                $user = $match['user'];
                $user_id = (int) $user->ID;
                $passkey = $match['passkey'];
            }
        } else {
            $passkey = $this->find_passkey($user_id, $credential_id);
        }

        if (!$user || !$passkey) {
            do_action('wp_login_failed', $user ? $user->user_login : '');
            return new \WP_Error('unknown_credential', __('Passkey not recognized.', 'acemedia-login-block'), ['status' => 403]);
        }

        if ($this->is_locked_out($user_id)) {
            return new \WP_Error('locked_out', __('Account is temporarily locked.', 'acemedia-login-block'), ['status' => 403]);
        }

        $clientDataJSON = $this->base64url_decode($credential['response']['clientDataJSON'] ?? '');
        $authenticatorData = $this->base64url_decode($credential['response']['authenticatorData'] ?? '');
        $signature = $this->base64url_decode($credential['response']['signature'] ?? '');
        $challenge = $this->base64url_decode($state_data['challenge']);

        if (!$clientDataJSON || !$authenticatorData || !$signature) {
            do_action('wp_login_failed', $user->user_login);
            return new \WP_Error('invalid_payload', __('Invalid login payload.', 'acemedia-login-block'), ['status' => 400]);
        }

        try {
            $webauthn = $this->get_webauthn();
            $webauthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $passkey['publicKey'],
                $challenge,
                isset($passkey['counter']) ? (int) $passkey['counter'] : 0,
                false,
                true
            );
        } catch (\Exception $e) {
            do_action('wp_login_failed', $user->user_login);
            return new \WP_Error('login_failed', $e->getMessage(), ['status' => 403]);
        }

        $passkey['counter'] = $this->extract_counter(null, $webauthn);
        $passkey['last_used'] = time();
        $this->update_passkey($user_id, $passkey);

        $this->delete_state(self::TRANSIENT_LOGIN, $state);

        if (($state_data['context'] ?? '') === 'second_factor') {
            $token = $this->generate_state(24);
            $this->store_state(self::TRANSIENT_2FA, $token, [
                'user_id' => $user_id,
            ], 5 * MINUTE_IN_SECONDS);

            return rest_ensure_response([
                'success' => true,
                'token' => $token,
            ]);
        }

        if ($this->user_requires_2fa($user) && !$this->user_allows_passwordless($user)) {
            return new \WP_Error('passkey_requires_password', __('This account requires password login before passkey verification.', 'acemedia-login-block'), ['status' => 403]);
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user->user_login, $user);

        return rest_ensure_response([
            'success' => true,
            'redirect' => $this->get_redirect_url($user),
        ]);
    }

    public function remove_passkey($request) {
        if (!$this->is_enabled()) {
            return new \WP_Error('passkeys_disabled', __('Passkeys are disabled.', 'acemedia-login-block'), ['status' => 403]);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new \WP_Error('not_logged_in', __('Not logged in.', 'acemedia-login-block'), ['status' => 401]);
        }

        $id = sanitize_text_field($request->get_param('id'));
        if (!$id) {
            return new \WP_Error('missing_id', __('Missing passkey id.', 'acemedia-login-block'), ['status' => 400]);
        }

        $passkeys = $this->get_passkeys($user->ID);
        $updated = [];
        $removed = false;

        foreach ($passkeys as $passkey) {
            if (!isset($passkey['id']) || $passkey['id'] !== $id) {
                $updated[] = $passkey;
            } else {
                $removed = true;
            }
        }

        if (!$removed) {
            return new \WP_Error('not_found', __('Passkey not found.', 'acemedia-login-block'), ['status' => 404]);
        }

        update_user_meta($user->ID, self::META_KEY, $updated);
        Logging::log_event($user->ID, 'passkey_removed', [
            'id' => $id,
        ], true);
        return rest_ensure_response(['success' => true]);
    }

    public static function verify_2fa_token($user_id, $token) {
        $token = sanitize_text_field($token);
        if (!$token) {
            return false;
        }

        $data = get_transient(self::TRANSIENT_2FA . $token);
        if (!$data || (int) ($data['user_id'] ?? 0) !== (int) $user_id) {
            return false;
        }

        delete_transient(self::TRANSIENT_2FA . $token);
        return true;
    }

    private function is_enabled() {
        if (!class_exists(WebAuthn::class)) {
            return false;
        }
        return (bool) get_option('acemedia_passkeys_enabled', true);
    }

    public function require_logged_in() {
        return is_user_logged_in();
    }

    private function get_webauthn() {
        return new WebAuthn(
            get_bloginfo('name'),
            $this->get_rp_id(),
            null,
            true
        );
    }

    private function get_rp_id() {
        $override = get_option('acemedia_passkeys_rp_id', '');
        if (!empty($override)) {
            return apply_filters('acemedia_passkeys_rp_id', $override);
        }

        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $host = ltrim(COOKIE_DOMAIN, '.');
        } elseif (!$host && isset($_SERVER['HTTP_HOST'])) {
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
        }

        $host = $host ?: 'localhost';
        return apply_filters('acemedia_passkeys_rp_id', $host);
    }

    private function get_attestation_preference() {
        $value = sanitize_text_field(get_option('acemedia_passkeys_attestation', 'preferred'));
        $allowed = ['preferred', 'none', 'indirect', 'direct', 'enterprise'];
        if (!in_array($value, $allowed, true)) {
            return 'preferred';
        }
        return $value;
    }

    private function get_passkeys($user_id) {
        $passkeys = get_user_meta($user_id, self::META_KEY, true);
        return is_array($passkeys) ? $passkeys : [];
    }

    private function save_passkey($user_id, array $data) {
        $passkeys = $this->get_passkeys($user_id);
        $existing = false;

        foreach ($passkeys as $index => $passkey) {
            if (!empty($passkey['id']) && $passkey['id'] === $data['id']) {
                $passkeys[$index] = array_merge($passkey, $data);
                $existing = true;
                break;
            }
        }

        if (!$existing) {
            $passkeys[] = $data;
        }

        update_user_meta($user_id, self::META_KEY, $passkeys);
    }

    private function update_passkey($user_id, array $data) {
        $this->save_passkey($user_id, $data);
    }

    private function find_passkey($user_id, $credential_id) {
        $passkeys = $this->get_passkeys($user_id);
        foreach ($passkeys as $passkey) {
            if (!empty($passkey['id']) && hash_equals($passkey['id'], $credential_id)) {
                return $passkey;
            }
        }
        return null;
    }

    private function find_user_by_credential_id($credential_id) {
        $users = get_users([
            'meta_key' => self::META_KEY,
            'fields' => ['ID'],
        ]);

        foreach ($users as $user_obj) {
            $passkeys = $this->get_passkeys($user_obj->ID);
            foreach ($passkeys as $passkey) {
                if (!empty($passkey['id']) && hash_equals($passkey['id'], $credential_id)) {
                    $user = get_user_by('id', $user_obj->ID);
                    if ($user) {
                        return [
                            'user' => $user,
                            'passkey' => $passkey,
                        ];
                    }
                }
            }
        }

        return null;
    }

    private function user_requires_2fa($user) {
        foreach ($user->roles as $role) {
            if (get_option("acemedia_2fa_enabled_{$role}", false)) {
                return true;
            }
        }
        return false;
    }

    private function user_allows_passwordless($user) {
        foreach ($user->roles as $role) {
            if (get_option("acemedia_passkey_passwordless_{$role}", false)) {
                return true;
            }
        }
        return false;
    }

    private function get_redirect_url($user) {
        $redirect_url = admin_url();
        foreach ($user->roles as $role) {
            $role_redirect_key = "acemedia_login_block_redirect_{$role}";
            $role_redirect_url = get_option($role_redirect_key);
            if (!empty($role_redirect_url)) {
                $redirect_url = esc_url($role_redirect_url);
                break;
            }
        }
        return $redirect_url;
    }

    private function get_user_from_login($login) {
        $login = sanitize_text_field($login);
        $user = get_user_by('login', $login);
        if (!$user && is_email($login)) {
            $user = get_user_by('email', $login);
        }
        return $user ?: null;
    }

    private function generate_state($length = 16) {
        return bin2hex(random_bytes($length));
    }

    private function store_state($prefix, $state, array $data, $ttl) {
        set_transient($prefix . $state, $data, $ttl);
    }

    private function get_state($prefix, $state) {
        return get_transient($prefix . $state);
    }

    private function delete_state($prefix, $state) {
        delete_transient($prefix . $state);
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        return $decoded === false ? '' : $decoded;
    }

    private function buffer_to_base64url($buffer) {
        if ($buffer instanceof ByteBuffer) {
            if (method_exists($buffer, 'getBase64Url')) {
                return $buffer->getBase64Url();
            }
            if (method_exists($buffer, 'getBinaryString')) {
                return $this->base64url_encode($buffer->getBinaryString());
            }
        }
        if (is_string($buffer)) {
            return $this->base64url_encode($buffer);
        }
        return '';
    }

    private function extract_credential_id($createData) {
        $candidates = ['credentialId', 'credentialID', 'credId', 'id'];
        foreach ($candidates as $candidate) {
            if (isset($createData->$candidate)) {
                return $this->buffer_to_base64url($createData->$candidate);
            }
        }

        foreach (get_object_vars($createData) as $value) {
            if ($value instanceof ByteBuffer) {
                return $this->buffer_to_base64url($value);
            }
        }

        return '';
    }

    private function extract_public_key($createData) {
        $candidates = ['credentialPublicKey', 'publicKey', 'publicKeyPem', 'credentialPublicKeyPem'];
        foreach ($candidates as $candidate) {
            if (isset($createData->$candidate) && is_string($createData->$candidate)) {
                return $createData->$candidate;
            }
        }

        foreach (get_object_vars($createData) as $value) {
            if (is_string($value) && strpos($value, 'BEGIN PUBLIC KEY') !== false) {
                return $value;
            }
        }

        return '';
    }

    private function extract_counter($createData, $webauthn) {
        if ($createData) {
            $candidates = ['signatureCounter', 'signCount', 'counter'];
            foreach ($candidates as $candidate) {
                if (isset($createData->$candidate)) {
                    return (int) $createData->$candidate;
                }
            }
        }

        $counter = $webauthn ? $webauthn->getSignatureCounter() : null;
        return $counter === null ? 0 : (int) $counter;
    }

    private function is_locked_out($user_id) {
        $locked_until = (int) get_user_meta($user_id, '_acemedia_login_locked_until', true);
        return $locked_until && time() < $locked_until;
    }
}

new Passkeys();
