<?php
function ace_expect($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
if (!defined('WP_CLI') || !WP_CLI || getenv('ACEMEDIA_TEST_DISPOSABLE') !== '1' || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Run only with WP-CLI on a disposable localhost site and ACEMEDIA_TEST_DISPOSABLE=1.');
}
$name='ace-auth-' . strtolower(wp_generate_password(10, false, false));
$id=wp_create_user($name, wp_generate_password(32), $name . '@example.invalid');
if (is_wp_error($id)) { throw new RuntimeException('Could not create fixture account.'); }
$user=get_userdata($id);
try {
delete_user_meta($user->ID,'_acemedia_login_failed_attempts');delete_user_meta($user->ID,'_acemedia_login_locked_until');
$password=wp_generate_password(32);wp_set_password($password,$user->ID);
update_user_meta($user->ID,'_acemedia_2fa_enabled',1);update_user_meta($user->ID,'_acemedia_2fa_method','email');
$t=\AceLoginBlock\Auth\Two_Factor::get_instance();
$sign=function($code)use($user,$password){$_POST=['2fa_code'=>$code];$result=wp_authenticate($user->user_login,$password);if($result instanceof WP_User)do_action('wp_login',$user->user_login,$result);return $result;};
delete_user_meta($user->ID,'_acemedia_2fa_attempts');
update_user_meta($user->ID,'_acemedia_2fa_code','FIXTURE');update_user_meta($user->ID,'_acemedia_2fa_code_time',time());
ace_expect($sign('FIXTURE') instanceof WP_User,'Real WP email code completes authenticate chain');
ace_expect(is_wp_error($sign('FIXTURE')),'Real WP email replay rejected');
update_user_meta($user->ID,'_acemedia_2fa_backup_codes',[['hash'=>wp_hash_password('BACKUP-FIXTURE')]]);
ace_expect($sign('BACKUP-FIXTURE') instanceof WP_User,'Real WP backup code completes authenticate chain');
ace_expect(is_wp_error($sign('BACKUP-FIXTURE')),'Real WP backup replay rejected');
delete_user_meta($user->ID,'_acemedia_2fa_attempts');
for($i=0;$i<8;$i++){update_user_meta($user->ID,'_acemedia_2fa_code','VALID-'.$i);update_user_meta($user->ID,'_acemedia_2fa_code_time',time());ace_expect($sign('VALID-'.$i) instanceof WP_User,'Successful logins do not exhaust failure budget');}
for($i=0;$i<5;$i++)$sign('WRONG');
$attempts=get_user_meta($user->ID,'_acemedia_2fa_attempts',true);
ace_expect($attempts['count']===5,'Failed attempts counted in shared user metadata');
update_user_meta($user->ID,'_acemedia_2fa_code','AFTER-BUDGET');update_user_meta($user->ID,'_acemedia_2fa_code_time',time());
ace_expect(is_wp_error($sign('AFTER-BUDGET')),'Exhausted failure budget rejects even valid code');
delete_user_meta($user->ID,'_acemedia_login_failed_attempts');delete_user_meta($user->ID,'_acemedia_login_locked_until');
$attempts['expires']=time()-1;update_user_meta($user->ID,'_acemedia_2fa_attempts',$attempts);
ace_expect($sign('AFTER-BUDGET') instanceof WP_User,'Expired fixed-window budget recovers');
$secret=\OTPHP\TOTP::create()->getSecret();update_user_meta($user->ID,'_acemedia_2fa_secret',acemedia_login_block_encrypt($secret));update_user_meta($user->ID,'_acemedia_2fa_method','auth_app');
$code=\OTPHP\TOTP::create($secret)->now();
ace_expect($sign($code) instanceof WP_User,'Real TOTP succeeds using installed library');
ace_expect(is_wp_error($sign($code)),'Real TOTP replay rejected');
$recent=get_user_meta($user->ID,'_acemedia_2fa_recent_totp',true);$recent[hash('sha256','OTHER-CODE')]=time()+90;update_user_meta($user->ID,'_acemedia_2fa_recent_totp',$recent);
ace_expect(is_wp_error($sign($code)),'Previously used TOTP still rejected after another recorded code');
$_POST=[];
echo "Real WordPress runtime checks complete.\n";

} finally {
    $_POST=[];
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user->ID);
}
