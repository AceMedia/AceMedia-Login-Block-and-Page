<?php
// templates/admin/settings-page.php

if (!defined('ABSPATH')) {
    exit;
}

// Get required globals
global $acemedia_admin_pages;
$front_end_pages = get_pages();
?>

<div class="wrap">
    <h1><?php esc_html_e('Ace Login Block Settings', 'acemedia-login-block'); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('acemedia_login_block_options_group');
        do_settings_sections('acemedia_login_block');
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php esc_html_e('Custom Login Page', 'acemedia-login-block'); ?></th>
                <td><?php $this->render_custom_page_field(); ?></td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e('Use Site Logo on Login Page', 'acemedia-login-block'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="acemedia_use_site_logo" value="1" <?php checked(get_option('acemedia_use_site_logo', false), true); ?> />
                        <?php esc_html_e('Enable', 'acemedia-login-block'); ?>
                    </label>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e('Passkeys / Security Keys', 'acemedia-login-block'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="acemedia_passkeys_enabled" value="1" <?php checked(get_option('acemedia_passkeys_enabled', true), true); ?> />
                        <?php esc_html_e('Enable passkeys on wp-login.php', 'acemedia-login-block'); ?>
                    </label>
                    <p style="margin-top: 8px;">
                        <label for="acemedia_passkeys_rp_id">
                            <?php esc_html_e('RP ID override (optional)', 'acemedia-login-block'); ?>
                        </label>
                        <input type="text" id="acemedia_passkeys_rp_id" name="acemedia_passkeys_rp_id" value="<?php echo esc_attr(get_option('acemedia_passkeys_rp_id', '')); ?>" placeholder="example.com" />
                    </p>
                    <p>
                        <label for="acemedia_passkeys_attestation">
                            <?php esc_html_e('Attestation preference', 'acemedia-login-block'); ?>
                        </label>
                        <select id="acemedia_passkeys_attestation" name="acemedia_passkeys_attestation">
                            <?php
                            $attestation = get_option('acemedia_passkeys_attestation', 'preferred');
                            $attestation_options = [
                                'preferred' => __('Preferred', 'acemedia-login-block'),
                                'none' => __('None', 'acemedia-login-block'),
                                'indirect' => __('Indirect', 'acemedia-login-block'),
                                'direct' => __('Direct', 'acemedia-login-block'),
                                'enterprise' => __('Enterprise', 'acemedia-login-block'),
                            ];
                            foreach ($attestation_options as $value => $label) :
                            ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($attestation, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e('Login Lockout', 'acemedia-login-block'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="acemedia_login_lockout_enabled" value="1" <?php checked(get_option('acemedia_login_lockout_enabled', true), true); ?> />
                        <?php esc_html_e('Enable lockout after failed attempts', 'acemedia-login-block'); ?>
                    </label>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e('Max Failed Attempts', 'acemedia-login-block'); ?></th>
                <td>
                    <input type="number" min="1" name="acemedia_login_lockout_max_attempts" value="<?php echo esc_attr(get_option('acemedia_login_lockout_max_attempts', 5)); ?>" />
                    <p class="description"><?php esc_html_e('Number of failed attempts before an account is locked.', 'acemedia-login-block'); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e('Attempt Window (minutes)', 'acemedia-login-block'); ?></th>
                <td>
                    <input type="number" min="1" name="acemedia_login_lockout_window_minutes" value="<?php echo esc_attr(get_option('acemedia_login_lockout_window_minutes', 15)); ?>" />
                    <p class="description"><?php esc_html_e('Time window for counting failed attempts.', 'acemedia-login-block'); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e('Lockout Duration (minutes)', 'acemedia-login-block'); ?></th>
                <td>
                    <input type="number" min="1" name="acemedia_login_lockout_duration_minutes" value="<?php echo esc_attr(get_option('acemedia_login_lockout_duration_minutes', 30)); ?>" />
                    <p class="description"><?php esc_html_e('How long the account stays locked.', 'acemedia-login-block'); ?></p>
                </td>
            </tr>

            <?php
            $roles = wp_roles()->roles;
            foreach ($roles as $role => $details) :
                $redirect_url = get_option("acemedia_login_block_redirect_{$role}", '');
                $is_2fa_enabled = get_option("acemedia_2fa_enabled_{$role}", false);
                $allow_passkey_passwordless = get_option("acemedia_passkey_passwordless_{$role}", false);
            ?>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html(ucfirst($role)); ?></th>
                    <td>
                        <label for="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>"><?php esc_html_e('Redirect: ', 'acemedia-login-block'); ?>
                            <select id="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>" name="acemedia_login_block_redirect_<?php echo esc_attr($role); ?>">
                                <option value=""><?php esc_html_e('Default behaviour', 'acemedia-login-block'); ?></option>

                                <!-- Frontend Pages -->
                                <option disabled><?php esc_html_e('--- Frontend Pages ---', 'acemedia-login-block'); ?></option>
                                <?php foreach ($front_end_pages as $page) : ?>
                                    <option value="<?php echo esc_attr(get_permalink($page->ID)); ?>" <?php selected(esc_url($redirect_url), get_permalink($page->ID)); ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>

                                <!-- Admin Pages -->
                                <option disabled><?php esc_html_e('--- Admin Pages ---', 'acemedia-login-block'); ?></option>
                                <?php foreach ($acemedia_admin_pages as $page => $info) :
                                    if (user_can(get_role($role), $info['capability'])) :
                                        $admin_url = admin_url($page);
                                ?>
                                        <option value="<?php echo esc_attr($admin_url); ?>" <?php selected(esc_url($redirect_url), $admin_url); ?>>
                                            <?php echo esc_html($info['title']); ?>
                                        </option>
                                <?php 
                                    endif;
                                endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr("acemedia_2fa_enabled_{$role}"); ?>" value="1" <?php checked($is_2fa_enabled, true); ?>>
                            <?php esc_html_e('Requires 2FA', 'acemedia-login-block'); ?>
                        </label>
                        <label style="margin-left: 8px;">
                            <input type="checkbox" name="<?php echo esc_attr("acemedia_passkey_passwordless_{$role}"); ?>" value="1" <?php checked($allow_passkey_passwordless, true); ?>>
                            <?php esc_html_e('Allow passkey passwordless', 'acemedia-login-block'); ?>
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="description">
            <?php esc_html_e('Remembered devices only skip the 2FA step. Users still need their password.', 'acemedia-login-block'); ?>
        </p>
        <?php submit_button(); ?>
    </form>

    <!-- 2FA Logs Section -->
    <h2><?php esc_html_e('Two-Factor Authentication Logs', 'acemedia-login-block'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e('Time', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('User', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('IP Address', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Action', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Status', 'acemedia-login-block'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        global $wpdb;
        $logs = [];
        $users = get_users();
        $total_log_size = 0;

        // Collect logs from all users
        foreach ($users as $user) {
            $user_logs = get_user_meta($user->ID, '_acemedia_2fa_logs', true) ?: [];
            foreach ($user_logs as $log) {
                $log['username'] = $user->user_login;
                if (isset($log['time']) && strtotime($log['time']) > strtotime('-24 hours')) {
                    $logs[] = $log;
                }
            }
            $total_log_size += strlen(serialize($user_logs));
        }

        // Sort logs by time, newest first
        usort($logs, function($a, $b) {
            return strtotime($b['time']) - strtotime($a['time']);
        });

        if (empty($logs)): ?>
            <tr>
                <td colspan="5"><?php esc_html_e('No failed login attempts in the last 24 hours.', 'acemedia-login-block'); ?></td>
            </tr>
        <?php else:
            foreach ($logs as $log): ?>
            <tr>
                <td><?php echo esc_html(get_date_from_gmt($log['time'])); ?></td>
                <td><?php echo esc_html($log['username']); ?></td>
                <td><?php echo esc_html($log['ip']); ?></td>
                <td><?php echo esc_html($log['action'] ?? 'verify_2fa'); ?></td>
                <td>
                <?php if (isset($log['success']) && $log['success']): ?>
                    <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                    <?php esc_html_e('Success', 'acemedia-login-block'); ?>
                <?php else: ?>
                    <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                    <?php esc_html_e('Failed', 'acemedia-login-block'); ?>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach;
        endif; ?>
    </tbody>
</table>

    <div style="margin-top: 20px;">
        <p>
            <?php printf(esc_html__('Total log size: %s', 'acemedia-login-block'), size_format($total_log_size)); ?>
        </p>
        <form method="post" action="">
            <?php wp_nonce_field('clear_2fa_logs', 'clear_2fa_logs_nonce'); ?>
            <input type="submit" name="clear_2fa_logs" class="button button-secondary" value="<?php esc_attr_e('Clear All 2FA Logs', 'acemedia-login-block'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all 2FA logs? This cannot be undone.', 'acemedia-login-block'); ?>');" />
        </form>
    </div>
</div>
