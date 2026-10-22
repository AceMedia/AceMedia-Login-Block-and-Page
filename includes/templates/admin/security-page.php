<?php
// templates/admin/security-page.php

use AceLoginBlock\Admin\Security_Page;

if (!defined('ABSPATH')) {
    exit;
}

$now = time();
$datetime_format = get_option('date_format') . ' ' . get_option('time_format');
$lock_labels = [
    'account' => __('Account', 'acemedia-login-block'),
    'name' => __('Unknown username', 'acemedia-login-block'),
    'ip' => __('IP address', 'acemedia-login-block'),
];
?>

<?php if ($notice === 'unlocked') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Unlocked and failed count cleared.', 'acemedia-login-block'); ?></p></div>
<?php elseif ($notice === 'purged') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Old log entries removed.', 'acemedia-login-block'); ?></p></div>
<?php endif; ?>

<?php if ($proxy_warning) : ?>
    <div class="notice notice-warning">
        <p>
            <?php
            printf(
                /* translators: 1: percentage, 2: IP address, 3: number of attempts. */
                esc_html__('%1$d%% of the last %3$d failed logins came from one address (%2$s). If the site is behind a proxy or CDN, every visitor may be showing that address, so IP locking could block everyone. Set the Trusted Proxy Header on the Settings tab, fix the server real IP setup, or set the IP threshold to 0.', 'acemedia-login-block'),
                (int) round($proxy_warning['share'] * 100),
                esc_html($proxy_warning['ip']),
                (int) $proxy_warning['total']
            );
            ?>
        </p>
    </div>
<?php endif; ?>

