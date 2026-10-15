<?php
/**
 * Shared payment gateway helpers.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
abstract class Payment_Gateway {
	protected $settings;
	public function __construct() {
		$all=get_option('dk2_payment_settings',array());
		$this->settings=is_array($all)?$all:array();
	}
	public static function minor_unit_factor($currency) {
		$zero_decimals=array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF');
		return in_array(strtoupper((string)$currency),$zero_decimals,true)?1:100;
	}
	public static function make($gateway) {
		if ('stripe'===$gateway) return new Stripe_Gateway();
		if ('paypal'===$gateway) return new PayPal_Gateway();
		return new \WP_Error('dk2_gateway_invalid',__('Choose PayPal or Stripe.','dropkey-v2'),array('status'=>400));
	}
	protected function config($key) {
		return isset($this->settings[$this->name()][$key])?(string)$this->settings[$this->name()][$key]:'';
	}
	protected function enabled() {
		return '1'===$this->config('enabled') && ''!==$this->config('secret');
	}
	protected function json_request($method,$url,$args=array()) {
		$defaults=array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>array('Accept'=>'application/json'),'body'=>'');
		$args=wp_parse_args($args,$defaults);
		$response=wp_remote_request($url,array_merge($args,array('method'=>$method)));
		if (is_wp_error($response)) { Logger::error($this->name(),'api_transport_failed','Payment provider transport failed',array('error_code'=>sanitize_key($response->get_error_code()),'method'=>sanitize_key($method),'provider'=>$this->name())); return new \WP_Error('dk2_gateway_transport',__('The payment provider could not be reached. Please try again.','dropkey-v2'),array('status'=>502)); }
		$status=(int)wp_remote_retrieve_response_code($response);
		$body=json_decode(wp_remote_retrieve_body($response),true);
		if ($status<200||$status>=300||!is_array($body)) {
			Logger::error($this->name(),'api_request_rejected','Payment provider rejected an API request',array('http_status'=>$status,'provider_error'=>isset($body['error']['type'])?sanitize_key($body['error']['type']):'provider_error','method'=>sanitize_key($method),'endpoint'=>sanitize_key(basename(parse_url($url,PHP_URL_PATH)))));
			do_action('dk2_gateway_request_failed',$this->name(),$status,isset($body['error']['type'])?$body['error']['type']:'provider_error');
			return new \WP_Error('dk2_gateway_request_failed',__('The payment provider rejected the request. Check gateway settings and provider logs.','dropkey-v2'),array('status'=>502,'provider_status'=>$status));
		}
		return $body;
	}
	abstract public function name();
	abstract public function create_checkout($order,$billing,$product,$tier,$success_url,$cancel_url);
	abstract public function verify_webhook($request);
}
