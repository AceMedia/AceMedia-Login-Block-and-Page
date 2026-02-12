<?php
/**
 * Security Logging Utility
 *
 * @package AceLoginBlock
 */

namespace AceLoginBlock\Utils;

if (!defined('ABSPATH')) {
	exit;
}

class Logging {
	private const META_KEY = '_acemedia_security_logs';
	private const MAX_ENTRIES = 50;

	public static function log_event($user_id, $event, array $data = [], $success = null) {
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return;
		}

		$entry = [
			'time' => current_time('mysql'),
			'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
			'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
			'event' => sanitize_text_field($event),
			'success' => is_bool($success) ? $success : null,
			'data' => $data,
		];

		$logs = get_user_meta($user_id, self::META_KEY, true);
		if (!is_array($logs)) {
			$logs = [];
		}

		array_unshift($logs, $entry);
		update_user_meta($user_id, self::META_KEY, array_slice($logs, 0, self::MAX_ENTRIES));

		self::maybe_send_alert($user_id, $entry);
	}

	private static function maybe_send_alert($user_id, array $entry) {
		$enabled = (bool) get_option('acemedia_security_alerts_enabled', false);
		if (!$enabled) {
			return;
		}

		$recipient = sanitize_email(get_option('acemedia_security_alerts_email', ''));
		if (empty($recipient)) {
			$recipient = get_option('admin_email');
		}

		if (empty($recipient)) {
			return;
		}

		$user = get_user_by('id', (int) $user_id);
		$subject = sprintf(
			__('Security alert: %s', 'acemedia-login-block'),
			$entry['event']
		);

		$lines = [
			sprintf(__('User: %s', 'acemedia-login-block'), $user ? $user->user_login : $user_id),
			sprintf(__('Time: %s', 'acemedia-login-block'), $entry['time']),
			sprintf(__('IP: %s', 'acemedia-login-block'), $entry['ip'] ?: '-'),
			sprintf(__('User Agent: %s', 'acemedia-login-block'), $entry['user_agent'] ?: '-'),
		];

		if (!empty($entry['data'])) {
			$lines[] = sprintf(__('Details: %s', 'acemedia-login-block'), wp_json_encode($entry['data']));
		}

		wp_mail($recipient, $subject, implode("\n", $lines));
	}
}
