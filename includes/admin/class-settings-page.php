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
                'description' => sprintf(__('Redirect URL for %s role', 'acemedia-login-block'), $details['name']),
                'default' => '',
            ]);

            // Register role-specific 2FA settings
            $role_2fa_key = "acemedia_2fa_enabled_{$role}";
            register_setting('acemedia_login_block_options_group', $role_2fa_key, [
                'type' => 'boolean',
                'description' => sprintf(__('Enable 2FA for %s role', 'acemedia-login-block'), $details['name']),
                'default' => false,
            ]);
        }

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
            $selected = selected($custom_page_id, $page->ID, false);
            echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>' . esc_html($page->post_title) . '</option>';
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
        include ACEMEDIA_LOGIN_BLOCK_PATH . 'includes/templates/admin/settings-page.php';
    }
}

// Initialize the settings page
new Settings_Page();