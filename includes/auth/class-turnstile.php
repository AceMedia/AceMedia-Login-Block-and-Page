<?php
/** Optional, step-specific Turnstile protection for core and block authentication. */
namespace AceLoginBlock\Auth;

defined('ABSPATH') || exit;

final class Turnstile {
    public const OPTION = 'acemedia_login_turnstile';
    public const STEPS = ['login', 'two_factor', 'registration', 'password_reset', 'passkey'];
    private static $verified = [];
    private static $rest_login = null;
    private static $rest_passkey = '';
    private static $assets_loaded = false;

    public static function boot() {
        add_action('init', [self::class, 'register_block']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('network_admin_menu', [self::class, 'network_menu']);
        add_action('admin_post_ace_login_turnstile_save', [self::class, 'save']);
        add_filter('render_block_acemedia/login-block', [self::class, 'protect_block'], 10, 2);
        add_action('login_form', [self::class, 'login_fields']);
        add_filter('login_form_middle', static function($content) {
            return $content . self::widget('login') . self::widget('two_factor', true) . self::widget('passkey', true);
        });
        add_action('woocommerce_login_form', [self::class, 'login_fields']);
        add_action('register_form', [self::class, 'registration_fields']);
        add_action('woocommerce_register_form', [self::class, 'registration_fields']);
        add_action('lostpassword_form', [self::class, 'recovery_fields']);
        add_action('woocommerce_lostpassword_form', [self::class, 'recovery_fields']);
        add_filter('authenticate', [self::class, 'authenticate'], 30, 3);
        add_filter('acemedia_login_second_factor_errors', [self::class, 'second_factor']);
        add_filter('registration_errors', [self::class, 'registration_errors'], 30, 3);
        add_filter('woocommerce_registration_errors', [self::class, 'woo_registration'], 30, 3);
        add_filter('lostpassword_errors', [self::class, 'recovery_errors'], 30);
        add_filter('rest_pre_dispatch', [self::class, 'rest_guard'], 15, 3);
        add_filter('rest_post_dispatch', [self::class, 'rest_receipt'], 15, 3);
    }

    public static function settings() {
        $site = get_option(self::OPTION, []);
        $site = is_array($site) ? $site : [];
        if (is_multisite()) {
            $network = get_site_option(self::OPTION, []);
            if (is_array($network) && (!empty($network['enforce']) || !$site)) {
                $site = $network;
            }
        }
        return self::resolve_settings($site);
    }

    private static function resolve_settings(array $site) {
        // Reuse the signup provider instead of copying its secret into another setting.
        if (empty($site['site_key']) && empty($site['secret']) && class_exists('ARG_Turnstile') && \ARG_Turnstile::enabled()) {
            $site['site_key'] = \ARG_Turnstile::site_key();
            $site['secret'] = \ARG_Turnstile::secret();
            $site['provider'] = 'registration-guard';
        }
        if (empty($site['site_key']) && empty($site['secret']) && class_exists('ACE\AdaptiveContentEnhancer\Forms\Turnstile')) {
            $shared = \ACE\AdaptiveContentEnhancer\Forms\Turnstile::settings();
            $site['site_key'] = $shared['site_key'] ?? '';
            $site['secret'] = $shared['secret_key'] ?? '';
            $site['provider'] = 'content';
        }
        $site['steps'] = array_values(array_intersect(self::STEPS, (array) ($site['steps'] ?? [])));
        return $site;
    }

    public static function enabled($step) {
        return in_array($step, self::settings()['steps'], true);
    }

    public static function action($step) { return 'ace_' . $step; }
    public static function field($step) { return 'ace_turnstile_' . $step; }
    private static function error($code, $message, $status = 400) {
        return new \WP_Error('ace_turnstile_' . $code, $message, ['status' => $status]);
    }

    public static function verify($step, $token) {
        if (!self::enabled($step)) return true;
        $s = self::settings();
        if (empty($s['site_key']) || empty($s['secret'])) return self::error('configuration', __('Verification is not configured. Please contact the site administrator.', 'acemedia-login-block'), 503);
        if (!is_string($token) || '' === $token || strlen($token) > 2048) return self::error('missing', __('Please complete the security check and try again.', 'acemedia-login-block'));
        $cache = hash('sha256', get_current_blog_id() . ':' . $s['site_key'] . ':' . $step . ':' . $token);
        if (array_key_exists($cache, self::$verified)) return self::$verified[$cache];
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional Turnstile verification API, disclosed in readme; not an offloaded plugin asset.
        $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['timeout' => 8, 'body' => ['secret' => $s['secret'], 'response' => $token]]);
        if (is_wp_error($res) || 200 !== wp_remote_retrieve_response_code($res)) return self::error('unavailable', __('Verification is temporarily unavailable. Please try again.', 'acemedia-login-block'), 503);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $development = (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) || 'production' !== wp_get_environment_type();
        $test = preg_match('/^[123]x0+AA$/', $s['secret']);
        if (!$development && $test) return self::error('test_keys', __('Production verification keys are required.', 'acemedia-login-block'), 503);
        $valid = is_array($data) && true === ($data['success'] ?? false);
        if (!$test || !$development) {
            $hosts = (array) apply_filters('acemedia_login_turnstile_hostnames', [wp_parse_url(home_url(), PHP_URL_HOST)]);
            $hosts = array_map('strtolower', array_filter($hosts, 'is_string'));
            $valid = $valid && in_array(strtolower((string) ($data['hostname'] ?? '')), $hosts, true) && self::action($step) === ($data['action'] ?? '');
        }
        return self::$verified[$cache] = $valid ? true : self::error('failed', __('The security check did not complete. Please try again.', 'acemedia-login-block'));
    }

