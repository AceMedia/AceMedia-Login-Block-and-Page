<?php
/** Isolated regression checks; these do not replace WordPress/browser tests. */
define('ABSPATH', __DIR__);
class WP_Error { public function __construct(public $code = '', public $message = '') {} public function get_error_message() {return $this->message;} }
class WP_User { public $ID = 7; public $roles = ['subscriber']; public $user_login = 'poet'; public $user_email = 'poet@example.test'; }
$GLOBALS['hooks'] = []; $GLOBALS['options'] = []; $GLOBALS['meta'] = []; $GLOBALS['transients'] = [];
function add_filter($name, $callback, $priority = 10, $args = 1) {$GLOBALS['hooks'][$name][$priority][] = $callback;}
function add_action($name, $callback, $priority = 10, $args = 1) {add_filter($name, $callback, $priority, $args);}
function __($s, $domain = '') {return $s;}
function is_wp_error($v) {return $v instanceof WP_Error;}
function get_option($key, $default = false) {return $GLOBALS['options'][$key] ?? $default;}
function get_user_meta($id, $key, $single = false) {return $GLOBALS['meta'][$key] ?? '';}
function wp_validate_redirect($url, $fallback) {return str_starts_with($url, 'https://example.test/') ? $url : $fallback;}
function home_url() {return 'https://example.test/';}
function sanitize_key($s) {return $s;}
function wp_unslash($s) {return $s;}
function get_post($id) {return $GLOBALS['page'] ?? null;}
function sanitize_text_field($s) {return $s;}
function get_transient($key) {return $GLOBALS['transients'][$key] ?? false;}
function delete_transient($key) {unset($GLOBALS['transients'][$key]);}
function get_user_by($key, $value) {return new WP_User;}
function is_email($s) {return strpos($s, '@') !== false;}
function apply_filters($name, $value, ...$args) { $hooks = $GLOBALS['hooks'][$name] ?? []; ksort($hooks); foreach ($hooks as $callbacks) {foreach ($callbacks as $callback) {$value = $callback($value, ...$args);}} return $value; }
function check($ok, $label) {if (!$ok) {throw new RuntimeException($label);} echo "PASS $label\n";}
$root = dirname(__DIR__);
if (in_array($argv[1] ?? '', ['guard', 'missing-vendor', 'missing-sodium', 'actual-guard'], true)) {
    $source = file_get_contents($root . '/acemedia-login-block.php');
    $source = substr($source, 5, strpos($source, '// Define plugin constants') - 5);
    if ($argv[1] === 'guard') {
        $source = str_replace('PHP_VERSION_ID >= 80200', '80100 >= 80200', $source);
    } elseif ($argv[1] === 'missing-sodium') {
        $source = str_replace("function_exists('sodium_crypto_secretbox')", 'false', $source);
    } elseif ($argv[1] === 'missing-vendor') {
        $source = str_replace("is_readable(__DIR__ . '/vendor/autoload.php')", 'false', $source);
    }
    eval($source);
    check(isset($GLOBALS['hooks']['authenticate'][PHP_INT_MAX]), 'unsupported runtime installs fail-closed authentication');
    check(apply_filters('authenticate', new WP_User)->code === 'acemedia_auth_unavailable', 'unsupported runtime cannot silently bypass 2FA');
    check(!class_exists('AceLoginBlock\\Auth\\Two_Factor'), 'unsupported runtime does not load incompatible classes');
    exit;
}
require $root . '/includes/auth/class-authentication.php';
require $root . '/includes/auth/class-login-handler.php';
require $root . '/includes/auth/class-passkeys.php';
check(!isset($GLOBALS['hooks']['wp_login']), 'wp_login actions remain non-terminating');
check(!isset($GLOBALS['hooks']['wp_logout']), 'wp_logout actions remain non-terminating');
$auth = new AceLoginBlock\Auth\Authentication;
$user = new WP_User;
check($auth->login_redirect('https://example.test/account', '', $user) === 'https://example.test/account', 'default login destination preserved');
$GLOBALS['options']['acemedia_login_block_redirect_subscriber'] = 'https://evil.test/';
check($auth->login_redirect('https://example.test/account', '', $user) === 'https://example.test/account', 'unsafe role redirect rejected');
$GLOBALS['options']['acemedia_login_block_redirect_subscriber'] = 'https://example.test/library';
check($auth->login_redirect('https://example.test/account', '', $user) === 'https://example.test/library', 'safe role redirect preserved');
$handler = new AceLoginBlock\Auth\Login_Handler;
foreach (['rp', 'resetpass', 'lostpassword', 'logout', 'register', 'enter_recovery_mode'] as $action) {
    $_REQUEST = ['action' => $action]; $handler->load_custom_page_template();
    check(true, 'core action returns: ' . $action);
}
$_REQUEST = []; $handler->load_custom_page_template(); check(true, 'missing page falls back to core');
$keys = new AceLoginBlock\Auth\Passkeys;
$error = new WP_Error('account_blocked');
check($keys->authenticate_with_passkey_token($error, 'poet', '') === $error, 'passkey filter preserves earlier account restrictions');
check(!AceLoginBlock\Auth\Passkeys::is_verified_passwordless_user(7), 'passwordless marker unavailable outside verified request');
$GLOBALS['meta']['_acemedia_2fa_enabled'] = 1;
$method = new ReflectionMethod($keys, 'user_requires_2fa');
check($method->invoke($keys, $user), 'personal 2FA constrains direct passwordless policy');
$GLOBALS['transients']['acemedia_passkey_pwd_fixture'] = ['user_id' => 7];
check(AceLoginBlock\Auth\Passkeys::inspect_password_token('fixture', 'poet') instanceof WP_User, 'passkey factor preview resolves matching user');
check(isset($GLOBALS['transients']['acemedia_passkey_pwd_fixture']), 'factor preview does not consume final-login token');
check(AceLoginBlock\Auth\Passkeys::inspect_password_token('fixture', 'other') === false, 'factor preview rejects mismatched account');
check(AceLoginBlock\Auth\Passkeys::inspect_password_token('expired', 'poet') === false, 'factor preview rejects expired token');
