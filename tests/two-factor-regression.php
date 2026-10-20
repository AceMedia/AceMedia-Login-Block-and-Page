<?php
/** Isolated regression tests, invented accounts and credentials; no network/database. */
namespace AceLoginBlock\Utils { class Logging { public static function log_event(...$args) {} } }
namespace AceLoginBlock\Auth { class Passkeys { public static function is_verified_passwordless_user($id) { return false; } public static function inspect_password_token($token,$username) { return $token === 'valid-fixture-token' ? $GLOBALS['user'] : false; } } }
namespace {
define('COOKIEHASH', 'fixture'); define('ABSPATH', __DIR__); define('DAY_IN_SECONDS',86400); define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60);
function add_action(...$a) {}
function add_filter($name,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][$name][$priority][]=$callback;}
function remove_filter($name,$callback,$priority=10){foreach($GLOBALS['hooks'][$name][$priority]??[] as $i=>$registered){if($registered===$callback)unset($GLOBALS['hooks'][$name][$priority][$i]);}}
 function __($s,...$a){return $s;}
function sanitize_text_field($s){return trim((string)$s);} function wp_unslash($s){return $s;}
function get_option($k,$default=false){return $GLOBALS['opts'][$k]??$default;}
function add_option($k,$v,...$a){if(isset($GLOBALS['opts'][$k]))return false;$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);}
function get_user_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??'';}
function update_user_meta($id,$k,$v,$previous=null){if($previous!==null && get_user_meta($id,$k)!==$previous)return false;$GLOBALS['meta'][$id][$k]=$v;return true;}
function delete_user_meta($id,$k,$v=null){if(!isset($GLOBALS['meta'][$id][$k]) || ($v!==null && $GLOBALS['meta'][$id][$k]!==$v))return false;unset($GLOBALS['meta'][$id][$k]);return true;}
function get_user_by($kind,$value){return $value==='fixture' ? $GLOBALS['user'] : false;}
function get_userdata($id){return $GLOBALS['user'];}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,...$a){$GLOBALS['transients'][$k]=$v;}
function apply_filters($k,$v,...$args){$hooks=$GLOBALS['hooks'][$k]??[];ksort($hooks);foreach($hooks as $callbacks)foreach($callbacks as $callback)$v=$callback($v,...$args);return $v;}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_check_password($a,$b){return password_verify($a,$b);}
function wp_authenticate($u,$p){return apply_filters('authenticate',null,$u,$p);}
function is_email($v){return true;} function wp_generate_password(...$a){return 'MAILED';} function wp_mail(...$a){$GLOBALS['mail_count']++;return true;}
function acemedia_login_block_decrypt($v){return false;}
function current_user_can(...$args){return true;}
function check_ajax_referer(...$args){} function get_current_user_id(){return 1;}
function wp_send_json_error($value,...$args){throw new RuntimeException('json-error');}
function wp_send_json_success($value,...$args){throw new RuntimeException('json-success');}
class WP_User {public $ID=1;public $roles=['subscriber'];public $user_email='fiction@example.invalid';}
class WP_Error {public $code;public $errors=[]; function __construct($c='',...$a){$this->code=$c;} function add($code,$message){$this->errors[$code]=$message;} function has_errors(){return !empty($this->errors);}}
class WP_REST_Request {private $p=[];function __construct(...$a){} function set_param($k,$v){$this->p[$k]=$v;} function get_param($k){return $this->p[$k]??null;}}
function expect($ok,$label){if(!$ok)throw new \Exception($label);echo "PASS: $label\n";}
class Fixture_DB {
    public $base_prefix='wp_'; public $locks=[];
    public function prepare($sql,...$args){if(strpos($sql,'%i')!==false)array_shift($args);return [$sql,$args];}
    public function query($query){[$sql,$args]=$query;[$key,$value]=$args;if(strpos($sql,'INSERT')===0){if(isset($this->locks[$key]))return 0;$this->locks[$key]=$value;return 1;}if(($this->locks[$key]??null)===$value){unset($this->locks[$key]);return 1;}return 0;}
    public function get_var($query){return $this->locks[$query[1][0]]??null;}
}
$GLOBALS['wpdb']=new Fixture_DB();
$GLOBALS['user']=new WP_User();$GLOBALS['mail_count']=0;
add_filter('authenticate', static function($candidate,$username,$password){if($candidate instanceof WP_User || $candidate instanceof WP_Error)return $candidate;return $password==='fixture-password' ? $GLOBALS['user'] : new WP_Error('invalid');},20,3);
require dirname(__DIR__).'/includes/auth/class-two-factor.php';
$t=\AceLoginBlock\Auth\Two_Factor::get_instance();
$GLOBALS['opts']=[];$GLOBALS['meta'][1]=['_acemedia_2fa_enabled'=>1,'_acemedia_2fa_method'=>'email','_acemedia_2fa_code'=>'FIXTURE','_acemedia_2fa_code_time'=>time()];
$r=new WP_REST_Request();$r->set_param('username','fixture');$r->set_param('code','FIXTURE');
for($i=0;$i<6;$i++)expect(is_wp_error($t->verify_code($r)),'Public verification rejected');
expect(empty($GLOBALS['transients']) && !get_user_meta(1, '_acemedia_2fa_attempts'), 'Anonymous calls cannot charge account attempt budget');
expect(get_user_meta(1,'_acemedia_2fa_code')==='FIXTURE','Anonymous calls cannot consume code');
$_POST=['2fa_code'=>'FIXTURE'];expect($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')===$GLOBALS['user'],'Personal opt-in accepts one valid email code');
expect(is_wp_error($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')),'Email replay rejected');
$GLOBALS['meta'][1]['_acemedia_2fa_backup_codes']=[['hash'=>password_hash('BACKUP-FIXTURE',PASSWORD_DEFAULT)]];
$_POST=['2fa_code'=>'BACKUP-FIXTURE'];expect($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')===$GLOBALS['user'],'Backup code succeeds at final authentication');
expect(is_wp_error($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')),'Backup replay rejected');
$_POST=[];expect(is_wp_error($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')),'Personal opt-in requires a second factor');
$r->set_param('password','bad');expect(is_wp_error($t->check_2fa_status($r)),'Factor details require valid password');
$r->set_param('password','fixture-password');expect($t->check_2fa_status($r)['requires2FA']===true,'Password-verified status honours opt-in');
expect($GLOBALS['mail_count']===1,'Email delivery throttled across repeated status checks');
$r->set_param('password','');$r->set_param('passwordToken','valid-fixture-token');
expect($t->check_2fa_status($r)['requires2FA']===true,'Passkey password-factor token supports status without password');
$blocker=static function($user){return new WP_Error('external_policy');};add_filter('authenticate',$blocker,100,1);
expect(is_wp_error($t->check_2fa_status($r)),'Passkey status retains later third-party restrictions');remove_filter('authenticate',$blocker,100);
$r->set_param('passwordToken','invalid');expect(is_wp_error($t->check_2fa_status($r)),'Invalid password-factor token rejected');
$r->set_param('passwordToken','');$r->set_param('password','fixture-password');
expect(is_wp_error(wp_authenticate('fixture','fixture-password')),'Second-factor filter restored after status request');
$GLOBALS['meta'][1]['_acemedia_2fa_secret']='unreadable-fixture';expect($t::generate_qr_code(1)==='','Unreadable secret displays no QR');
expect(get_user_meta(1,'_acemedia_2fa_secret')==='unreadable-fixture','Unreadable enrolment preserved');
expect($t->verify_qr_code('invalid','123456')===false,'TOTP dependency failure caught safely');
$GLOBALS['opts']['acemedia_2fa_enabled_subscriber']=true;$GLOBALS['meta'][1]['_acemedia_2fa_enabled']=0;
expect(is_wp_error($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')),'Role mandate survives personal opt-out');
$GLOBALS['opts']=[];
$_POST=['acemedia_2fa_enabled'=>1,'acemedia_2fa_method'=>'auth_app','acemedia_2fa_setup_code'=>'WRONG'];
$before=$GLOBALS['meta'];
try {$t->handle_setup();expect(false,'Setup must reject unproven TOTP');} catch (RuntimeException $e) {expect($e->getMessage()==='json-error','Setup rejects unproven TOTP');}
expect($GLOBALS['meta']===$before,'Failed enrolment leaves account configuration unchanged');
try {$t->prepare_enrolment();expect(false,'Unreadable enrolment must require recovery');} catch (RuntimeException $e) {expect($e->getMessage()==='json-error','Explicit preparation preserves unreadable secret');}
$GLOBALS['wpdb']->locks['acemedia_2fa_lock_1']=time().':other-request';
$_POST=['2fa_code'=>'ANY'];$GLOBALS['meta'][1]['_acemedia_2fa_enabled']=1;
expect(is_wp_error($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')),'Concurrent factor verification fails closed');
expect(isset($GLOBALS['wpdb']->locks['acemedia_2fa_lock_1']),'Contending request cannot release owner lock');
require dirname(__DIR__).'/includes/admin/class-user-profile.php';
$_POST=['acemedia_2fa_settings_present'=>1,'acemedia_2fa_enabled'=>1,'acemedia_2fa_method'=>'auth_app'];
$profile_errors=new WP_Error();
(new \AceLoginBlock\Admin\User_Profile())->validate_2fa_fields($profile_errors,true,(object)['ID'=>1]);
expect(isset($profile_errors->errors['acemedia_setup_code_required']),'Core stdClass profile payload reports invalid enrolment visibly');
$GLOBALS['wpdb']->locks=[];$GLOBALS['opts']=[];$GLOBALS['meta'][1]['_acemedia_2fa_method']='email';
for($i=0;$i<8;$i++){
    $GLOBALS['meta'][1]['_acemedia_2fa_code']='VALID-'.$i;$GLOBALS['meta'][1]['_acemedia_2fa_code_time']=time();
    $_POST=['2fa_code'=>'VALID-'.$i];expect($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')===$GLOBALS['user'],'Repeated successful login does not exhaust failure budget');
}
for($i=0;$i<5;$i++){$_POST=['2fa_code'=>'INVALID'];$t->validate_2fa($GLOBALS['user'],'fixture','fixture-password');}
$budget=get_user_meta(1,'_acemedia_2fa_attempts');expect($budget['count']===5,'Failure budget lives in shared account metadata');
$GLOBALS['meta'][1]['_acemedia_2fa_code']='RECOVER';$GLOBALS['meta'][1]['_acemedia_2fa_code_time']=time();$_POST=['2fa_code'=>'RECOVER'];
expect(is_wp_error($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')),'Exhausted budget rejects correct code');
$GLOBALS['meta'][1]['_acemedia_2fa_attempts']['expires']=time()-1;
expect($t->validate_2fa($GLOBALS['user'],'fixture','fixture-password')===$GLOBALS['user'],'Expired fixed-window budget recovers');
echo "All isolated 2FA regressions passed.\n";
}