    public static function assets() {
        if (self::$assets_loaded) return;
        self::$assets_loaded = true;
        wp_enqueue_script('ace-login-turnstile', ACEMEDIA_LOGIN_BLOCK_URL . 'assets/turnstile.js', [], (string) filemtime(ACEMEDIA_LOGIN_BLOCK_PATH . 'assets/turnstile.js'), true);
        $settings = self::settings();
        wp_add_inline_script('ace-login-turnstile', 'window.aceLoginVerification=' . wp_json_encode([
            'steps' => $settings['steps'], 'siteKey' => $settings['site_key'] ?? '',
            'prompt' => __('Complete the verification to continue.', 'acemedia-login-block'),
            'complete' => __('Verification complete.', 'acemedia-login-block'),
            'expired' => __('Verification expired. Please complete it again.', 'acemedia-login-block'),
            'unavailable' => __('Verification could not load. Please reload the page and try again.', 'acemedia-login-block'),
        ]) . ';', 'before');
    }

    public static function widget($step, $lazy = false) {
        if (!self::enabled($step) || ('registration' === $step && self::guard_owns_registration())) return '';
        self::assets();
        $s = self::settings();
        return '<div class="ace-login-turnstile" data-ace-turnstile-step="' . esc_attr($step) . '" data-sitekey="' . esc_attr($s['site_key'] ?? '') . '" data-action="' . esc_attr(self::action($step)) . '" data-field="' . esc_attr(self::field($step)) . '"' . ($lazy ? ' data-lazy="1" hidden' : '') . '></div>';
    }

    public static function register_block() {
        register_block_type('acemedia/turnstile', ['api_version' => 3, 'attributes' => ['step' => ['type' => 'string', 'default' => 'login']], 'render_callback' => static function($attrs) { return self::widget($attrs['step'] ?? 'login', 'two_factor' === ($attrs['step'] ?? '')); }]);
    }

    public static function protect_block($html, $block = []) {
        foreach (['login', 'two_factor', 'passkey'] as $step) {
            if (self::enabled($step) && false === strpos($html, 'data-ace-turnstile-step="' . $step . '"')) {
                $widget = self::widget($step, 'login' !== $step);
                $html = preg_replace('~</form>~i', $widget . '</form>', $html, 1);
            }
        }
        return $html;
    }