<h2><?php esc_html_e('Accounts with failed logins', 'acemedia-login-block'); ?></h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e('Username', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Failed attempts', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Status', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Action', 'acemedia-login-block'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($users)) : ?>
            <tr><td colspan="4"><?php esc_html_e('No accounts have failed logins right now.', 'acemedia-login-block'); ?></td></tr>
        <?php else : foreach ($users as $row) : ?>
            <tr>
                <td><a href="<?php echo esc_url(get_edit_user_link($row['id'])); ?>"><?php echo esc_html($row['login']); ?></a></td>
                <td><?php echo esc_html((string) $row['count']); ?></td>
                <td>
                    <?php if ($row['locked_until'] > $now) : ?>
                        <?php
                        /* translators: %s: date and time the lock ends. */
                        printf(esc_html__('Locked until %s', 'acemedia-login-block'), esc_html(wp_date($datetime_format, $row['locked_until'])));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Not locked', 'acemedia-login-block'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('acemedia_login_unlock'); ?>
                        <input type="hidden" name="action" value="acemedia_login_unlock" />
                        <input type="hidden" name="unlock_type" value="user" />
                        <input type="hidden" name="unlock_key" value="<?php echo esc_attr((string) $row['id']); ?>" />
                        <button type="submit" class="button button-secondary">
                            <?php echo $row['locked_until'] > $now ? esc_html__('Unlock', 'acemedia-login-block') : esc_html__('Clear count', 'acemedia-login-block'); ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
</table>

<h2><?php esc_html_e('IP addresses and unknown usernames', 'acemedia-login-block'); ?></h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e('Type', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Value', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Failed attempts', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Last attempt', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Status', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Action', 'acemedia-login-block'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($counters)) : ?>
            <tr><td colspan="6"><?php esc_html_e('Nothing is being counted right now.', 'acemedia-login-block'); ?></td></tr>
        <?php else : foreach ($counters as $row) : ?>
            <?php $locked = (int) $row['locked_until'] > $now; ?>
            <tr>
                <td><?php echo esc_html($row['bucket'] === 'ip' ? $lock_labels['ip'] : $lock_labels['name']); ?></td>
                <td><?php echo esc_html($row['label'] ?? ''); ?></td>
                <td><?php echo esc_html((string) (int) $row['count']); ?></td>
                <td><?php echo esc_html(wp_date($datetime_format, (int) $row['last'])); ?></td>
                <td>
                    <?php if ($locked) : ?>
                        <?php
                        /* translators: %s: date and time the lock ends. */
                        printf(esc_html__('Locked until %s', 'acemedia-login-block'), esc_html(wp_date($datetime_format, (int) $row['locked_until'])));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Not locked', 'acemedia-login-block'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('acemedia_login_unlock'); ?>
                        <input type="hidden" name="action" value="acemedia_login_unlock" />
                        <input type="hidden" name="unlock_type" value="<?php echo esc_attr($row['bucket']); ?>" />
                        <input type="hidden" name="unlock_key" value="<?php echo esc_attr($row['key']); ?>" />
                        <button type="submit" class="button button-secondary">
                            <?php echo $locked ? esc_html__('Unlock', 'acemedia-login-block') : esc_html__('Clear count', 'acemedia-login-block'); ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
</table>

<h2><?php esc_html_e('Login attempt log', 'acemedia-login-block'); ?></h2>
<form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" class="acemedia-login-filters" style="margin: 8px 0;">
    <input type="hidden" name="page" value="acemedia-login-block" />
    <input type="hidden" name="tab" value="security" />
    <label class="screen-reader-text" for="acemedia-login-filter-event"><?php esc_html_e('Event', 'acemedia-login-block'); ?></label>
    <select id="acemedia-login-filter-event" name="event">
        <option value=""><?php esc_html_e('All events', 'acemedia-login-block'); ?></option>
        <option value="failed" <?php selected($filters['event'], 'failed'); ?>><?php esc_html_e('Failed', 'acemedia-login-block'); ?></option>
        <option value="blocked" <?php selected($filters['event'], 'blocked'); ?>><?php esc_html_e('Blocked', 'acemedia-login-block'); ?></option>
    </select>
    <label class="screen-reader-text" for="acemedia-login-filter-user"><?php esc_html_e('Username', 'acemedia-login-block'); ?></label>
    <input type="search" id="acemedia-login-filter-user" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Username', 'acemedia-login-block'); ?>" />
    <label class="screen-reader-text" for="acemedia-login-filter-ip"><?php esc_html_e('IP address', 'acemedia-login-block'); ?></label>
    <input type="text" id="acemedia-login-filter-ip" name="ip" value="<?php echo esc_attr($filters['ip']); ?>" placeholder="<?php esc_attr_e('IP address', 'acemedia-login-block'); ?>" />
    <label>
        <input type="checkbox" name="lockout_only" value="1" <?php checked($filters['lockout_only']); ?> />
        <?php esc_html_e('Lockouts only', 'acemedia-login-block'); ?>
    </label>
    <?php submit_button(__('Filter', 'acemedia-login-block'), 'secondary', '', false); ?>
    <a class="button-link" href="<?php echo esc_url(Security_Page::url()); ?>" style="margin-left: 8px;"><?php esc_html_e('Reset', 'acemedia-login-block'); ?></a>
</form>

<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e('Time', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Username', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('IP address', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('User agent', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Event', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Attempts (account / IP)', 'acemedia-login-block'); ?></th>
            <th><?php esc_html_e('Lockout', 'acemedia-login-block'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($result['rows'])) : ?>
            <tr><td colspan="7"><?php esc_html_e('No login attempts match.', 'acemedia-login-block'); ?></td></tr>
        <?php else : foreach ($result['rows'] as $row) : ?>
            <?php
            $lock_types = array_filter(explode(',', (string) $row['lockout']));
            $lock_text = implode(', ', array_map(function ($type) use ($lock_labels) {
                return $lock_labels[$type] ?? $type;
            }, $lock_types));
            ?>
            <tr>
                <td><?php echo esc_html(get_date_from_gmt($row['attempt_time'], $datetime_format)); ?></td>
                <td>
                    <?php echo esc_html($row['username'] !== '' ? $row['username'] : '-'); ?>
                    <?php if (!(int) $row['user_id'] && $row['username'] !== '') : ?>
                        <br /><small><?php esc_html_e('(no such user)', 'acemedia-login-block'); ?></small>
                    <?php endif; ?>
                </td>
                <td><a href="<?php echo esc_url(Security_Page::url(['ip' => $row['ip']])); ?>"><?php echo esc_html($row['ip']); ?></a></td>
                <td><small><?php echo esc_html($row['user_agent']); ?></small></td>
                <td><?php echo $row['event'] === 'blocked' ? esc_html__('Blocked', 'acemedia-login-block') : esc_html__('Failed', 'acemedia-login-block'); ?></td>
                <td><?php echo $row['event'] === 'blocked' ? '-' : esc_html((int) $row['attempt_count'] . ' / ' . (int) $row['ip_count']); ?></td>
                <td>
                    <?php
                    if ($row['event'] === 'blocked') {
                        /* translators: %s: lock type. */
                        echo esc_html(sprintf(__('Already locked (%s)', 'acemedia-login-block'), $lock_text));
                    } elseif ($lock_text !== '') {
                        /* translators: %s: lock type. */
                        echo '<strong>' . esc_html(sprintf(__('Yes (%s)', 'acemedia-login-block'), $lock_text)) . '</strong>';
                    } else {
                        esc_html_e('No', 'acemedia-login-block');
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
</table>

<div class="tablenav bottom">
    <div class="tablenav-pages">
        <span class="displaying-num">
            <?php
            /* translators: %s: number of log entries. */
            printf(esc_html(_n('%s entry', '%s entries', $result['total'], 'acemedia-login-block')), esc_html(number_format_i18n($result['total'])));
            ?>
        </span>
        <?php
        if ($total_pages > 1) {
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $filters['page'],
                'total' => $total_pages,
                'prev_text' => '&lsaquo;',
                'next_text' => '&rsaquo;',
            ]));
        }
        ?>
    </div>
</div>

<p class="description">
    <?php
    /* translators: %d: number of days. */
    printf(esc_html__('Log entries older than %d days are removed automatically each day. Change this on the Settings tab.', 'acemedia-login-block'), (int) $retention_days);
    ?>
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('acemedia_login_purge_log'); ?>
    <input type="hidden" name="action" value="acemedia_login_purge_log" />
    <?php submit_button(__('Remove old entries now', 'acemedia-login-block'), 'secondary', 'submit', false); ?>
</form>
