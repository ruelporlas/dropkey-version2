<?php
/**
 * Portable licensing client for plugins distributed through DropKey V2.
 * Copy this file into the plugin you sell. Never embed an admin API secret.
 * @package DropKeyLicenseClient
 */
defined('ABSPATH') || exit;
if (!class_exists('DropKey_License_Client')) {
	final class DropKey_License_Client {
		private $endpoint; private $product_slug; private $timeout;
		public function __construct($dropkey_url,$product_slug,$timeout=15) {
			$this->endpoint=untrailingslashit(esc_url_raw($dropkey_url)).'/wp-json/dropkey/v1';
			$this->product_slug=sanitize_title($product_slug); $this->timeout=max(5,min(30,absint($timeout)));
		}
		public function activate($key) { return $this->request('/licenses/activate',array('license_key'=>$key,'site_url'=>home_url('/'))); }
		public function deactivate($key) { return $this->request('/licenses/deactivate',array('license_key'=>$key,'site_url'=>home_url('/'))); }
		public function validate($key) { return $this->request('/licenses/validate',array('license_key'=>$key,'site_url'=>home_url('/'))); }
		public function check_for_updates($key,$version) { return $this->request('/products/'.rawurlencode($this->product_slug).'/updates',array('license_key'=>$key,'site_url'=>home_url('/'),'current_version'=>(string)$version)); }
		private function request($route,$payload) {
			$parts=wp_parse_url($this->endpoint);
			if (!is_array($parts)||empty($parts['host'])||empty($parts['scheme'])||'https'!==strtolower($parts['scheme'])) return new WP_Error('dk2_client_https_required',__('The DropKey licensing endpoint must use HTTPS.','dropkey-v2'));
			$response=wp_remote_post($this->endpoint.$route,array('timeout'=>$this->timeout,'redirection'=>0,'sslverify'=>true,'headers'=>array('Accept'=>'application/json','Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
			if (is_wp_error($response)) return new WP_Error('dk2_client_transport_error',__('DropKey could not be reached. Try again later.','dropkey-v2'),array('cause'=>$response->get_error_code()));
			$status=(int)wp_remote_retrieve_response_code($response); $body=json_decode(wp_remote_retrieve_body($response),true);
			if (!is_array($body)) return new WP_Error('dk2_client_invalid_response',__('DropKey returned an invalid response.','dropkey-v2'),array('status'=>$status));
			if ($status<200||$status>=300||empty($body['success'])) return new WP_Error(isset($body['code'])?sanitize_key($body['code']):'dk2_client_request_failed',isset($body['message'])?sanitize_text_field($body['message']):__('The DropKey request failed.','dropkey-v2'),array('status'=>$status));
			return $body;
		}
	}
}