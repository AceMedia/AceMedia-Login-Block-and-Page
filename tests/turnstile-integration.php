<?php
use AceLoginBlock\Auth\Turnstile;
if (!defined('WP_CLI') || !WP_CLI || !defined('WP_LOCAL_DEV') || !WP_LOCAL_DEV) throw new RuntimeException('Local development only.');
if (!defined('ACEMEDIA_LOGIN_BLOCK_PATH')) define('ACEMEDIA_LOGIN_BLOCK_PATH',dirname(__DIR__) . '/');
if (!defined('ACEMEDIA_LOGIN_BLOCK_URL')) define('ACEMEDIA_LOGIN_BLOCK_URL',plugins_url('acemedia-login-block/'));
require_once ACEMEDIA_LOGIN_BLOCK_PATH.'includes/auth/class-turnstile.php';
$old=get_option(Turnstile::OPTION,null);$net=get_site_option(Turnstile::OPTION,null);$post=$_POST;$server=$_SERVER;$used=[];$checks=0;$receipts=[];
$mock=static function($pre,$args,$url)use(&$used){if(!str_contains($url,'challenges.cloudflare.com/turnstile/v0/siteverify'))return $pre;$token=$args['body']['response'];$step=explode(':',$token)[0];$success=!isset($used[$token])&&!str_contains($token,'bad');$used[$token]=true;return ['response'=>['code'=>200],'body'=>wp_json_encode(['success'=>$success,'hostname'=>str_contains($token,'wrong-host')?'wrong.example':wp_parse_url(home_url(),PHP_URL_HOST),'action'=>str_contains($token,'wrong-action')?'other':'ace_'.$step]),'headers'=>[]];};
$assert=static function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";++$checks;};
$cold=static function(){foreach(['verified'=>[],'rest_login'=>null,'rest_passkey'=>'']as$n=>$v){$p=new ReflectionProperty(Turnstile::class,$n);$p->setValue(null,$v);}};
add_filter('pre_http_request',$mock,99,3);
try{
 update_site_option(Turnstile::OPTION,[]);update_option(Turnstile::OPTION,['site_key'=>'unit-site','secret'=>'unit-secret','steps'=>Turnstile::STEPS],false);
 $assert(is_wp_error(Turnstile::verify('login','')),'missing token is blocked');
 $assert(is_wp_error(Turnstile::verify('login','login:wrong-host')),'another hostname is blocked');
 $assert(is_wp_error(Turnstile::verify('login','login:wrong-action')),'another action is blocked');
 $assert(Turnstile::verify('login','login:good')===true,'valid hostname and action accepted');
 $assert(Turnstile::verify('login','login:good')===true,'one request does not redeem a token twice');
 $cold();$assert(is_wp_error(Turnstile::verify('login','login:good')),'a later request cannot replay a consumed token');
 $user=new WP_User();$user->ID=991234;$user->roles=['subscriber'];
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['log'=>'fixture','pwd'=>'password'];
 $assert(is_wp_error(Turnstile::authenticate($user,'fixture','password')),'direct browser login cannot skip verification');
 $_POST=[];$assert(Turnstile::authenticate($user,'fixture','password')===$user,'programmatic authentication remains available');
 $_POST=['register'=>'1'];$delegated=class_exists('ARG_Turnstile')&&ARG_Turnstile::enabled();$assert(Turnstile::woo_registration(new WP_Error())->has_errors()!==$delegated,$delegated?'WooCommerce registration delegates to Registration Guard':'WooCommerce registration requires verification');
 $_POST=[];$assert(!Turnstile::woo_registration(new WP_Error())->has_errors(),'checkout and API customer creation are not challenged');
 $_SERVER['REQUEST_METHOD']='GET';$assert(!Turnstile::recovery_errors(new WP_Error())->has_errors(),'programmatic recovery remains available');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['user_login'=>'fixture'];$assert(Turnstile::recovery_errors(new WP_Error())->has_errors(),'browser recovery requires verification');
 $cold();$_POST=[];$req=new WP_REST_Request('POST','/acemedia/v1/check-2fa');$req->set_param('username','fixture');$req->set_param('password','password');$req->set_param('ace_turnstile_login','login:preflight');
 $assert(Turnstile::rest_guard(null,null,$req)===null,'valid verification allows password preflight');
 $assert(Turnstile::authenticate($user,'fixture','password')===$user,'verified preflight reaches password authentication');
 $res=Turnstile::rest_receipt(new WP_REST_Response(['is2FAEnabled'=>false]),null,$req);$receipt=$res->get_data()['turnstileReceipt']??'';$receipts[]=$receipt;
 $assert(strlen($receipt)>=40,'preflight returns an opaque receipt');
 $cold();$_POST=['log'=>'other','pwd'=>'password','ace_turnstile_receipt'=>$receipt];$assert(is_wp_error(Turnstile::authenticate($user,'other','password')),'receipt cannot authenticate different credentials');
 $cold();$_POST=['log'=>'fixture','pwd'=>'password','ace_turnstile_receipt'=>$receipt];$assert(Turnstile::authenticate($user,'fixture','password')===$user,'matching final login uses receipt without redeeming token again');
 $cold();$assert(is_wp_error(Turnstile::authenticate($user,'fixture','password')),'receipt is single use');
 $cold();$_POST=['log'=>'fixture','ace_turnstile_two_factor'=>'two_factor:good'];$assert(Turnstile::second_factor($user)===$user,'second-factor verification accepts its own action');
 $cold();$_POST=['log'=>'fixture','ace_turnstile_two_factor'=>'login:wrong-factor'];$assert(is_wp_error(Turnstile::second_factor($user)),'login token cannot bypass second-factor policy');
 $cold();$_POST=[];$spaced=new WP_REST_Request('POST','/acemedia/v1/check-2fa');$spaced->set_param('username',' fixture ');$spaced->set_param('password',' password ');$spaced->set_param('ace_turnstile_login','login:normalised');
 $assert(Turnstile::rest_guard(null,null,$spaced)===null,'padded credentials can complete verification');
 $assert(Turnstile::authenticate($user,'fixture','password')===$user,'receipt context follows WordPress credential normalisation');
 update_site_option(Turnstile::OPTION,['site_key'=>'network-site','secret'=>'network-secret','steps'=>['login'],'enforce'=>true]);update_option(Turnstile::OPTION,['steps'=>[]],false);$assert(Turnstile::enabled('login'),'network policy overrides local opt-out');
 $assert(!Turnstile::enabled('password_reset'),'unselected steps remain optional');
 echo "Verified $checks checks. No accounts or emails created.\n";
}finally{
 remove_filter('pre_http_request',$mock,99);$old===null?delete_option(Turnstile::OPTION):update_option(Turnstile::OPTION,$old,false);$net===null?delete_site_option(Turnstile::OPTION):update_site_option(Turnstile::OPTION,$net);foreach($receipts as$r)delete_transient('ace_login_ts_'.hash('sha256',$r));$_POST=$post;$_SERVER=$server;
}
