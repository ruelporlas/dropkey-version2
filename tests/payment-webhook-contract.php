<?php
/**
 * Lightweight, network-free contract tests for signed Stripe webhook handling.
 * Run: php tests/payment-webhook-contract.php
 */
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
$GLOBALS['dk2_test_options']=array('dk2_payment_settings'=>array('stripe'=>array('enabled'=>'1','secret'=>'sk_test_placeholder','webhook_secret'=>'whsec_contract_test')));
function __($text,$domain=null) { return $text; }
function get_option($key,$default=array()) { return isset($GLOBALS['dk2_test_options'][$key])?$GLOBALS['dk2_test_options'][$key]:$default; }
function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value)); }
function sanitize_email($value) { return filter_var((string)$value,FILTER_SANITIZE_EMAIL); }
function wp_parse_args($args,$defaults=array()) { return array_merge($defaults,is_array($args)?$args:array()); }
function wp_json_encode($value) { return json_encode($value); }
function untrailingslashit($value) { return rtrim((string)$value,'/\\\\'); }
function esc_url_raw($value) { return trim((string)$value); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/','-',strtolower((string)$value)),'-'); }
function absint($value) { return abs((int)$value); }
function home_url($path='/') { return 'https://client.example/'.ltrim($path,'/'); }
function wp_parse_url($url) { return parse_url($url); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_post($url,$args=array()) {
	$GLOBALS['dk2_mock_request']=array('url'=>$url,'args'=>$args);
	return isset($GLOBALS['dk2_mock_response'])?$GLOBALS['dk2_mock_response']:new WP_Error('mock_transport','mock transport failure');
}
function wp_remote_retrieve_response_code($response) { return isset($response['response']['code'])?(int)$response['response']['code']:0; }
function wp_remote_retrieve_body($response) { return isset($response['body'])?$response['body']:''; }
class WP_Error {
	private $code; private $message; private $data;
	public function __construct($code,$message='',$data=array()) { $this->code=$code; $this->message=$message; $this->data=$data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class DropKey_Test_Request {
	private $body; private $signature;
	public function __construct($body,$signature) { $this->body=$body; $this->signature=$signature; }
	public function get_body() { return $this->body; }
	public function get_header($name) { return 'stripe-signature'===$name?$this->signature:''; }
}
function check_case($condition,$message) {
	if (!$condition) { fwrite(STDERR,"FAIL: ".$message."\n"); exit(1); }
	echo "PASS: ".$message."\n";
}
require_once dirname(__DIR__).'/includes/class-payment-gateway.php';
require_once dirname(__DIR__).'/includes/class-stripe-gateway.php';
$secret='whsec_contract_test';
$timestamp=time();
$payload=array('id'=>'evt_contract_001','type'=>'checkout.session.completed','data'=>array('object'=>array(
	'id'=>'cs_contract_001','client_reference_id'=>'order-contract-uuid','payment_status'=>'paid',
	'amount_total'=>1250,'currency'=>'usd','customer_details'=>array('email'=>'buyer@example.test'),
	'metadata'=>array('order_public_id'=>'order-contract-uuid'),'subscription'=>'sub_contract_001'
)));
$raw=json_encode($payload);
$signature=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
$gateway=new \DropKey\V2\Stripe_Gateway();
$result=$gateway->verify_webhook(new DropKey_Test_Request($raw,'t='.$timestamp.',v1='.$signature));
check_case(!($result instanceof WP_Error),'accepts a valid Stripe signature');
check_case(isset($result['event_id'])&&'evt_contract_001'===$result['event_id'],'extracts the provider event ID');
check_case(isset($result['order_public_id'])&&'order-contract-uuid'===$result['order_public_id'],'extracts the DropKey order reference');
check_case(isset($result['amount'])&&12.5===$result['amount'],'normalizes Stripe minor units to the order currency amount');
check_case(isset($result['currency'])&&'USD'===$result['currency'],'normalizes currency to uppercase');
check_case(isset($result['subscription_id'])&&'sub_contract_001'===$result['subscription_id'],'extracts subscription reference');
$tampered=str_replace('1250','9999',$raw);
$bad=$gateway->verify_webhook(new DropKey_Test_Request($tampered,'t='.$timestamp.',v1='.$signature));
check_case($bad instanceof WP_Error && 'dk2_stripe_signature_invalid'===$bad->get_error_code(),'rejects a payload changed after signing');
$old=time()-600; $old_sig=hash_hmac('sha256',$old.'.'.$raw,$secret);
$expired=$gateway->verify_webhook(new DropKey_Test_Request($raw,'t='.$old.',v1='.$old_sig));
check_case($expired instanceof WP_Error && 'dk2_stripe_signature_invalid'===$expired->get_error_code(),'rejects an expired webhook timestamp');
$malformed=$gateway->verify_webhook(new DropKey_Test_Request('{not-json}','t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.{not-json}',$secret)));
check_case($malformed instanceof WP_Error && 'dk2_stripe_event_invalid'===$malformed->get_error_code(),'rejects malformed signed event bodies');

require_once dirname(__DIR__).'/sdk/dropkey-license-client.php';
$GLOBALS['dk2_mock_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('success'=>true,'valid'=>true)));
$client=new DropKey_License_Client('https://licenses.example/','My Product');
$before=isset($GLOBALS['dk2_mock_request']);
$http_client=new DropKey_License_Client('http://licenses.example','my-product');
$http_result=$http_client->activate('not-a-real-key');
check_case($http_result instanceof WP_Error&&'dk2_client_https_required'===$http_result->get_error_code(),'client refuses non-HTTPS licensing endpoints');
check_case($before===isset($GLOBALS['dk2_mock_request']),'non-HTTPS request is blocked before network access');
$client_result=$client->activate('DK2-TEST-KEY');
check_case(is_array($client_result)&&!empty($client_result['valid']),'client accepts a valid JSON success response');
check_case('https://licenses.example/wp-json/dropkey/v1/licenses/activate'===$GLOBALS['dk2_mock_request']['url'],'client calls the versioned activation route');
check_case(true===$GLOBALS['dk2_mock_request']['args']['sslverify']&&0===$GLOBALS['dk2_mock_request']['args']['redirection'],'client keeps TLS verification and disables redirects');
$payload=json_decode($GLOBALS['dk2_mock_request']['args']['body'],true);
check_case(isset($payload['license_key'],$payload['site_url'])&&'https://client.example/'===$payload['site_url'],'client sends the license key and its own site URL');
$GLOBALS['dk2_mock_response']=array('response'=>array('code'=>403),'body'=>json_encode(array('success'=>false,'code'=>'dk2_license_inactive','message'=>'This license is not active.')));
$invalid=$client->validate('DK2-TEST-KEY');
check_case($invalid instanceof WP_Error&&'dk2_license_inactive'===$invalid->get_error_code(),'client preserves API error codes');
$GLOBALS['dk2_mock_response']=new WP_Error('mock_transport','offline');
$offline=$client->validate('DK2-TEST-KEY');
check_case($offline instanceof WP_Error&&'dk2_client_transport_error'===$offline->get_error_code(),'client treats transport failure as an error, not an invalid license');
echo "All webhook and PHP client contract tests passed.\\n";
