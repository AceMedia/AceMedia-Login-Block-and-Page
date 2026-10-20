<?php
namespace AceLoginBlock\Auth;

if (!defined('ABSPATH')) {
    exit;
}

class Login_Handler {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('login_init', [$this, 'load_custom_page_template']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_login_script']);
    }

    /**
     * Replace wp-login.php with the selected custom page content and template.
     */
    public function load_custom_page_template() {
        // Core owns reset, recovery, logout, reauthentication and interim screens.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Fixed-value comparison only; method is never stored or output.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || $action !== 'login'
            || !empty($_REQUEST['interim-login']) || !empty($_REQUEST['reauth'])) {
            return;
        }
        $custom_page_id = (int) get_option('acemedia_login_block_custom_page', 0);
        $page = $custom_page_id ? get_post($custom_page_id) : null;
        if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish') {
            return;
        }
        // Let the normal front-end query select both classic and block templates.
        $url = get_permalink($custom_page_id);
        if (!$url) {
            return;
        }
        if (!empty($_GET['redirect_to']) && is_string($_GET['redirect_to'])) {
            $url = add_query_arg('redirect_to', wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect_to'])), admin_url()), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Enqueue styles and scripts for the custom login page.
     */
    public function enqueue_login_script() {
        if (has_block('acemedia/login-block')) {
            $custom_page_id = (int) get_option('acemedia_login_block_custom_page', 0);
            wp_enqueue_script(
                'acemedia-login-frontend',
                plugin_dir_url(__FILE__) . '../../build/acemedia-login.js',
                [],
                filemtime(plugin_dir_path(__FILE__) . '../../build/acemedia-login.js'),
                true
            );

            // Fetch the current value of the 2FA setting
            $is_2fa_enabled = (bool) get_option('acemedia_2fa_enabled', false);
            $passkeys_enabled = (bool) get_option('acemedia_passkeys_enabled', true);
            $passkeys_supported = $passkeys_enabled && class_exists('\\lbuchs\\WebAuthn\\WebAuthn');

            wp_localize_script('acemedia-login-frontend', 'aceLoginBlock', [
                'loginUrl' => site_url('wp-login.php'),
                'userRoles' => wp_get_current_user()->roles,
                'redirectUrl' => site_url('/wp-admin'),
                'postLoginUrl' => $custom_page_id ? get_permalink($custom_page_id) : '',
                'is2FAEnabled' => $is_2fa_enabled,
                'passkeysEnabled' => $passkeys_supported,
                'twoFALabel' => __('Enter 2FA Code:', 'acemedia-login-block'),
                'twoFAPlaceholder' => __('2FA Code', 'acemedia-login-block'),
                'submit2FA' => __('Verify', 'acemedia-login-block'),
                'rememberDeviceLabel' => __('Remember this device for 2FA for 30 days', 'acemedia-login-block'),
                'passkeyPasswordLabel' => __('Use Passkey for Password', 'acemedia-login-block'),
                'passkeyTwoFALabel' => __('Use Passkey for 2FA', 'acemedia-login-block'),
                'verify2FAEndpoint' => rest_url('acemedia/v1/verify-2fa'),
                'check2FAEndpoint' => rest_url('acemedia/v1/check-2fa'),
                'passkeyLoginOptionsEndpoint' => rest_url('acemedia/v1/passkeys/login-options'),
                'passkeyLoginEndpoint' => rest_url('acemedia/v1/passkeys/login'),
                'csrfToken' => wp_create_nonce('wp_rest'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);
        }
    }
}

// Initialize the class
new Login_Handler();
