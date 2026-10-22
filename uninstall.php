<?php
/**
 * Uninstall: remove the login attempt log, counters and lockout data.
 *
 * @package AceLoginBlock
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}acemedia_login_attempts"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ([
    'acemedia_login_attempts_db_version',
    'acemedia_login_lockout_store',
    'acemedia_login_lockout_ip_max_attempts',
    'acemedia_login_lockout_notify',
    'acemedia_login_log_retention_days',
    'acemedia_login_trusted_proxy_header',
] as $option) {
    delete_option($option);
}

delete_transient('acemedia_login_lockout_mailed');
wp_clear_scheduled_hook('acemedia_login_attempts_cleanup');

foreach ([
    '_acemedia_login_failed_attempts',
    '_acemedia_login_locked_until',
    '_acemedia_login_trusted_ips',
] as $meta_key) {
    delete_metadata('user', 0, $meta_key, '', true);
}
