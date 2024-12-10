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

            <?php
            $roles = wp_roles()->roles;
            foreach ($roles as $role => $details) :
                $redirect_url = get_option("acemedia_login_block_redirect_{$role}", '');
                $is_2fa_enabled = get_option("acemedia_2fa_enabled_{$role}", false);
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
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
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