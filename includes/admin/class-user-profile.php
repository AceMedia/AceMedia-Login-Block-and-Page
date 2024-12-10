<?php
/**
 * User Profile Handler
 *
 * @package AceLoginBlock
 */

namespace AceLoginBlock\Admin;

use AceLoginBlock\Auth\Two_Factor;

if (!defined('ABSPATH')) {
    exit;
}

class User_Profile {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('show_user_profile', [$this, 'add_2fa_fields'], 1);
        add_action('edit_user_profile', [$this, 'add_2fa_fields'], 1);
        add_action('personal_options_update', [$this, 'save_2fa_fields']);
        add_action('edit_user_profile_update', [$this, 'save_2fa_fields']);
    }

    /**
     * Add 2FA fields to user profile
     */
    public function add_2fa_fields($user) {
        $is_2fa_enabled_global = (bool) get_option('acemedia_2fa_enabled', false);
        if (!$is_2fa_enabled_global) {
            return;
        }

        $is_2fa_enabled = get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
        $secret = get_user_meta($user->ID, '_acemedia_2fa_secret', true);
        $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true) ?: 'email';
        ?>
        <h3><?php esc_html_e('Two-Factor Authentication', 'acemedia-login-block'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="acemedia_2fa_enabled"><?php esc_html_e('Enable 2FA', 'acemedia-login-block'); ?></label></th>
                <td>
                    <input type="checkbox" name="acemedia_2fa_enabled" id="acemedia_2fa_enabled" value="1" <?php checked($is_2fa_enabled, 1); ?> />
                </td>
            </tr>
            <tr>
                <th><label for="acemedia_2fa_method"><?php esc_html_e('2FA Method', 'acemedia-login-block'); ?></label></th>
                <td>
                    <select name="acemedia_2fa_method" id="acemedia_2fa_method">
                        <option value="email" <?php selected($selected_method, 'email'); ?>><?php esc_html_e('Email', 'acemedia-login-block'); ?></option>
                        <option value="auth_app" <?php selected($selected_method, 'auth_app'); ?>><?php esc_html_e('Authentication App', 'acemedia-login-block'); ?></option>
                    </select>
                </td>
            </tr>
            <tr id="acemedia_2fa_qr_row" style="display: <?php echo ($is_2fa_enabled && $selected_method === 'auth_app') ? 'table-row' : 'none'; ?>">
                <th><label for="acemedia_2fa_qr"><?php esc_html_e('2FA QR Code', 'acemedia-login-block'); ?></label></th>
                <td>
                    <?php $qr_code_url = Two_Factor::generate_qr_code($user->ID); ?>
                    <img src="<?php echo esc_url($qr_code_url); ?>" alt="<?php esc_attr_e('2FA QR Code', 'acemedia-login-block'); ?>" />
                    <p class="description"><?php esc_html_e('Scan this QR code with your authentication app.', 'acemedia-login-block'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Backup Codes', 'acemedia-login-block'); ?></th>
                <td>
                    <button type="button" class="button" id="generate-backup-codes">
                        <?php esc_html_e('Generate New Backup Codes', 'acemedia-login-block'); ?>
                    </button>
                    <div id="backup-codes-container" style="display: none; margin-top: 10px;">
                        <p class="description">
                            <?php esc_html_e('Save these backup codes in a secure location. Each code can only be used once.', 'acemedia-login-block'); ?>
                        </p>
                        <pre id="backup-codes" style="background: #f1f1f1; padding: 10px; margin: 10px 0;"></pre>
                        <button type="button" class="button" id="download-backup-codes">
                            <?php esc_html_e('Download Backup Codes', 'acemedia-login-block'); ?>
                        </button>
                    </div>
                    <script>
                    jQuery(document).ready(function($) {
                        $('#generate-backup-codes').on('click', function() {
                            if (!confirm('<?php esc_html_e('Generating new backup codes will invalidate any existing codes. Continue?', 'acemedia-login-block'); ?>')) {
                                return;
                            }
                            $.post(ajaxurl, {
                                action: 'get_or_generate_backup_codes',
                                _ajax_nonce: '<?php echo wp_create_nonce("get_or_generate_backup_codes"); ?>',
                                force_new: true // Force new codes when explicitly requested
                            }, function(response) {
                                if (response.success) {
                                    $('#backup-codes').text(response.data.codes.join('\n'));
                                    $('#backup-codes-container').show();
                                }
                            });
                        });

                        $('#download-backup-codes').on('click', function() {
                            const codes = $('#backup-codes').text();
                            const siteDomain = '<?php echo sanitize_file_name(parse_url(get_site_url(), PHP_URL_HOST)); ?>';
                            const filename = siteDomain + '-2fa-backup-codes.txt';

                            const blob = new Blob([codes], { type: 'text/plain' });
                            const a = document.createElement('a');
                            a.href = URL.createObjectURL(blob);
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(a.href);
                        });
                    });
                    </script>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save 2FA settings
     */
    public function save_2fa_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }

        $is_2fa_enabled_global = (bool) get_option('acemedia_2fa_enabled', false);
        if (!$is_2fa_enabled_global) {
            delete_user_meta($user_id, '_acemedia_2fa_enabled');
            delete_user_meta($user_id, '_acemedia_2fa_method');
            delete_user_meta($user_id, '_acemedia_2fa_secret');
            return;
        }

        $is_2fa_enabled = isset($_POST['acemedia_2fa_enabled']) ? 1 : 0;
        update_user_meta($user_id, '_acemedia_2fa_enabled', $is_2fa_enabled);

        // If 2FA is disabled, reset the setup status
        if (!$is_2fa_enabled) {
            delete_user_meta($user_id, '_acemedia_2fa_setup_complete');
            delete_user_meta($user_id, '_acemedia_2fa_secret');
        }

        $selected_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field($_POST['acemedia_2fa_method']) : 'email';
        update_user_meta($user_id, '_acemedia_2fa_method', $selected_method);
    }
}

// Initialize the user profile handler
new User_Profile();