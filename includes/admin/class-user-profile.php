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
        add_action('user_profile_update_errors', [$this, 'validate_2fa_fields'], 10, 3);
        add_action('personal_options_update', [$this, 'save_2fa_fields']);
        add_action('edit_user_profile_update', [$this, 'save_2fa_fields']);
    }

    /**
     * Add 2FA fields to user profile
     */
    public function add_2fa_fields($user) {
        $role_requires_2fa = $this->role_requires_2fa($user);
        $role_requires_passkey_2fa = $this->role_requires_passkey_2fa($user);
        $has_registered_passkey = $this->user_has_registered_passkey($user->ID);

        $is_2fa_enabled = get_user_meta($user->ID, '_acemedia_2fa_enabled', true);
        $secret = get_user_meta($user->ID, '_acemedia_2fa_secret', true);
        $selected_method = get_user_meta($user->ID, '_acemedia_2fa_method', true) ?: 'email';
        if ($role_requires_passkey_2fa) {
            $selected_method = 'passkey';
        }
        ?>
        <h3><?php esc_html_e('Two-Factor Authentication', 'acemedia-login-block'); ?></h3>
        <?php if ($role_requires_2fa): ?>
            <p class="description"><?php esc_html_e('Your role requires 2FA. Please enable and complete setup below.', 'acemedia-login-block'); ?></p>
        <?php endif; ?>
        <?php if ($role_requires_passkey_2fa): ?>
            <p class="description"><?php esc_html_e('Your role requires passkey verification for the second factor. Register at least one passkey in the “Passkeys / Security Keys” section below.', 'acemedia-login-block'); ?></p>
        <?php endif; ?>
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
                    <select
                        name="acemedia_2fa_method"
                        id="acemedia_2fa_method"
                        data-passkey-required="<?php echo $role_requires_passkey_2fa ? '1' : '0'; ?>"
                        data-has-passkey="<?php echo $has_registered_passkey ? '1' : '0'; ?>"
                    >
                        <option value="email" <?php selected($selected_method, 'email'); ?> <?php disabled($role_requires_passkey_2fa, true); ?>><?php esc_html_e('Email', 'acemedia-login-block'); ?></option>
                        <option value="auth_app" <?php selected($selected_method, 'auth_app'); ?> <?php disabled($role_requires_passkey_2fa, true); ?>><?php esc_html_e('Authentication App', 'acemedia-login-block'); ?></option>
                        <option value="passkey" <?php selected($selected_method, 'passkey'); ?>><?php esc_html_e('Passkey', 'acemedia-login-block'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Passkey is the strongest option and can be used as the second factor when a passkey is registered for this account.', 'acemedia-login-block'); ?>
                    </p>
                    <p class="description" id="acemedia-passkey-onboarding-message" style="display: none; margin-top: 6px;">
                        <?php esc_html_e('Passkey 2FA is selected, but no passkey is registered yet. Register a passkey in the “Passkeys / Security Keys” section below before saving.', 'acemedia-login-block'); ?>
                    </p>
                </td>
            </tr>
            <tr id="acemedia_2fa_qr_row" style="display: <?php echo ($is_2fa_enabled && $selected_method === 'auth_app' && !$role_requires_passkey_2fa) ? 'table-row' : 'none'; ?>">
                <th><label for="acemedia_2fa_qr"><?php esc_html_e('2FA QR Code', 'acemedia-login-block'); ?></label></th>
                <td>
                    <img src="<?php echo esc_attr(Two_Factor::generate_qr_code($user->ID)); ?>" alt="<?php esc_attr_e('2FA QR Code', 'acemedia-login-block'); ?>" />
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
                        const methodSelect = $('#acemedia_2fa_method');
                        const qrRow = $('#acemedia_2fa_qr_row');
                        const enableCheckbox = $('#acemedia_2fa_enabled');
                        const onboardingMessage = $('#acemedia-passkey-onboarding-message');

                        const isPasskeyRequiredByRole = methodSelect.data('passkey-required') === 1 || methodSelect.data('passkey-required') === '1';
                        const hasRegisteredPasskey = methodSelect.data('has-passkey') === 1 || methodSelect.data('has-passkey') === '1';

                        function update2FAUiState() {
                            let method = methodSelect.val();

                            if (isPasskeyRequiredByRole && method !== 'passkey') {
                                methodSelect.val('passkey');
                                method = 'passkey';
                            }

                            const isEnabled = enableCheckbox.is(':checked');
                            const showQr = isEnabled && method === 'auth_app' && !isPasskeyRequiredByRole;
                            const needsPasskeyOnboarding = isEnabled && method === 'passkey' && !hasRegisteredPasskey;

                            qrRow.toggle(showQr);
                            onboardingMessage.toggle(needsPasskeyOnboarding);
                        }

                        methodSelect.on('change', update2FAUiState);
                        enableCheckbox.on('change', update2FAUiState);
                        update2FAUiState();

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

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        $role_requires_passkey_2fa = $this->role_requires_passkey_2fa($user);

        $is_2fa_enabled = isset($_POST['acemedia_2fa_enabled']) ? 1 : 0;
        if ($role_requires_passkey_2fa) {
            $is_2fa_enabled = 1;
        }

        update_user_meta($user_id, '_acemedia_2fa_enabled', $is_2fa_enabled);

        // If 2FA is disabled, reset the setup status
        if (!$is_2fa_enabled) {
            delete_user_meta($user_id, '_acemedia_2fa_setup_complete');
            delete_user_meta($user_id, '_acemedia_2fa_secret');
            delete_user_meta($user_id, '_acemedia_2fa_trusted_devices');
        }

        $selected_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field($_POST['acemedia_2fa_method']) : 'email';
        $allowed_methods = ['email', 'auth_app', 'passkey'];
        if (!in_array($selected_method, $allowed_methods, true)) {
            $selected_method = 'email';
        }

        if ($role_requires_passkey_2fa) {
            $selected_method = 'passkey';
        } elseif ($selected_method === 'passkey' && !$this->user_has_registered_passkey($user_id)) {
            $selected_method = 'email';
        }

        update_user_meta($user_id, '_acemedia_2fa_method', $selected_method);
    }

    public function validate_2fa_fields($errors, $update, $user) {
        if (!($errors instanceof \WP_Error) || !($user instanceof \WP_User)) {
            return;
        }

        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $requested_enabled = isset($_POST['acemedia_2fa_enabled']);
        $requested_method = isset($_POST['acemedia_2fa_method']) ? sanitize_text_field(wp_unslash($_POST['acemedia_2fa_method'])) : 'email';
        $allowed_methods = ['email', 'auth_app', 'passkey'];
        if (!in_array($requested_method, $allowed_methods, true)) {
            $requested_method = 'email';
        }

        $role_requires_passkey_2fa = $this->role_requires_passkey_2fa($user);
        $has_registered_passkey = $this->user_has_registered_passkey($user->ID);

        if ($role_requires_passkey_2fa) {
            if (!$requested_enabled) {
                $errors->add('acemedia_passkey_role_requires_2fa', __('Your role requires passkey-based 2FA. Keep 2FA enabled for this account.', 'acemedia-login-block'));
            }

            if ($requested_method !== 'passkey') {
                $errors->add('acemedia_passkey_role_requires_method', __('Your role requires passkey-based 2FA. Select Passkey as the 2FA method.', 'acemedia-login-block'));
            }

            if (!$has_registered_passkey) {
                $errors->add('acemedia_passkey_role_requires_registration', __('Your role requires passkey-based 2FA, but no passkey is registered. Add one in the “Passkeys / Security Keys” section and save again.', 'acemedia-login-block'));
            }

            return;
        }

        if ($requested_enabled && $requested_method === 'passkey' && !$has_registered_passkey) {
            $errors->add('acemedia_passkey_method_requires_registration', __('Please register at least one passkey in the “Passkeys / Security Keys” section before selecting Passkey as your 2FA method.', 'acemedia-login-block'));
        }
    }

    private function role_requires_2fa($user) {
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

    private function role_requires_passkey_2fa($user) {
        foreach ($user->roles as $role) {
            if (get_option("acemedia_passkey_2fa_required_{$role}", false)) {
                return true;
            }
        }

        return false;
    }

    private function user_has_registered_passkey($user_id) {
        $passkeys = get_user_meta($user_id, '_acemedia_passkeys', true);
        return is_array($passkeys) && !empty($passkeys);
    }
}

// Initialize the user profile handler
new User_Profile();
