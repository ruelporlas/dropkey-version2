<?php
/**
 * Versioned public licensing and protected download API.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class REST_API {
	public function register_routes() {
		register_rest_route('dropkey/v1','/licenses/activate',array('methods'=>'POST','callback'=>array($this,'activate'),'permission_callback'=>'__return_true'));
		register_rest_route('dropkey/v1','/licenses/deactivate',array('methods'=>'POST','callback'=>array($this,'deactivate'),'permission_callback'=>'__return_true'));
		register_rest_route('dropkey/v1','/licenses/validate',array('methods'=>'POST','callback'=>array($this,'validate'),'permission_callback'=>'__return_true'));
		register_rest_route('dropkey/v1','/products/(?P<slug>[a-zA-Z0-9_-]+)/updates',array('methods'=>'POST','callback'=>array($this,'updates'),'permission_callback'=>'__return_true','args'=>array('slug'=>array('required'=>true,'sanitize_callback'=>'sanitize_title'))));
		register_rest_route('dropkey/v1','/downloads/(?P<token>[a-f0-9]{64})',array('methods'=>'GET','callback'=>array($this,'download'),'permission_callback'=>'__return_true'));
		register_rest_route('dropkey/v1','/checkout/sessions',array('methods'=>'POST','callback'=>array($this,'checkout'),'permission_callback'=>'__return_true'));
		register_rest_route('dropkey/v1','/checkout/paypal/capture',array('methods'=>'POST','callback'=>array($this,'capture_paypal'),'permission_callback'=>'__return_true'));
		register_rest_route('dropkey/v1','/webhooks/(?P<gateway>stripe|paypal)',array('methods'=>'POST','callback'=>array($this,'webhook'),'permission_callback'=>'__return_true'));
	}
	private function payload($request) { $data=$request->get_json_params(); return is_array($data)?$data:$request->get_params(); }
	private function error($error) {
		$data=$error->get_error_data(); $status=is_array($data)&&isset($data['status'])?absint($data['status']):400;
		return new \WP_REST_Response(array('success'=>false,'code'=>$error->get_error_code(),'message'=>$error->get_error_message()),$status);
	}
	private function response($data,$status=200) { return new \WP_REST_Response(array_merge(array('success'=>true),$data),$status); }
	public function activate($request) {
		$p=$this->payload($request); $result=(new License_Service())->activate(isset($p['license_key'])?sanitize_text_field($p['license_key']):'',isset($p['site_url'])?esc_url_raw($p['site_url']):'');
		if(is_wp_error($result)) return $this->error($result);
		return $this->response(array('valid'=>true,'already_active'=>!empty($result['already_active']),'activation_id'=>isset($result['activation_id'])?$result['activation_id']:null));
	}
	public function deactivate($request) {
		$p=$this->payload($request); $result=(new License_Service())->deactivate(isset($p['license_key'])?$p['license_key']:'',isset($p['site_url'])?$p['site_url']:'');
		if(is_wp_error($result)) return $this->error($result);
		return $this->response(array('valid'=>true,'deactivated'=>!empty($result['deactivated'])));
	}
	public function validate($request) {
		$p=$this->payload($request); $result=(new License_Service())->validate(isset($p['license_key'])?$p['license_key']:'',isset($p['site_url'])?$p['site_url']:'');
		if(is_wp_error($result)) return $this->error($result);
		return $this->response(array('valid'=>true,'license_status'=>$result['license']->status,'expires_at'=>$result['license']->expires_at));
	}
	public function updates($request) {
		global $wpdb; $p=$this->payload($request); $key=isset($p['license_key'])?sanitize_text_field($p['license_key']):''; $site=isset($p['site_url'])?$p['site_url']:''; $current=isset($p['current_version'])?sanitize_text_field($p['current_version']):'0.0.0';
		if (''===trim((string)$site)) return $this->error(new \WP_Error('dk2_site_url_required',__('A site_url is required for update checks.','dropkey-v2'),array('status'=>400)));
		$service=new License_Service(); $valid=$service->validate($key,$site); if(is_wp_error($valid)) return $this->error($valid);
		$products=Database::table('products'); $product=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$products} WHERE id=%d AND slug=%s AND status='published'",$valid['license']->product_id,sanitize_title($request['slug'])));
		if(!$product) return $this->error(new \WP_Error('dk2_product_license_mismatch',__('This license does not belong to the requested product.','dropkey-v2'),array('status'=>403)));
		$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".Database::table('releases')." WHERE product_id=%d AND status='published'",$product->id)); $latest=null;
		foreach($rows as $row) if(null===$latest||version_compare($row->version,$latest->version,'>')) $latest=$row;
		if(!$latest||version_compare($latest->version,$current,'<=')) return $this->response(array('update_available'=>false));
		$link=(new Download_Service())->create_token($valid['license']->id,$latest->id); if(is_wp_error($link)) return $this->error($link);
		return $this->response(array('update_available'=>true,'version'=>$latest->version,'download_url'=>$link['url'],'download_expires_at'=>$link['expires_at'],'release_notes'=>wp_strip_all_tags($latest->release_notes)));
	}

	public function checkout($request) {
		$p=$this->payload($request);
		$billing_id=isset($p['billing_option_id'])?absint($p['billing_option_id']):0;
		$email=isset($p['customer_email'])?sanitize_email($p['customer_email']):'';
		$gateway=isset($p['gateway'])?sanitize_key($p['gateway']):'';
		$success=isset($p['success_url'])?esc_url_raw($p['success_url']):home_url('/');
		$cancel=isset($p['cancel_url'])?esc_url_raw($p['cancel_url']):home_url('/');
		$result=(new Commerce_Service())->start_checkout($billing_id,$email,$gateway,$success,$cancel);
		if (is_wp_error($result)) return $this->error($result);
		return $this->response($result,200);
	}
	public function capture_paypal($request) {
		$p=$this->payload($request);
		$public_id=isset($p['order_id'])?sanitize_text_field($p['order_id']):'';
		$paypal_id=isset($p['paypal_order_id'])?sanitize_text_field($p['paypal_order_id']):'';
		if (''===$public_id||''===$paypal_id) return $this->error(new \WP_Error('dk2_paypal_capture_fields',__('order_id and paypal_order_id are required.','dropkey-v2'),array('status'=>400)));
		$result=(new Commerce_Service())->capture_paypal($public_id,$paypal_id);
		if (is_wp_error($result)) return $this->error($result);
		return $this->response($result,200);
	}
	public function webhook($request) {
		$gateway=sanitize_key($request['gateway']);
		$instance=Payment_Gateway::make($gateway);
		if (is_wp_error($instance)) return $this->error($instance);
		$event=$instance->verify_webhook($request);
		if (is_wp_error($event)) return $this->error($event);
		$result=(new Commerce_Service())->process_webhook($gateway,$event);
		if (is_wp_error($result)) return $this->error($result);
		return $this->response($result,200);
	}

	public function download($request) {
		$result=(new Download_Service())->deliver($request['token']); if(is_wp_error($result)) return $this->error($result);
		if(headers_sent()) return $this->error(new \WP_Error('dk2_download_headers_sent',__('The download could not start because output was already sent.','dropkey-v2'),array('status'=>500)));
		nocache_headers(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.basename($result['filename']).'"'); header('Content-Length: '.(string)$result['size']); header('X-Content-Type-Options: nosniff');
		$handle=fopen($result['path'],'rb'); if(!$handle) return $this->error(new \WP_Error('dk2_download_open_failed',__('The package could not be opened.','dropkey-v2'),array('status'=>500)));
		while(!feof($handle)){ $chunk=fread($handle,1048576); if(false===$chunk) break; echo $chunk; }
		fclose($handle); exit;
	}
}