<?php
namespace AceLoginBlock\Auth;


use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\RoundBlockSizeMode;
use AceLoginBlock\Utils\Logging;

if (!defined('ABSPATH')) {
    exit;
}

class Two_Factor {
    private static $instance = null;
    private const TRUSTED_META_KEY = '_acemedia_2fa_trusted_devices';
    private const TRUSTED_COOKIE_PREFIX = 'acemedia_2fa_trusted';
    private const TRUSTED_TTL = 30 * DAY_IN_SECONDS;

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
        add_action('wp_ajax_acemedia_prepare_2fa', [$this, 'prepare_enrolment']);
        add_action('wp_ajax_acemedia_save_2fa_setup', [$this, 'handle_setup']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_init', [$this, 'enforce_2fa_setup'], 1);
        add_action('login_form', [$this, 'acemedia_add_2fa_to_login_form'], 10, 1);
        add_action('woocommerce_login_form', [$this, 'woocommerce_login_fields']);
    }

    /** Shared by WooCommerce My Account and its classic checkout login form. */
    public function woocommerce_login_fields() {
        $redirect = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
        if (function_exists('is_checkout') && is_checkout() && function_exists('wc_get_checkout_url')) {
            $redirect = wc_get_checkout_url();
        }
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label><?php esc_html_e('Two-factor code (if enabled)', 'acemedia-login-block'); ?>
                <input type="text" class="woocommerce-Input input-text" name="2fa_code" autocomplete="one-time-code" value="" />
            </label>
            <small><?php esc_html_e('Use an authentication app or backup code. For email verification, sign in with your password first to receive a code, then enter it here and sign in again.', 'acemedia-login-block'); ?></small>
        </p>
        <p><a href="<?php echo esc_url(wp_login_url($redirect)); ?>"><?php esc_html_e('Use the secure login page for passkey verification', 'acemedia-login-block'); ?></a></p>
        <?php
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
            $role_2fa_required = (bool) get_option("acemedia_2fa_enabled_{$role}", false)
                || (bool) get_option("acemedia_passkey_2fa_required_{$role}", false);
            if ($role_2fa_required) {
                $user_2fa_enabled = (bool) get_user_meta($user_id, '_acemedia_2fa_enabled', true);
                $user_2fa_setup_complete = (bool) get_user_meta($user_id, '_acemedia_2fa_setup_complete', true);
                $role_requires_passkey_2fa = (bool) get_option("acemedia_passkey_2fa_required_{$role}", false);

                if (!$user_2fa_setup_complete || !$user_2fa_enabled) {
                    return true;
                }

                if ($role_requires_passkey_2fa && !self::user_has_registered_passkey_static($user_id)) {
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

        $needs_2fa = $this->user_requires_2fa($user);
        $requires_passkey_2fa = $this->user_requires_passkey_2fa($user);

        if (Passkeys::is_verified_passwordless_user($user->ID)) {
            return $user;
        }

        if (!$needs_2fa) {
            return $user;
        }

        if ($requires_passkey_2fa && !$this->user_has_registered_passkey($user->ID)) {
            return new \WP_Error(
                'passkey_required_not_configured',
                __('Your account requires passkey 2FA, but no passkey is registered yet. Please register a passkey in your profile.', 'acemedia-login-block')
            );
        }

        if (!$requires_passkey_2fa && $this->is_trusted_device($user->ID)) {
            return $user;
        }

        $passkey_token = isset($_POST['acemedia_passkey_token']) ? sanitize_text_field(wp_unslash($_POST['acemedia_passkey_token'])) : '';
        if ($passkey_token && Passkeys::verify_2fa_token($user->ID, $passkey_token)) {
            if (!$requires_passkey_2fa) {
                $this->maybe_trust_device($user->ID);
            }
            return $user;
        }

        if ($requires_passkey_2fa) {
            return new \WP_Error('passkey_2fa_required', __('This account requires passkey verification for 2FA. Use the passkey option to continue.', 'acemedia-login-block'));
        }

        $two_factor_code = isset($_POST['2fa_code']) ? sanitize_text_field(wp_unslash($_POST['2fa_code'])) : '';
        if (empty($two_factor_code)) {
            if ($this->sanitize_2fa_method(get_user_meta($user->ID, '_acemedia_2fa_method', true)) === 'email') {
                $this->send_2fa_email($user->ID);
            }
            add_action('login_form', function() {
                echo '<p><label for="2fa_code">' . 
                     esc_html__('Two-Factor Authentication Code', 'acemedia-login-block') . 
                     '<br /><input type="text" name="2fa_code" id="2fa_code" class="input" value="" size="20" /></label></p>';
            });
            return new \WP_Error('2fa_required', __('Two-factor authentication code required.', 'acemedia-login-block'));
        }

        // Consume only once, after the password authentication chain succeeded.
        $verification_result = $this->verify_user_code($user, $two_factor_code);

        if (is_wp_error($verification_result) || !$verification_result['success']) {
            return new \WP_Error('2fa_invalid', __('Invalid two-factor authentication code.', 'acemedia-login-block'));
        }

        $this->maybe_trust_device($user->ID);

        return $user;
    }

    /**
     * Verify 2FA code
     */
    public function verify_code($request) {
        // Kept for old clients, but public verification must never consume factors.
        return new \WP_Error('2fa_invalid', __('Complete verification using the login form.', 'acemedia-login-block'), ['status' => 400]);
    }

    private function with_factor_lock($user_id, $callback) {
        global $wpdb;
        // The base options table is shared across blogs, like user metadata.
        $lock = 'acemedia_2fa_lock_' . (int) $user_id;
        $owner = time() . ':' . bin2hex(random_bytes(16));
        $table = $wpdb->base_prefix . 'options';
        $acquired = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $table, $lock, $owner
        ));
        if (!$acquired) {
            $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM %i WHERE option_name = %s", $table, $lock));
            if (is_string($held) && (int) $held < time() - 60) {
                $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name = %s AND option_value = %s", $table, $lock, $held));
            }
            return new \WP_Error('2fa_busy', __('Verification is busy. Please try again.', 'acemedia-login-block'));
        }
        try {
            return $callback();
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name = %s AND option_value = %s", $table, $lock, $owner));
        }
    }

