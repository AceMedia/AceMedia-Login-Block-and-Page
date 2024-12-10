<?php
namespace AceLoginBlock\Auth;

if (!defined('ABSPATH')) {
    exit;
}

class Backup_Codes {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('wp_ajax_get_or_generate_backup_codes', [$this, 'handle_backup_codes']);
    }

    /**
     * Handle the AJAX request for generating backup codes
     */
    public function handle_backup_codes() {
        check_ajax_referer('get_or_generate_backup_codes');

        $user_id = get_current_user_id();
        if (!$user_id || !current_user_can('edit_user', $user_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'acemedia-login-block')]);
            return;
        }

        $force_new = isset($_POST['force_new']) && $_POST['force_new'] === 'true';
        $backup_codes = get_user_meta($user_id, '_acemedia_2fa_backup_codes', true);

        if ($force_new || !is_array($backup_codes) || empty($backup_codes)) {
            // Generate new codes with high entropy
            $codes = [];
            for ($i = 0; $i < 10; $i++) {
                $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 characters of high entropy
            }

            // Store codes with metadata
            $hashed_codes = array_map(function($code) {
                return [
                    'hash' => wp_hash_password($code),
                    'created' => time(),
                    'used' => false
                ];
            }, $codes);

            update_user_meta($user_id, '_acemedia_2fa_backup_codes', $hashed_codes);

            wp_send_json_success(['codes' => $codes]);
        } else {
            // Return placeholder codes for existing backup codes
            $codes = array_map(function($code_data) {
                return sprintf('BACKUP-%s', substr(md5($code_data['hash']), 0, 4));
            }, $backup_codes);

            wp_send_json_success(['codes' => $codes]);
        }
    }

    /**
     * Helper function for base32 encoding
     */
    public static function base32_encode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $binary = str_pad($binary, ceil(strlen($binary) / 40) * 40, '0');
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $result .= $alphabet[bindec($chunk)];
        }
        return $result;
    }
}

// Initialize the class
new Backup_Codes();