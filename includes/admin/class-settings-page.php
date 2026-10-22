<?php
/**
 * Settings Page Handler
 *
 * @package AceLoginBlock
 */

namespace AceLoginBlock\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings_Page {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'add_site_logo_setting']);
        add_action('login_enqueue_scripts', [$this, 'use_site_logo']);
    }

    /**
     * Register settings and create settings page
     */
    public function register_settings() {
        // Register the custom login page setting
        register_setting('acemedia_login_block_options_group', 'acemedia_login_block_custom_page', [
            'type' => 'integer',
            'description' => __('Custom page for login', 'acemedia-login-block'),
            'default' => 0,
        ]);

        // Register settings for redirect URLs and 2FA per role
        $roles = wp_roles()->roles;
        foreach ($roles as $role => $details) {
            // Register role-specific redirect URL settings
            $role_redirect_key = "acemedia_login_block_redirect_{$role}";
            register_setting('acemedia_login_block_options_group', $role_redirect_key, [
                'type' => 'string',
                /* translators: %s: WordPress role name. */
                'description' => sprintf(__('Redirect URL for %s role', 'acemedia-login-block'), $details['name']),
                'default' => '',
            ]);

            // Register role-specific 2FA settings
            $role_2fa_key = "acemedia_2fa_enabled_{$role}";
            register_setting('acemedia_login_block_options_group', $role_2fa_key, [
                'type' => 'boolean',
                /* translators: %s: WordPress role name. */
                'description' => sprintf(__('Enable 2FA for %s role', 'acemedia-login-block'), $details['name']),
                'default' => false,
            ]);

            $role_passkey_passwordless_key = "acemedia_passkey_passwordless_{$role}";
            register_setting('acemedia_login_block_options_group', $role_passkey_passwordless_key, [
                'type' => 'boolean',
                /* translators: %s: WordPress role name. */
                'description' => sprintf(__('Allow passkey passwordless login for %s role', 'acemedia-login-block'), $details['name']),
                'default' => false,
            ]);

            $role_passkey_2fa_required_key = "acemedia_passkey_2fa_required_{$role}";
            register_setting('acemedia_login_block_options_group', $role_passkey_2fa_required_key, [
                'type' => 'boolean',
                /* translators: %s: WordPress role name. */
                'description' => sprintf(__('Require passkey as 2FA method for %s role', 'acemedia-login-block'), $details['name']),
                'default' => false,
            ]);
        }

        register_setting('acemedia_login_block_options_group', 'acemedia_login_lockout_enabled', [
            'type' => 'boolean',
            'description' => __('Enable login lockout', 'acemedia-login-block'),
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_lockout_max_attempts', [
            'type' => 'integer',
            'description' => __('Maximum failed login attempts before lockout', 'acemedia-login-block'),
            'sanitize_callback' => 'absint',
            'default' => 5,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_lockout_window_minutes', [
            'type' => 'integer',
            'description' => __('Failed login attempt window (minutes)', 'acemedia-login-block'),
            'sanitize_callback' => 'absint',
            'default' => 15,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_lockout_duration_minutes', [
            'type' => 'integer',
            'description' => __('Lockout duration (minutes)', 'acemedia-login-block'),
            'sanitize_callback' => 'absint',
            'default' => 30,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_lockout_ip_max_attempts', [
            'type' => 'integer',
            'description' => __('Failed login attempts from one IP address before it is locked', 'acemedia-login-block'),
            'sanitize_callback' => 'absint',
            'default' => 20,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_trusted_proxy_header', [
            'type' => 'string',
            'description' => __('Trusted proxy header for the client IP', 'acemedia-login-block'),
            'sanitize_callback' => function ($value) {
                return $value === 'auto' ? 'auto' : 'off';
            },
            'default' => 'off',
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_lockout_notify', [
            'type' => 'boolean',
            'description' => __('Email the site admin when a lockout happens', 'acemedia-login-block'),
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_login_log_retention_days', [
            'type' => 'integer',
            'description' => __('Days to keep the login attempt log', 'acemedia-login-block'),
            'sanitize_callback' => 'absint',
            'default' => 30,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_security_alerts_enabled', [
            'type' => 'boolean',
            'description' => __('Enable security alert emails', 'acemedia-login-block'),
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_security_alerts_email', [
            'type' => 'string',
            'description' => __('Security alert email recipient', 'acemedia-login-block'),
            'sanitize_callback' => 'sanitize_email',
            'default' => '',
        ]);

        // Add the settings page
        add_options_page(
            __('Ace Login Block Settings', 'acemedia-login-block'),
            __('Ace Login Block', 'acemedia-login-block'),
            'manage_options',
            'acemedia-login-block',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Add site logo setting
     */
    public function add_site_logo_setting() {
        register_setting('acemedia_login_block_options_group', 'acemedia_use_site_logo', [
            'type' => 'boolean',
            'description' => __('Use site logo on login page', 'acemedia-login-block'),
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_passkeys_enabled', [
            'type' => 'boolean',
            'description' => __('Enable passkeys on the login page', 'acemedia-login-block'),
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_passkeys_rp_id', [
            'type' => 'string',
            'description' => __('Passkeys RP ID override (optional)', 'acemedia-login-block'),
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('acemedia_login_block_options_group', 'acemedia_passkeys_attestation', [
            'type' => 'string',
            'description' => __('Passkeys attestation preference', 'acemedia-login-block'),
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'preferred',
        ]);
    }

    /**
     * Handle logo display on login page
     */
    public function use_site_logo() {
        if (get_option('acemedia_use_site_logo', false)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                if ($logo_url) {
                    ?>
                    <style type="text/css">
                        body.login h1 a {
                            background-image: url('<?php echo esc_url($logo_url); ?>');
                            background-size: contain;
                            width: auto;
                            height: 80px;
                        }
                    </style>
                    <?php
                }
            }
        }
    }

    /**
     * Render the custom page dropdown field
     */
    private function render_custom_page_field() {
        $custom_page_id = get_option('acemedia_login_block_custom_page', 0);
        $pages = get_pages();

        echo '<select name="acemedia_login_block_custom_page">';
        echo '<option value="">' . esc_html__('Default WordPress Login', 'acemedia-login-block') . '</option>';

        foreach ($pages as $page) {
            echo '<option value="' . esc_attr($page->ID) . '" ' . selected($custom_page_id, $page->ID, false) . '>' . esc_html($page->post_title) . '</option>';
        }

        echo '</select>';
    }



/**
 * Add the toggle option to the settings page.
 */
private function acemedia_render_site_logo_setting() {
    $use_site_logo = get_option('acemedia_use_site_logo', false);
    ?>
    <tr valign="top">
        <th scope="row"><?php esc_html_e('Use Site Logo on Login Page', 'acemedia-login-block'); ?></th>
        <td>
            <label>
                <input type="checkbox" name="acemedia_use_site_logo" value="1" <?php checked($use_site_logo, true); ?> />
                <?php esc_html_e('Enable', 'acemedia-login-block'); ?>
            </label>
        </td>
    </tr>
    <?php
}




    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        $tabs = [
            'settings' => __('Settings', 'acemedia-login-block'),
            'security' => __('Security', 'acemedia-login-block'),
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'settings';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Ace Login Block Settings', 'acemedia-login-block') . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
                esc_url(add_query_arg(['page' => 'acemedia-login-block', 'tab' => $slug], admin_url('options-general.php'))),
                $slug === $tab ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';

        if ($tab === 'security') {
            Security_Page::instance()->render();
        } else {
            include ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/templates/admin/settings-page.php';
        }
        echo '</div>';
    }
}

// Initialize the settings page
new Settings_Page();