    private function verify_user_code($user, $code) {
        return $this->with_factor_lock($user->ID, function () use ($user, $code) {
            $result = $this->verify_user_code_locked($user, $code);
            if (!is_wp_error($result) && !empty($result['success'])) {
                delete_user_meta($user->ID, '_acemedia_2fa_attempts');
            }
            return $result;
        });
    }

    private function verify_user_code_locked($user, $code) {
        // Rate limiting
        if (!$this->check_rate_limit($user->ID)) {
            return new \WP_Error('too_many_attempts', __('Too many attempts. Please try again later.', 'acemedia-login-block'));
        }

        if ($this->user_requires_passkey_2fa($user)) {
            return new \WP_Error('passkey_2fa_required', __('This account requires passkey verification for 2FA.', 'acemedia-login-block'));
        }

        // Verify backup codes first
        if ($this->verify_backup_code($user->ID, $code)) {
            return ['success' => true];
        }

        // Verify method-specific code
        $method = $this->sanitize_2fa_method(get_user_meta($user->ID, '_acemedia_2fa_method', true));
        if ($method === 'auth_app') {
            return $this->verify_auth_app_code($user->ID, $code);
        } else if ($method === 'email') {
            return $this->verify_email_code($user->ID, $code);
        } else if ($method === 'passkey') {
            return new \WP_Error('passkey_2fa_required', __('This account is configured for passkey-based 2FA. Please use your passkey.', 'acemedia-login-block'));
        }

        return $this->verify_email_code($user->ID, $code);
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
        $selected_method = isset($_POST['acemedia_2fa_method']) ? $this->sanitize_2fa_method(sanitize_text_field(wp_unslash($_POST['acemedia_2fa_method']))) : 'email';

        $user = get_userdata($user_id);
        $requires_passkey_2fa = $user ? $this->user_requires_passkey_2fa($user) : false;
        if ($requires_passkey_2fa) {
            $is_2fa_enabled = 1;
            $selected_method = 'passkey';
        }

        if ($selected_method === 'passkey' && !$this->user_has_registered_passkey($user_id)) {
            wp_send_json_error(['message' => __('Please register at least one passkey before selecting passkey as your 2FA method.', 'acemedia-login-block')]);
            return;
        }

        if ($is_2fa_enabled && $selected_method === 'auth_app') {
            $secret = \acemedia_login_block_decrypt(get_user_meta($user_id, '_acemedia_2fa_secret', true));
            $code = isset($_POST['acemedia_2fa_setup_code']) ? sanitize_text_field(wp_unslash($_POST['acemedia_2fa_setup_code'])) : '';
            if (!$secret || !$this->verify_qr_code($secret, $code)) {
                wp_send_json_error(['message' => __('Enter a valid code from your authentication app before saving.', 'acemedia-login-block')]);
                return;
            }
        }
        if ($user && $this->user_requires_2fa($user) && !$is_2fa_enabled) {
            wp_send_json_error(['message' => __('Use your profile to change your two-factor preference.', 'acemedia-login-block')]);
            return;
        }

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
        $username = sanitize_text_field((string) $request->get_param('username'));
        $password = (string) $request->get_param('password');
        $password_token = sanitize_text_field((string) $request->get_param('passwordToken'));
        $passkey_authenticator = null;
        if ($password_token !== '') {
            $passkey_user = Passkeys::inspect_password_token($password_token, $username);
            if (!($passkey_user instanceof \WP_User)) {
                return new \WP_Error('authentication_failed', __('Unable to sign in with those details.', 'acemedia-login-block'), ['status' => 403]);
            }
            $passkey_authenticator = static function ($candidate) use ($passkey_user, $password) {
                if (is_wp_error($candidate)) {
                    return $candidate;
                }
                return apply_filters('wp_authenticate_user', $passkey_user, $password);
            };
            add_filter('authenticate', $passkey_authenticator, 15, 1);
        }
        // Run the complete password-authentication chain except this second-factor step.
        // No factor status, email delivery or second-factor counters before it succeeds.
        remove_filter('authenticate', [$this, 'validate_2fa'], 99);
        try {
            $user = wp_authenticate($username, $password);
        } finally {
            add_filter('authenticate', [$this, 'validate_2fa'], 99, 3);
            if ($passkey_authenticator !== null) {
                remove_filter('authenticate', $passkey_authenticator, 15);
            }
        }
        if (!($user instanceof \WP_User)) {
            return new \WP_Error('authentication_failed', __('Unable to sign in with those details.', 'acemedia-login-block'), ['status' => 403]);
        }

        $needs_2fa = $this->user_requires_2fa($user);
        $requires_passkey_2fa = $this->user_requires_passkey_2fa($user);
        $has_registered_passkey = $this->user_has_registered_passkey($user->ID);

        $passkey_passwordless_allowed = $this->user_allows_passkey_passwordless($user);

        $is_2fa_enabled = (bool) get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
        $selected_method = $this->sanitize_2fa_method(get_user_meta($user->ID, '_acemedia_2fa_method', true));
        if ($requires_passkey_2fa) {
            $selected_method = 'passkey';
        }

        $needs_setup = $needs_2fa && (
            !$is_2fa_enabled
            || !get_user_meta($user->ID, '_acemedia_2fa_setup_complete', true)
            || ($requires_passkey_2fa && !$has_registered_passkey)
        );

        $trusted_device = false;
        if ($needs_2fa && !$needs_setup && !$requires_passkey_2fa) {
            $trusted_device = $this->is_trusted_device($user->ID);
        }

        if ($needs_2fa && !$trusted_device && $selected_method === 'email') {
            $this->send_2fa_email($user->ID);
        }

        return [
            'is2FAEnabled' => $needs_2fa && !$trusted_device,
            'requires2FA' => $needs_2fa,
            'requiresPasskey2FA' => $requires_passkey_2fa,
            'hasRegisteredPasskey' => $has_registered_passkey,
            'passkeyPasswordlessAllowed' => $passkey_passwordless_allowed,
            'method' => $selected_method,
            'needs2FASetup' => $needs_setup,
            'trustedDevice' => $trusted_device,
        ];
    }

