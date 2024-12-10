<?php
namespace AceLoginBlock\Auth;


use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\RoundBlockSizeMode;

if (!defined('ABSPATH')) {
    exit;
}

class Two_Factor {
    private static $instance = null;

    /**
     * Get the instance of the class
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /**
     * Initialize the class
     */
    public function __construct() {
        add_filter('authenticate', [$this, 'validate_2fa'], 99, 3);
        add_action('wp_ajax_acemedia_save_2fa_setup', [$this, 'handle_setup']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_init', [$this, 'enforce_2fa_setup'], 1);
        add_action('login_form', [$this, 'acemedia_add_2fa_to_login_form'], 10, 1);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('acemedia/v1', '/check-2fa', [
            'methods' => 'POST',
            'callback' => [$this, 'check_2fa_status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('acemedia/v1', '/verify-2fa', [
            'methods' => 'POST',
            'callback' => [$this, 'verify_code'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Check if user needs 2FA setup
     */
    public static function user_needs_setup($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        foreach ($user->roles as $role) {
            $role_2fa_required = (bool) get_option("acemedia_2fa_enabled_{$role}", false);
            if ($role_2fa_required) {
                $user_2fa_enabled = (bool) get_user_meta($user_id, '_acemedia_2fa_enabled', true);
                $user_2fa_setup_complete = (bool) get_user_meta($user_id, '_acemedia_2fa_setup_complete', true);

                if (!$user_2fa_setup_complete || !$user_2fa_enabled) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate 2FA during login
     */
    public function validate_2fa($user, $username, $password) {
        if (!$user || is_wp_error($user)) {
            return $user;
        }

        // Check if role requires 2FA
        $needs_2fa = false;
        foreach ($user->roles as $role) {
            if (get_option("acemedia_2fa_enabled_{$role}", false)) {
                $needs_2fa = true;
                break;
            }
        }

        if (!$needs_2fa) {
            return $user;
        }

        $two_factor_code = isset($_POST['2fa_code']) ? $_POST['2fa_code'] : '';
        if (empty($two_factor_code)) {
            add_action('login_form', function() {
                echo '<p><label for="2fa_code">' . 
                     esc_html__('Two-Factor Authentication Code', 'acemedia-login-block') . 
                     '<br /><input type="text" name="2fa_code" id="2fa_code" class="input" value="" size="20" /></label></p>';
            });
            return new \WP_Error('2fa_required', __('Two-factor authentication code required.', 'acemedia-login-block'));
        }

        // Verify the code
        $request = new \WP_REST_Request('POST', '/acemedia/v1/verify-2fa');
        $request->set_param('code', $two_factor_code);
        $request->set_param('username', $username);

        $verification_result = $this->verify_code($request);

        if (is_wp_error($verification_result) || !$verification_result['success']) {
            return new \WP_Error('2fa_invalid', __('Invalid two-factor authentication code.', 'acemedia-login-block'));
        }

        return $user;
    }

    /**
     * Verify 2FA code
     */
    public function verify_code($request) {
        $code = $request->get_param('code');
        $username = $request->get_param('username');

        if (!$username) {
            return new \WP_Error('missing_username', __('Username is required.', 'acemedia-login-block'));
        }

        $user = get_user_by('login', sanitize_text_field($username));
        if (!$user) {
            return new \WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'));
        }

        // Rate limiting
        if (!$this->check_rate_limit($user->ID)) {
            return new \WP_Error('too_many_attempts', __('Too many attempts. Please try again later.', 'acemedia-login-block'));
        }

        // Verify backup codes first
        if ($this->verify_backup_code($user->ID, $code)) {
            return ['success' => true];
        }

        // Verify method-specific code
        $method = get_user_meta($user->ID, '_acemedia_2fa_method', true);
        if ($method === 'auth_app') {
            return $this->verify_auth_app_code($user->ID, $code);
        } else if ($method === 'email') {
            return $this->verify_email_code($user->ID, $code);
        }


    }

    /**
     * Enforce 2FA setup
     */
    public function enforce_2fa_setup() {
        if (wp_doing_ajax() || defined('REST_REQUEST')) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        if (self::user_needs_setup($user_id)) {
            global $pagenow;
            $allowed_pages = ['profile.php', 'admin-ajax.php'];

            if (!in_array($pagenow, $allowed_pages)) {
                wp_safe_redirect(admin_url('profile.php'));
                exit;
            }
        }
    }

    /**
     * Handle 2FA setup via AJAX
     */
    public function handle_setup() {
        check_ajax_referer('acemedia_2fa_setup');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in']);
            return;
        }

        $is_2fa_enabled = isset($_POST['acemedia_2fa_enabled']) ? 1 : 0;
        $selected_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field($_POST['acemedia_2fa_method']) : 'email';

        update_user_meta($user_id, '_acemedia_2fa_enabled', $is_2fa_enabled);
        update_user_meta($user_id, '_acemedia_2fa_method', $selected_method);
        update_user_meta($user_id, '_acemedia_2fa_setup_complete', true);

        $user = get_userdata($user_id);
        $redirect_url = admin_url();

        foreach ($user->roles as $role) {
            $role_redirect_key = "acemedia_login_block_redirect_{$role}";
            $role_redirect_url = get_option($role_redirect_key);

            if (!empty($role_redirect_url)) {
                $redirect_url = $role_redirect_url;
                break;
            }
        }

        wp_send_json_success([
            'message' => 'Settings saved',
            'redirect' => $redirect_url
        ]);
    }

    /**
     * Check 2FA status via REST API
     */
    public function check_2fa_status($request) {
        $username = $request->get_param('username');
        $user = get_user_by('login', sanitize_text_field($username));

        if (!$user) {
            return new \WP_Error('invalid_username', __('Invalid username.', 'acemedia-login-block'), ['status' => 404]);
        }

        $needs_2fa = false;
        foreach ($user->roles as $role) {
            if (get_option("acemedia_2fa_enabled_{$role}", false)) {
                $needs_2fa = true;
                break;
            }
        }

        $is_2fa_enabled = (bool) get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
        $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true);
        $needs_setup = $needs_2fa && (!$is_2fa_enabled || !get_user_meta($user->ID, '_acemedia_2fa_setup_complete', true));

        return [
            'is2FAEnabled' => $is_2fa_enabled && $needs_2fa,
            'method' => $selected_method,
            'needs2FASetup' => $needs_setup,
        ];
    }

    /**
     * Verify QR code
     */
    public function verify_qr_code($secret, $code) {

        try {
            $totp = \OTPHP\TOTP::create($secret);
            return $totp->verify($code);
        } catch (Exception $e) {
            error_log('TOTP verification error: ' . $e->getMessage());
            return false;
        }
    }

    
    public function acemedia_log_2fa_attempt($user_id, $data = []) {
        $log = array_merge([
            'time' => current_time('mysql'),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT']
        ], $data);
    
        $logs = get_user_meta($user_id, '_acemedia_2fa_logs', true) ?: [];
        array_unshift($logs, $log);
        update_user_meta($user_id, '_acemedia_2fa_logs', array_slice($logs, 0, 10));
    }

    /**
     * Send 2FA email
     */
    public function send_2fa_email($user_id) {
        $user = get_userdata($user_id);

        if ($user && is_email($user->user_email)) {
            $code = wp_generate_password(6, false, false);
            update_user_meta($user_id, '_acemedia_2fa_code', $code);
            update_user_meta($user_id, '_acemedia_2fa_code_time', time());

            $subject = __('Your 2FA Code', 'acemedia-login-block');
            $message = sprintf(__('Your 2FA code is: %s (valid for 5 minutes)', 'acemedia-login-block'), $code);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            if (!wp_mail($user->user_email, $subject, $message, $headers)) {
                error_log('Failed to send 2FA email to ' . $user->user_email);
            }
        }
    }

    /**
     * Generate QR code for 2FA
     */
    public static function generate_qr_code($user_id) {

        $secret = get_user_meta($user_id, '_acemedia_2fa_secret', true);
        if (!$secret) {
            $base32_alphabet = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');
            $secret = '';

            for ($i = 0; $i < 32; $i++) {
                $secret .= $base32_alphabet[array_rand($base32_alphabet)];
            }

            update_user_meta($user_id, '_acemedia_2fa_secret', $secret);
        }

        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $username = get_userdata($user_id)->user_login;

        $uri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($site_name),
            rawurlencode($username),
            $secret,
            rawurlencode($site_name)
        );

        $qrCode = new QrCode(
            data: $uri,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $qrcodes_dir = ACEMEDIA_LOGIN_BLOCK_URL . 'qrcodes/';
        if (!file_exists($qrcodes_dir)) {
            mkdir($qrcodes_dir, 0755, true);
        }

        $result->saveToFile($qrcodes_dir . $user_id . '.png');

        return $qrcodes_dir . $user_id . '.png';
    }

    /**
     * Log 2FA attempt
     */
    public function log_2fa_attempt($user_id, $data = []) {
    $log = array_merge([
        'time' => current_time('mysql'),
        'ip' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ], $data);

    $logs = get_user_meta($user_id, '_acemedia_2fa_logs', true) ?: [];
    array_unshift($logs, $log);
    update_user_meta($user_id, '_acemedia_2fa_logs', array_slice($logs, 0, 10));
}




public function acemedia_add_2fa_to_login_form() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            if (!aceLoginBlock.is2FAEnabled) {
                return;
            }

            const loginButton = document.querySelector('#wp-submit');
            if (loginButton) {
                loginButton.addEventListener('click', handleLoginAttempt);
            }

            function handleLoginAttempt(event) {
                event.preventDefault();
                const form = event.target.closest('form');

                if (form) {
                    form.removeEventListener('submit', handleFormSubmit);
                    form.addEventListener('submit', handleFormSubmit);

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

                    const sessionStart = Date.now();
                    createHiddenInput('session_start', sessionStart);

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
                    const pwdInput = form.querySelector('input[name="pwd"]');
                    const pwdLabel = form.querySelector('label[for="user_pass"]');
                    const pwdShowToggle = form.querySelector('span[data-show-password="true"]');

                    const twoFALabel = document.createElement('label');
                    twoFALabel.setAttribute('for', '2fa_code');
                    twoFALabel.textContent = aceLoginBlock.twoFALabel || 'Enter Authentication Code';

                    const twoFAInput = document.createElement('input');
                    twoFAInput.type = 'text';
                    twoFAInput.name = '2fa_code';
                    twoFAInput.className = 'tfa-code-input';
                    twoFAInput.placeholder = aceLoginBlock.twoFAPlaceholder || 'Authentication Code';
                    twoFAInput.required = true;

                    pwdInput.style.display = 'none';
                    if (pwdLabel) pwdLabel.style.display = 'none';
                    if (pwdShowToggle) pwdShowToggle.style.display = 'none';

                    pwdInput.insertAdjacentElement('afterend', twoFAInput);
                    if (pwdLabel) {
                        pwdLabel.insertAdjacentElement('afterend', twoFALabel);
                    } else {
                        pwdInput.parentElement.insertBefore(twoFALabel, pwdInput);
                    }

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

                    const loginButton = form.querySelector('#wp-submit');
                    loginButton.textContent = aceLoginBlock.submit2FA || 'Verify';
                    loginButton.removeEventListener('click', handleLoginAttempt);
                    loginButton.addEventListener('click', (e) => {
                        e.preventDefault();
                        verify2FA();
                    });

                    twoFAInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            verify2FA();
                        }
                    });
                }
            }
        });
    </script>
    <?php
}


    /**
     * Check rate limit for 2FA attempts
     */
    private function check_rate_limit($user_id) {
        $attempts = get_transient('2fa_attempts_' . $user_id);
        if ($attempts === false) {
            set_transient('2fa_attempts_' . $user_id, 1, HOUR_IN_SECONDS);
            return true;
        } else if ($attempts >= 1000) {
            return false;
        } else {
            set_transient('2fa_attempts_' . $user_id, $attempts + 1, HOUR_IN_SECONDS);
            return true;
        }
    }

    /**
     * Verify backup code
     */
    private function verify_backup_code($user_id, $code) {
        $backup_codes = get_user_meta($user_id, '_acemedia_2fa_backup_codes', true);
        if (is_array($backup_codes)) {
            foreach ($backup_codes as $index => $code_data) {
                if (wp_check_password($code, $code_data['hash'])) {
                    unset($backup_codes[$index]);
                    update_user_meta($user_id, '_acemedia_2fa_backup_codes', $backup_codes);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Verify authentication app code
     */
    private function verify_auth_app_code($user_id, $code) {
        $secret = get_user_meta($user_id, '_acemedia_2fa_secret', true);
        if ($secret && $this->verify_qr_code($secret, $code)) {

            // Log failed attempt
         //   $this->log_2fa_attempt($user_id, [
         //       'action' => 'verify_2fa',
          //      'success' => true
          //  ]);
            return ['success' => true];
        }


        // Log failed attempt
        $this->log_2fa_attempt($user_id, [
            'action' => 'verify_2fa',
            'success' => false
        ]);

        return ['success' => false, 'message' => __('Invalid 2FA code!', 'acemedia-login-block')];
    }

    /**
     * Verify email code
     */
    private function verify_email_code($user_id, $code) {
        $expected_code = get_user_meta($user_id, '_acemedia_2fa_code', true);
        if ($code === $expected_code) {
            return ['success' => true];
        }
        return ['success' => false];
    }
}

// Initialize the class
Two_Factor::get_instance();