    public static function login_fields() {
        echo self::widget('login'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped markup.
        echo self::widget('passkey', true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget() escapes each attribute in its generated markup.
        echo self::widget('two_factor', true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    private static function guard_owns_registration() { return class_exists('ARG_Turnstile') && \ARG_Turnstile::enabled(); }
    public static function registration_fields() {
        if (!self::guard_owns_registration()) {
            echo self::widget('registration'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget() escapes each attribute in its generated markup.
        }
    }
    public static function recovery_fields() {
        echo self::widget('password_reset'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget() escapes each attribute in its generated markup.
    }
    private static function posted($field) { return isset($_POST[$field]) && is_string($_POST[$field]) ? wp_unslash($_POST[$field]) : ''; }
    private static function context($username, $password, $passkey = '') { return hash_hmac('sha256', wp_json_encode([strtolower(sanitize_user(trim((string) $username))), trim((string) $password), (string) $passkey]), wp_salt('auth')); }

    public static function authenticate($user, $username, $password) {
        if (!($user instanceof \WP_User) || (!self::$rest_login && !self::browser_login())) return $user;
        $passkey = self::$rest_login ? self::$rest_passkey : self::posted('acemedia_passkey_password_token');
        $context = self::context($username, $password, $passkey);
        if (self::enabled('login') && self::$rest_login !== $context) {
            $receipt = self::posted('ace_turnstile_receipt');
            $key = 'ace_login_ts_' . hash('sha256', $receipt);
            $stored = $receipt !== '' ? get_transient($key) : false;
            if (is_string($stored) && hash_equals($stored, $context)) {
                delete_transient($key);
            } else {
                $ok = self::verify('login', self::posted(self::field('login')));
                if (is_wp_error($ok)) return $ok;
            }
        }
        return $user;
    }

    private static function browser_login() {
        return 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && (isset($_POST['log']) || isset($_POST['login']));
    }

    public static function second_factor($user) {
        if (!self::browser_login() || !self::enabled('two_factor')) return $user;
        $ok = self::verify('two_factor', self::posted(self::field('two_factor')));
        return is_wp_error($ok) ? $ok : $user;
    }

    public static function rest_guard($result, $server, $request) {
        $route = $request->get_route();
        $step = '/acemedia/v1/check-2fa' === $route ? 'login' : ('/acemedia/v1/passkeys/login-options' === $route ? 'passkey' : '');
        if (!$step || !self::enabled($step) || null !== $result) return $result;
        $ok = self::verify($step, $request->get_param(self::field($step)));
        if (is_wp_error($ok)) return $ok;
        if ('login' === $step) {
            self::$rest_passkey = (string) $request->get_param('passwordToken');
            self::$rest_login = self::context($request->get_param('username'), $request->get_param('password'), self::$rest_passkey);
        }
        return $result;
    }

    public static function rest_receipt($response, $server, $request) {
        if ('/acemedia/v1/check-2fa' !== $request->get_route() || !self::$rest_login || $response->get_status() >= 300) return $response;
        $data = $response->get_data();
        if (!is_array($data) || !array_key_exists('is2FAEnabled', $data)) return $response;
        $receipt = wp_generate_password(48, false, false);
        set_transient('ace_login_ts_' . hash('sha256', $receipt), self::$rest_login, 5 * MINUTE_IN_SECONDS);
        $data['turnstileReceipt'] = $receipt;
        $response->set_data($data);
        return $response;
    }

    public static function registration_errors($errors, $login = '', $email = '') {
        if (self::guard_owns_registration() || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) return $errors;
        $result = self::verify('registration', self::posted(self::field('registration')));
        if (is_wp_error($result)) $errors->add($result->get_error_code(), $result->get_error_message());
        return $errors;
    }
    public static function woo_registration($errors, $login = '', $email = '') {
        // wc_create_new_customer also runs for checkout, admin and API customers; those are not this form.
        return isset($_POST['register']) ? self::registration_errors($errors, $login, $email) : $errors;
    }
    public static function recovery_errors($errors) {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || (!isset($_POST['user_login']) && !isset($_POST['wc_reset_password']))) return $errors;
        $result = self::verify('password_reset', self::posted(self::field('password_reset')));
        if (is_wp_error($result)) $errors->add($result->get_error_code(), $result->get_error_message());
        return $errors;
    }

    public static function menu() { add_options_page(__('Login verification', 'acemedia-login-block'), __('Login verification', 'acemedia-login-block'), 'manage_options', 'ace-login-turnstile', [self::class, 'render_settings']); }
    public static function network_menu() { add_submenu_page('settings.php', __('Login verification', 'acemedia-login-block'), __('Login verification', 'acemedia-login-block'), 'manage_network_options', 'ace-login-turnstile', [self::class, 'render_settings']); }
    public static function render_settings() {
        $network = is_network_admin();
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) wp_die(esc_html__('You cannot change verification settings.', 'acemedia-login-block'));
        $raw = $network ? get_site_option(self::OPTION, []) : get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        echo '<div class="wrap"><h1>' . esc_html__('Login verification', 'acemedia-login-block') . '</h1><p>' . esc_html__('Choose the authentication steps that need Turnstile. Existing Registration Guard keys are reused when local keys are blank. Registration Guard owns signup verification when it is configured, avoiding duplicate challenges. Passkeys still require their own cryptographic proof.', 'acemedia-login-block') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ace_login_turnstile_save"/><input type="hidden" name="network" value="' . ($network ? '1' : '0') . '"/>';
        wp_nonce_field('ace_login_turnstile_save');
        if (!$network && is_multisite() && !empty(get_site_option(self::OPTION, [])['enforce'])) echo '<p>' . esc_html__('Network settings currently control verification on this site.', 'acemedia-login-block') . '</p>';
        echo '<p><label>' . esc_html__('Site key', 'acemedia-login-block') . ' <input class="regular-text" name="site_key" value="' . esc_attr($raw['site_key'] ?? '') . '"/></label></p><p><label>' . esc_html__('Secret key', 'acemedia-login-block') . ' <input type="password" class="regular-text" name="secret" value="" autocomplete="new-password"/></label></p><p>' . esc_html__('Leave the secret blank to keep it. Leave both keys unset to reuse Registration Guard or Ace Content keys.', 'acemedia-login-block') . '</p><fieldset><legend>' . esc_html__('Protected steps', 'acemedia-login-block') . '</legend>';
        foreach (self::STEPS as $step) {
            if ('registration' === $step && self::guard_owns_registration()) { echo '<p>' . esc_html__('Registration verification is managed in Registration Guard settings.', 'acemedia-login-block') . '</p>'; continue; }
            echo '<p><label><input type="checkbox" name="steps[]" value="' . esc_attr($step) . '"' . checked(in_array($step, $raw['steps'] ?? [], true), true, false) . '/> ' . esc_html(ucwords(str_replace('_', ' ', $step))) . '</label></p>';
        }
        if ($network) echo '<p><label><input type="checkbox" name="enforce" value="1"' . checked(!empty($raw['enforce']), true, false) . '/> ' . esc_html__('Enforce these settings across this network', 'acemedia-login-block') . '</label></p>';
        echo '<p><label><input type="checkbox" name="clear_secret" value="1"/> ' . esc_html__('Remove the saved secret', 'acemedia-login-block') . '</label></p>';
        submit_button();echo '</fieldset></form></div>';
    }
    private static function purge() {
        if (function_exists('ace_redis_cache')) {
            $cache = ace_redis_cache();
            $manager = $cache && method_exists($cache, 'get_cache_manager') ? $cache->get_cache_manager() : null;
            if ($manager && method_exists($manager, 'flush_all_cache')) $manager->flush_all_cache();
            do_action('ace_rc_cache_cleared', 'ace-login-turnstile');
        }
    }
    public static function save() {
        $network = !empty($_POST['network']);
        if (!current_user_can($network ? 'manage_network_options' : 'manage_options')) wp_die(esc_html__('You cannot change verification settings.', 'acemedia-login-block'), '', 403);
        check_admin_referer('ace_login_turnstile_save');
        $raw = $network ? get_site_option(self::OPTION, []) : get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        $secret = sanitize_text_field(self::posted('secret'));
        if (!empty($_POST['clear_secret'])) $raw['secret'] = '';
        $value = ['site_key' => sanitize_text_field(self::posted('site_key')), 'secret' => '' === $secret ? ($raw['secret'] ?? '') : $secret, 'steps' => array_values(array_intersect(self::STEPS, array_map('sanitize_key', (array) ($_POST['steps'] ?? [])))), 'enforce' => $network && !empty($_POST['enforce'])];
        $configured = static function($candidate) {
            $resolved = self::resolve_settings($candidate);
            return !empty($resolved['site_key']) && !empty($resolved['secret']);
        };
        if ($value['steps'] && !$configured($value)) wp_die(esc_html__('Configure verification keys before enabling protected steps.', 'acemedia-login-block'), '', 400);
        if ($network && $value['enforce'] && $value['steps']) {
            $ready = true;
            foreach (get_sites(['number' => 0]) as $site) {
                switch_to_blog($site->blog_id); $ready = $ready && $configured($value); restore_current_blog();
            }
            if (!$ready) wp_die(esc_html__('Configure keys for every site, or enter shared network keys, before enforcing this policy.', 'acemedia-login-block'), '', 400);
        }
        $network ? update_site_option(self::OPTION, $value) : update_option(self::OPTION, $value, false);
        if ($network) {
            foreach (get_sites(['number' => 0]) as $site) { switch_to_blog($site->blog_id); self::purge(); restore_current_blog(); }
        } else { self::purge(); }
        wp_safe_redirect($network ? network_admin_url('settings.php?page=ace-login-turnstile&updated=1') : admin_url('options-general.php?page=ace-login-turnstile&updated=1'));exit;
    }
}
Turnstile::boot();