    private function user_requires_2fa($user) {
        if (get_user_meta($user->ID, '_acemedia_2fa_enabled', true)) {
            return true;
        }
        foreach ($user->roles as $role) {
            if (
                get_option("acemedia_2fa_enabled_{$role}", false)
                || get_option("acemedia_passkey_2fa_required_{$role}", false)
            ) {
                return true;
            }
        }

        return false;
    }

    private function user_requires_passkey_2fa($user) {
        foreach ($user->roles as $role) {
            if (get_option("acemedia_passkey_2fa_required_{$role}", false)) {
                return true;
            }
        }

        return false;
    }

    private function user_has_registered_passkey($user_id) {
        return self::user_has_registered_passkey_static($user_id);
    }

    private static function user_has_registered_passkey_static($user_id) {
        $passkeys = get_user_meta($user_id, '_acemedia_passkeys', true);
        return is_array($passkeys) && !empty($passkeys);
    }

    private function sanitize_2fa_method($method) {
        $allowed_methods = ['email', 'auth_app', 'passkey'];
        if (!in_array($method, $allowed_methods, true)) {
            return 'email';
        }

        return $method;
    }

    private function user_allows_passkey_passwordless($user) {
        foreach ($user->roles as $role) {
            if (
                get_option("acemedia_passkey_passwordless_{$role}", false)
                || get_option("acemedia_passkey_2fa_required_{$role}", false)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verify QR code
     */
    public function verify_qr_code($secret, $code) {

        try {
            $totp = \OTPHP\TOTP::create($secret);
            // Accept the current code plus one window either side (±30s clock skew).
            return $totp->verify($code, null, 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    
    public function acemedia_log_2fa_attempt($user_id, $data = []) {
        $log = array_merge([
            'time' => current_time('mysql'),
            'ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''))
        ], $data);
    
        $logs = get_user_meta($user_id, '_acemedia_2fa_logs', true) ?: [];
        array_unshift($logs, $log);
        update_user_meta($user_id, '_acemedia_2fa_logs', array_slice($logs, 0, 10));
    }

    /**
     * Send 2FA email
     */
    public function send_2fa_email($user_id) {
        return $this->with_factor_lock($user_id, function () use ($user_id) {
            return $this->send_2fa_email_locked($user_id);
        });
    }

    private function send_2fa_email_locked($user_id) {
        $user = get_userdata($user_id);

        if ($user && is_email($user->user_email)) {
            if ((int) get_user_meta($user_id, '_acemedia_2fa_code_time', true) > time() - MINUTE_IN_SECONDS) {
                return;
            }
            $code = wp_generate_password(6, false, false);
            update_user_meta($user_id, '_acemedia_2fa_code', $code);
            update_user_meta($user_id, '_acemedia_2fa_code_time', time());

            $subject = __('Your 2FA Code', 'acemedia-login-block');
            /* translators: %s: One-time verification code. */
            $message = sprintf(__('Your 2FA code is: %s (valid for 5 minutes)', 'acemedia-login-block'), $code);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            if (!wp_mail($user->user_email, $subject, $message, $headers)) {
                delete_user_meta($user_id, '_acemedia_2fa_code');
                delete_user_meta($user_id, '_acemedia_2fa_code_time');
            }
        }
    }

    public function prepare_enrolment() {
        check_ajax_referer('acemedia_2fa_setup');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Please sign in.', 'acemedia-login-block')], 403);
            return;
        }
        $stored = get_user_meta($user_id, '_acemedia_2fa_secret', true);
        if ($stored && !\acemedia_login_block_decrypt($stored)) {
            wp_send_json_error(['message' => __('Your existing enrolment cannot be read. Contact your administrator for recovery.', 'acemedia-login-block')]);
            return;
        }
        if (!$stored) {
            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
            $secret = '';
            for ($i = 0; $i < 32; $i++) {
                $secret .= $alphabet[random_int(0, 31)];
            }
            $encrypted = \acemedia_login_block_encrypt($secret);
            if (!$encrypted) {
                wp_send_json_error(['message' => __('Secure storage is unavailable.', 'acemedia-login-block')]);
                return;
            }
            add_user_meta($user_id, '_acemedia_2fa_secret', $encrypted, true);
        }
        try {
            wp_send_json_success(['qr' => self::generate_qr_code($user_id)]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => __('QR rendering is unavailable. Please contact your administrator.', 'acemedia-login-block')]);
        }
    }

    /**
     * Generate QR code for 2FA
     */
    public static function generate_qr_code($user_id) {

        $stored = get_user_meta($user_id, '_acemedia_2fa_secret', true);
        $secret = $stored ? \acemedia_login_block_decrypt($stored) : false;
        // Display is read-only. Missing/unreadable enrolments require explicit setup.
        if (!$secret) {
            return '';
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
        
        // Return a data URI instead of saving to a file
        return $result->getDataUri();
    }

    /**
     * Log 2FA attempt
     */
    public function log_2fa_attempt($user_id, $data = []) {
    $log = array_merge([
        'time' => current_time('mysql'),
        'ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
        'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''))
    ], $data);

    $logs = get_user_meta($user_id, '_acemedia_2fa_logs', true) ?: [];
    array_unshift($logs, $log);
    update_user_meta($user_id, '_acemedia_2fa_logs', array_slice($logs, 0, 10));
}




public function acemedia_add_2fa_to_login_form() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof aceLoginBlock === 'undefined' || !aceLoginBlock.check2FAEndpoint || !aceLoginBlock.verify2FAEndpoint) {
                return;
            }

            const STATUS_CLASS = 'acemedia-login-status-message';
            let isSubmitting = false;

            function getStatusElement(form) {
                if (!form) {
                    return null;
                }

                let status = form.querySelector('.' + STATUS_CLASS);
                if (!status) {
                    status = document.createElement('p');
                    status.className = STATUS_CLASS + ' description';
                    status.style.marginTop = '10px';
                    const submitRow = form.querySelector('.submit');
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

            const loginForm = document.querySelector('#loginform');
            if (loginForm) {
                loginForm.addEventListener('submit', handleLoginAttempt);
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
                        twoFactorVerified: createHiddenInput('two_factor_verified', 'false'),
                        passkeyToken: createHiddenInput('acemedia_passkey_token', '')
                    };

                    Object.entries(formInputs).forEach(([key, input]) => {
                        const existing = form.querySelector(`input[name="${input.name}"]`);
                        if (existing) {
                            formInputs[key] = existing;
                        } else {
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

                    const sessionStart = Date.now();
                    const sessionInput = form.querySelector('input[name="session_start"]') || createHiddenInput('session_start', sessionStart);
                    sessionInput.value = sessionStart;
                    if (!sessionInput.parentNode) {
                        form.appendChild(sessionInput);
                    }

                    fetch(aceLoginBlock.check2FAEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            username,
                    password: form.querySelector('input[name="pwd"]')?.value || '',
                    passwordToken: form.querySelector('input[name="acemedia_passkey_password_token"]')?.value || '',
                            timestamp: sessionStart,
                            csrf_token: aceLoginBlock.csrfToken
                        }),
                    })
                    .then(async response => {
                        const data = await response.json();
                        if (!response.ok) {
                            throw new Error(extractApiMessage(data, 'Unable to sign in with those details. Please try again.'));
                        }
                        return data;
                    })
                    .then(data => {
                        if (data.needs2FASetup && !data.requires2FA) {
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
                        showMessage(form, error.message || 'Unable to sign in. Please try again.');
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

                if (isSubmitting) {
                    return;
                }

                if (!twoFactorVerified || twoFactorVerified.value !== 'true') {
                    e.preventDefault();
                    if (!twoFactorState || twoFactorState.value === 'verification') {
                        showMessage(form, 'Please complete two-factor authentication.');
                    }
                    return;
                }

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
                    const usesPasskeyMethod = !!(statusData && statusData.method === 'passkey');
                    const requiresPasskeyOnly = !!(statusData && (statusData.requiresPasskey2FA || usesPasskeyMethod));
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

                    const rememberLabel = document.createElement('label');
                    rememberLabel.style.display = 'block';
                    rememberLabel.style.marginTop = '8px';
                    const rememberCheckbox = document.createElement('input');
                    rememberCheckbox.type = 'checkbox';
                    rememberCheckbox.name = 'acemedia_trust_device';
                    rememberCheckbox.value = '1';
                    rememberLabel.appendChild(rememberCheckbox);
                    rememberLabel.appendChild(document.createTextNode(' ' + (aceLoginBlock.rememberDeviceLabel || 'Remember this device for 2FA for 30 days')));

                    pwdInput.style.display = 'none';
                    if (pwdLabel) pwdLabel.style.display = 'none';
                    if (pwdShowToggle) pwdShowToggle.style.display = 'none';

                    pwdInput.insertAdjacentElement('afterend', twoFAInput);
                    pwdInput.parentElement.insertBefore(rememberLabel, twoFAInput.nextSibling);
                    if (pwdLabel) {
                        pwdLabel.insertAdjacentElement('afterend', twoFALabel);
                    } else {
                        pwdInput.parentElement.insertBefore(twoFALabel, pwdInput);
                    }

                    if (requiresPasskeyOnly) {
                        twoFALabel.textContent = aceLoginBlock.passkeyTwoFALabel || 'Use Passkey for 2FA';
                        twoFAInput.style.display = 'none';
                        twoFAInput.required = false;
                        rememberLabel.style.display = 'none';
                        showMessage(form, 'This account requires passkey verification for 2FA. Use your passkey to continue.', false);
                    }

                    const insertAfterNode = rememberLabel || twoFAInput;
                    let passkeyButton = null;
                    if (aceLoginBlock.passkeysEnabled && window.PublicKeyCredential) {
                        const existingPasskeyLogin = document.getElementById('acemedia-passkey-login');
                        if (existingPasskeyLogin && existingPasskeyLogin.closest('.acemedia-passkey-login')) {
                            existingPasskeyLogin.closest('.acemedia-passkey-login').style.display = 'none';
                        }

                        passkeyButton = document.createElement('button');
                        passkeyButton.id = 'acemedia-passkey-2fa-login';
                        passkeyButton.type = 'button';
                        passkeyButton.className = 'button';

                        const passkeyLabel = aceLoginBlock.passkeyTwoFALabel
                            || (existingPasskeyLogin && existingPasskeyLogin.dataset ? existingPasskeyLogin.dataset.passkeyTwoFALabel : '')
                            || 'Use Passkey for 2FA';
                        passkeyButton.textContent = passkeyLabel;
                        passkeyButton.style.marginTop = '8px';
                        pwdInput.parentElement.insertBefore(passkeyButton, insertAfterNode.nextSibling);
                    }

                    const verify2FA = () => {
                        if (requiresPasskeyOnly) {
                            showMessage(form, 'This account requires passkey verification. Use the passkey button below.', true);
                            return;
                        }

                        const twoFACode = twoFAInput.value;
                        if (!twoFACode) {
                            showMessage(form, 'Please enter your authentication code.');
                            return;
                        }

                        showMessage(form, '');

                        // The final password login consumes the code exactly once server-side.
                formInputs.twoFactorVerified.value = 'true';
                form.dataset.twoFactorVerified = 'true';
                form.submit();
                    };

                    const loginButton = form.querySelector('#wp-submit');
                    loginButton.textContent = aceLoginBlock.submit2FA || 'Verify';
                    loginButton.removeEventListener('click', handleLoginAttempt);
                    loginButton.addEventListener('click', (e) => {
                        e.preventDefault();
                        verify2FA();
                    });

                    if (passkeyButton) {
                        passkeyButton.addEventListener('click', async (e) => {
                            e.preventDefault();
                            if (!window.acemediaPasskeys || !window.acemediaPasskeys.startSecondFactor) {
                                showMessage(form, 'Passkey support is not available in this browser.');
                                return;
                            }

                            passkeyButton.disabled = true;
                            try {
                                const token = await window.acemediaPasskeys.startSecondFactor(username);
                                if (!token) {
                                    showMessage(form, 'Passkey verification failed.');
                                    return;
                                }
                                if (!formInputs || !formInputs.passkeyToken || !formInputs.twoFactorVerified) {
                                    showMessage(form, 'Unable to complete passkey verification. Please refresh and try again.');
                                    return;
                                }
                                formInputs.passkeyToken.value = token;
                                formInputs.twoFactorVerified.value = 'true';
                                isSubmitting = true;
                                form.submit();
                        } catch (error) {
                            console.error('Passkey verification failed:', error);
                            const message = (error && (error.userMessage || error.message)) || 'An error occurred while verifying the passkey.';
                            showMessage(form, message);
                        } finally {
                            passkeyButton.disabled = false;
                        }
                    });
                    }

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

    private function maybe_trust_device($user_id) {
        if (!isset($_POST['acemedia_trust_device'])) {
            return;
        }

        $should_trust = sanitize_text_field(wp_unslash($_POST['acemedia_trust_device']));
        if (!in_array($should_trust, ['1', 'true', 'yes'], true)) {
            return;
        }

        $this->remember_trusted_device($user_id);
    }

    private function remember_trusted_device($user_id) {
        $token = bin2hex(random_bytes(32));
        $hash = wp_hash_password($token);
        $now = time();
        $expires = $now + self::TRUSTED_TTL;

        $devices = $this->get_trusted_devices($user_id);
        $devices[] = [
            'hash' => $hash,
            'created' => $now,
            'expires' => $expires,
            'last_used' => $now,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 200) : '',
        ];

        $devices = $this->trim_trusted_devices($devices);
        update_user_meta($user_id, self::TRUSTED_META_KEY, $devices);

        $this->set_trusted_cookie($token, $expires);
    }

    private function is_trusted_device($user_id) {
        $token = $this->get_trusted_cookie();
        if (!$token) {
            return false;
        }

        $devices = $this->get_trusted_devices($user_id);
        if (empty($devices)) {
            return false;
        }

        $now = time();
        $updated = false;

        foreach ($devices as $index => $device) {
            if (empty($device['expires']) || (int) $device['expires'] < $now) {
                unset($devices[$index]);
                $updated = true;
                continue;
            }

            if (!empty($device['hash']) && wp_check_password($token, $device['hash'])) {
                $devices[$index]['last_used'] = $now;
                $updated = true;
                update_user_meta($user_id, self::TRUSTED_META_KEY, array_values($devices));
                return true;
            }
        }

        if ($updated) {
            update_user_meta($user_id, self::TRUSTED_META_KEY, array_values($devices));
        }

        return false;
    }

    private function get_trusted_cookie() {
        $cookie_name = $this->get_trusted_cookie_name();
        if (!isset($_COOKIE[$cookie_name])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
    }

    private function set_trusted_cookie($token, $expires) {
        $cookie_name = $this->get_trusted_cookie_name();
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();

        $options = [
            'expires' => $expires,
            'path' => COOKIEPATH ? COOKIEPATH : '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($cookie_name, $token, $options);

        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH) {
            $options['path'] = SITECOOKIEPATH;
            setcookie($cookie_name, $token, $options);
        }
    }

    private function get_trusted_cookie_name() {
        $suffix = defined('COOKIEHASH') && COOKIEHASH ? COOKIEHASH : md5(get_site_url());
        return self::TRUSTED_COOKIE_PREFIX . '_' . $suffix;
    }

    private function get_trusted_devices($user_id) {
        $devices = get_user_meta($user_id, self::TRUSTED_META_KEY, true);
        return is_array($devices) ? $devices : [];
    }

    private function trim_trusted_devices(array $devices) {
        $now = time();
        $filtered = [];

        foreach ($devices as $device) {
            if (empty($device['expires']) || (int) $device['expires'] < $now) {
                continue;
            }
            if (empty($device['hash'])) {
                continue;
            }
            $filtered[] = $device;
        }

        usort($filtered, function($a, $b) {
            return (int) ($b['last_used'] ?? 0) <=> (int) ($a['last_used'] ?? 0);
        });

        return array_slice($filtered, 0, 10);
    }

    public static function forget_trusted_device($user_id = null) {
        $token = self::get_trusted_cookie_value();
        if ($token && $user_id) {
            $devices = self::get_trusted_devices_static($user_id);
            $updated = [];

            foreach ($devices as $device) {
                if (!empty($device['hash']) && wp_check_password($token, $device['hash'])) {
                    continue;
                }
                $updated[] = $device;
            }

            update_user_meta($user_id, self::TRUSTED_META_KEY, $updated);
        }

        self::clear_trusted_cookie();
    }

    public static function has_trusted_cookie() {
        return (bool) self::get_trusted_cookie_value();
    }

    private static function get_trusted_cookie_value() {
        $cookie_name = self::get_trusted_cookie_name_static();
        if (!isset($_COOKIE[$cookie_name])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
    }

    private static function clear_trusted_cookie() {
        $cookie_name = self::get_trusted_cookie_name_static();
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();

        $options = [
            'expires' => time() - DAY_IN_SECONDS,
            'path' => COOKIEPATH ? COOKIEPATH : '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($cookie_name, '', $options);

        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH) {
            $options['path'] = SITECOOKIEPATH;
            setcookie($cookie_name, '', $options);
        }
    }

    private static function get_trusted_cookie_name_static() {
        $suffix = defined('COOKIEHASH') && COOKIEHASH ? COOKIEHASH : md5(get_site_url());
        return self::TRUSTED_COOKIE_PREFIX . '_' . $suffix;
    }

    private static function get_trusted_devices_static($user_id) {
        $devices = get_user_meta($user_id, self::TRUSTED_META_KEY, true);
        return is_array($devices) ? $devices : [];
    }


    /**
     * Check rate limit for 2FA attempts
     */
    private function check_rate_limit($user_id) {
        $max = max(1, (int) apply_filters('acemedia_2fa_max_attempts_per_hour', 5));
        // Account-wide metadata prevents rotating between multisite blogs to reset the budget.
        // This runs under the shared account lock in verify_user_code().
        $attempts = get_user_meta($user_id, '_acemedia_2fa_attempts', true);
        if (!is_array($attempts) || ($attempts['expires'] ?? 0) <= time()) {
            $attempts = ['count' => 0, 'expires' => time() + HOUR_IN_SECONDS];
        }
        if ($attempts['count'] >= $max) {
            return false;
        }
        $attempts['count']++;
        return (bool) update_user_meta($user_id, '_acemedia_2fa_attempts', $attempts);

    }

    /**
     * Verify backup code
     */
    private function verify_backup_code($user_id, $code) {
        $backup_codes = get_user_meta($user_id, '_acemedia_2fa_backup_codes', true);
        if (is_array($backup_codes)) {
            foreach ($backup_codes as $index => $code_data) {
                if (wp_check_password($code, $code_data['hash'])) {
                    $previous = $backup_codes;
                    unset($backup_codes[$index]);
                    return (bool) update_user_meta($user_id, '_acemedia_2fa_backup_codes', $backup_codes, $previous);
                }
            }
        }
        return false;
    }

    /**
     * Verify authentication app code
     */
    private function verify_auth_app_code($user_id, $code) {
        $secret = \acemedia_login_block_decrypt(get_user_meta($user_id, '_acemedia_2fa_secret', true));
        if ($secret && $this->verify_qr_code($secret, $code)) {
            $fingerprint = hash('sha256', (string) $code);
            $recent = get_user_meta($user_id, '_acemedia_2fa_recent_totp', true);
            $recent = is_array($recent) ? array_filter($recent, static function ($expiry) { return $expiry >= time(); }) : [];
            // Honour the previous version's single-code replay marker during upgrades.
            $last = get_user_meta($user_id, '_acemedia_2fa_last_totp', true);
            if (is_array($last) && ($last['expires'] ?? 0) >= time() && isset($last['hash'])) {
                $recent[$last['hash']] = $last['expires'];
            }
            if (isset($recent[$fingerprint])) {
                return ['success' => false];
            }
            $recent[$fingerprint] = time() + 90;
            if (!update_user_meta($user_id, '_acemedia_2fa_recent_totp', $recent)) {
                return ['success' => false];
            }
            return ['success' => true];
        }

        Logging::log_event($user_id, '2fa_failed', [
            'method' => 'auth_app',
        ], false);

        return ['success' => false, 'message' => __('Invalid 2FA code!', 'acemedia-login-block')];
    }

    /**
     * Verify email code
     */
    private function verify_email_code($user_id, $code) {
        $expected_code = get_user_meta($user_id, '_acemedia_2fa_code', true);
        $code_time     = (int) get_user_meta($user_id, '_acemedia_2fa_code_time', true);
        $ttl           = (int) apply_filters('acemedia_2fa_email_code_ttl', 5 * MINUTE_IN_SECONDS);

        if ($expected_code && $code_time && (time() - $code_time) <= $ttl
            && hash_equals((string) $expected_code, (string) $code)) {
            // Single-use: invalidate the code once accepted.
            if (!delete_user_meta($user_id, '_acemedia_2fa_code', $expected_code)) {
                return ['success' => false];
            }
            delete_user_meta($user_id, '_acemedia_2fa_code_time');
            return ['success' => true];
        }
        Logging::log_event($user_id, '2fa_failed', [
            'method' => 'email',
        ], false);
        return ['success' => false];
    }
}

// Initialize the class
Two_Factor::get_instance();
