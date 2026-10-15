<?php
/**
 * Network-free tests for the authenticated customer checkout requirement.
 * Run: php tests/customer-checkout-contract.php
 */
namespace {
	if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
	if (!function_exists('__')) { function __($text,$domain=null) { return $text; } }
	if (!function_exists('sanitize_email')) { function sanitize_email($value) { return filter_var((string)$value,FILTER_SANITIZE_EMAIL); } }
	if (!function_exists('sanitize_key')) { function sanitize_key($value) { return preg_replace('/[^a-z0-9_\\-]/','',strtolower((string)$value)); } }
	if (!function_exists('is_email')) { function is_email($value) { return false!==filter_var((string)$value,FILTER_VALIDATE_EMAIL); } }
	if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return !empty($GLOBALS['dk2_test_logged_in']); } }
	if (!function_exists('wp_get_current_user')) { function wp_get_current_user() { return isset($GLOBALS['dk2_test_user'])?$GLOBALS['dk2_test_user']:(object)array('ID'=>0,'user_email'=>''); } }
	if (!function_exists('absint')) { function absint($value) { return abs((int)$value); } }
	if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS',60);
	if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS',3600);
	if (!function_exists('wp_unslash')) { function wp_unslash($value) { return is_array($value)?array_map('wp_unslash',$value):stripslashes((string)$value); } }
	if (!function_exists('sanitize_text_field')) { function sanitize_text_field($value) { return trim(strip_tags((string)$value)); } }
	if (!function_exists('get_transient')) { function get_transient($key) { return isset($GLOBALS['dk2_test_transients'][$key])?$GLOBALS['dk2_test_transients'][$key]:false; } }
	if (!function_exists('set_transient')) { function set_transient($key,$value,$expiration=0) { $GLOBALS['dk2_test_transients'][$key]=$value; return true; } }

	if (!function_exists('is_wp_error')) { function is_wp_error($value) { return $value instanceof \WP_Error; } }
	if (!class_exists('WP_Error')) { class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct($code,$message='',$data=array()) { $this->code=$code; $this->message=$message; $this->data=$data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	}
}
namespace DropKey\V2 {
	final class Logger {
		public static function info($category,$event,$message,$context=array()) {}
	}
}
namespace {
	require_once dirname(__DIR__).'/includes/class-commerce-service.php';
	require_once dirname(__DIR__).'/includes/class-storefront.php';
	function check_checkout_case($condition,$message) {
		if (!$condition) { fwrite(STDERR,"FAIL: ".$message."\n"); exit(1); }
		echo "PASS: ".$message."\n";
	}
	$service=new \DropKey\V2\Commerce_Service();
	$GLOBALS['dk2_test_logged_in']=false;
	$GLOBALS['dk2_test_user']=(object)array('ID'=>0,'user_email'=>'');
	$guest=$service->start_checkout(1,'buyer@example.test','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($guest instanceof \WP_Error&&'dk2_checkout_account_required'===$guest->get_error_code(),'rejects checkout without an authenticated customer account');
	$invalid_email=$service->start_checkout(1,'not-an-email','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($invalid_email instanceof \WP_Error&&'dk2_checkout_email_invalid'===$invalid_email->get_error_code(),'rejects malformed checkout email addresses');
	$GLOBALS['dk2_test_logged_in']=true;
	$GLOBALS['dk2_test_user']=(object)array('ID'=>42,'user_email'=>'owner@example.test');
	$mismatch=$service->start_checkout(1,'buyer@example.test','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($mismatch instanceof \WP_Error&&'dk2_checkout_account_email_mismatch'===$mismatch->get_error_code(),'rejects checkout using an email different from the signed-in account');
	$GLOBALS['dk2_test_user']=(object)array('ID'=>0,'user_email'=>'owner@example.test');
	$missing_id=$service->start_checkout(1,'owner@example.test','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($missing_id instanceof \WP_Error&&'dk2_checkout_account_email_mismatch'===$missing_id->get_error_code(),'rejects an authenticated session without a valid customer user ID');
	$storefront=new \\DropKey\\V2\\Storefront();
	$registration_limiter=new \\ReflectionMethod('DropKey'.chr(92).'V2'.chr(92).'Storefront','rate_limit_registration');
	$registration_limiter->setAccessible(true);
	$GLOBALS['dk2_test_transients']=array();
	$_SERVER['REMOTE_ADDR']='192.0.2.10';
	for($i=1;$i<=5;$i++) check_checkout_case(true===$registration_limiter->invoke($storefront,'throttle@example.test'),'allows account-registration attempt '.$i.' within the per-email limit');
	check_checkout_case(false===$registration_limiter->invoke($storefront,'throttle@example.test'),'throttles the sixth account-registration attempt for one email');
	$GLOBALS['dk2_test_transients']=array();
	for($i=1;$i<=10;$i++) check_checkout_case(true===$registration_limiter->invoke($storefront,'buyer'.$i.'@example.test'),'allows account-registration attempt '.$i.' within the per-IP limit');
	check_checkout_case(false===$registration_limiter->invoke($storefront,'buyer11@example.test'),'throttles the eleventh account-registration attempt from one IP');
	echo "All customer checkout contract tests passed.\n";
}
