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
	if (!function_exists('is_wp_error')) { function is_wp_error($value) { return $value instanceof \\WP_Error; } }
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
namespace DropKey\\V2 {
	final class Logger {
		public static function info($category,$event,$message,$context=array()) {}
	}
}
namespace {
	require_once dirname(__DIR__).'/includes/class-commerce-service.php';
	function check_checkout_case($condition,$message) {
		if (!$condition) { fwrite(STDERR,"FAIL: ".$message."\\n"); exit(1); }
		echo "PASS: ".$message."\\n";
	}
	$service=new \\DropKey\\V2\\Commerce_Service();
	$GLOBALS['dk2_test_logged_in']=false;
	$GLOBALS['dk2_test_user']=(object)array('ID'=>0,'user_email'=>'');
	$guest=$service->start_checkout(1,'buyer@example.test','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($guest instanceof \\WP_Error&&'dk2_checkout_account_required'===$guest->get_error_code(),'rejects checkout without an authenticated customer account');
	$invalid_email=$service->start_checkout(1,'not-an-email','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($invalid_email instanceof \\WP_Error&&'dk2_checkout_email_invalid'===$invalid_email->get_error_code(),'rejects malformed checkout email addresses');
	$GLOBALS['dk2_test_logged_in']=true;
	$GLOBALS['dk2_test_user']=(object)array('ID'=>42,'user_email'=>'owner@example.test');
	$mismatch=$service->start_checkout(1,'buyer@example.test','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($mismatch instanceof \\WP_Error&&'dk2_checkout_account_email_mismatch'===$mismatch->get_error_code(),'rejects checkout using an email different from the signed-in account');
	$GLOBALS['dk2_test_user']=(object)array('ID'=>0,'user_email'=>'owner@example.test');
	$missing_id=$service->start_checkout(1,'owner@example.test','stripe','https://example.test/success','https://example.test/cancel');
	check_checkout_case($missing_id instanceof \\WP_Error&&'dk2_checkout_account_email_mismatch'===$missing_id->get_error_code(),'rejects an authenticated session without a valid customer user ID');
	echo "All customer checkout contract tests passed.\\n";
}
