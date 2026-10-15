<?php
/**
 * PayPal hosted checkout/subscriptions and verified webhooks.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class PayPal_Gateway extends Payment_Gateway {
	public function name() { return 'paypal'; }
	private function api_base() { return '1'===$this->config('live_mode')?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com'; }
	private function access_token() {
		$id=$this->config('client_id'); $secret=$this->config('secret');
		if (''===$id||''===$secret||'1'!==$this->config('enabled')) return new \WP_Error('dk2_paypal_not_configured',__('PayPal is not enabled or credentials are missing.','dropkey-v2'),array('status'=>503));
		$response=wp_remote_post($this->api_base().'/v1/oauth2/token',array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Basic '.base64_encode($id.':'.$secret),'Accept'=>'application/json','Content-Type'=>'application/x-www-form-urlencoded'),'body'=>'grant_type=client_credentials'));
		if (is_wp_error($response)||(int)wp_remote_retrieve_response_code($response)<200||(int)wp_remote_retrieve_response_code($response)>=300) return new \WP_Error('dk2_paypal_auth_failed',__('PayPal authentication failed. Check the selected environment and credentials.','dropkey-v2'),array('status'=>502));
		$data=json_decode(wp_remote_retrieve_body($response),true);
		return !empty($data['access_token'])?$data['access_token']:new \WP_Error('dk2_paypal_auth_failed',__('PayPal did not return an access token.','dropkey-v2'),array('status'=>502));
	}
	private function paypal_request($method,$path,$token,$body=null,$request_id='') {
		$args=array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json','Content-Type'=>'application/json'));
		if (''!==$request_id) $args['headers']['PayPal-Request-Id']=$request_id;
		if (null!==$body) $args['body']=wp_json_encode($body);
		$response=wp_remote_request($this->api_base().$path,array_merge($args,array('method'=>$method)));
		if (is_wp_error($response)) return new \WP_Error('dk2_paypal_transport',__('PayPal could not be reached. Please try again.','dropkey-v2'),array('status'=>502));
		$status=(int)wp_remote_retrieve_response_code($response); $data=json_decode(wp_remote_retrieve_body($response),true);
		if ($status<200||$status>=300||!is_array($data)) {
			do_action('dk2_gateway_request_failed','paypal',$status,isset($data['name'])?$data['name']:'provider_error');
			return new \WP_Error('dk2_paypal_request_failed',__('PayPal rejected the request. Check gateway settings and provider logs.','dropkey-v2'),array('status'=>502,'provider_status'=>$status));
		}
		return $data;
	}
	/** Create and persist a PayPal plan when a recurring billing option is first used. */
	private function ensure_subscription_plan($billing,$product,$tier,$token) {
		if (!empty($billing->paypal_plan_id)) return $billing->paypal_plan_id;
		if (!in_array($billing->billing_type,array('monthly','yearly'),true)) return new \WP_Error('dk2_paypal_plan_type_invalid',__('A PayPal plan can only be created for monthly or yearly billing.','dropkey-v2'));
		$site=home_url('/');
		$product_request_id=substr(hash('sha256',$site.'|dk2-product|'.(int)$billing->id.'|'.$product->name.'|'.$tier->name),0,32);
		$paypal_product=$this->paypal_request('POST','/v1/catalogs/products',$token,array(
			'name'=>substr($product->name.' — '.$tier->name,0,127),
			'description'=>substr('DropKey subscription for '.$product->name.' — '.$tier->name,0,256),
			'type'=>'DIGITAL'
		),$product_request_id);
		if (is_wp_error($paypal_product)) return $paypal_product;
		if (empty($paypal_product['id'])) return new \WP_Error('dk2_paypal_product_invalid',__('PayPal did not return a product ID for the subscription plan.','dropkey-v2'),array('status'=>502));
		$interval='monthly'===$billing->billing_type?'MONTH':'YEAR';
		$currency=strtoupper((string)$billing->currency);
		$price=number_format((float)$billing->price,2,'.','');
		$plan_request_id=substr(hash('sha256',$site.'|dk2-plan|'.(int)$billing->id.'|'.$billing->billing_type.'|'.$price.'|'.$currency),0,32);
		$plan=$this->paypal_request('POST','/v1/billing/plans',$token,array(
			'product_id'=>sanitize_text_field($paypal_product['id']),
			'name'=>substr($product->name.' — '.$tier->name.' — '.ucfirst($billing->billing_type),0,127),
			'description'=>substr('Automatically managed by DropKey for '.$product->name.' — '.$tier->name,0,256),
			'status'=>'ACTIVE',
			'billing_cycles'=>array(array(
				'frequency'=>array('interval_unit'=>$interval,'interval_count'=>1),
				'tenure_type'=>'REGULAR','sequence'=>1,'total_cycles'=>0,
				'pricing_scheme'=>array('fixed_price'=>array('value'=>$price,'currency_code'=>$currency))
			)),
			'payment_preferences'=>array('auto_bill_outstanding'=>true,'payment_failure_threshold'=>1)
		),$plan_request_id);
		if (is_wp_error($plan)) return $plan;
		if (empty($plan['id'])) return new \WP_Error('dk2_paypal_plan_invalid',__('PayPal did not return a subscription plan ID.','dropkey-v2'),array('status'=>502));
		global $wpdb;
		$plan_id=sanitize_text_field($plan['id']);
		$saved=$wpdb->update(Database::table('billing_options'),array('paypal_plan_id'=>$plan_id,'updated_at'=>current_time('mysql',true)),array('id'=>(int)$billing->id));
		if (false===$saved) return new \WP_Error('dk2_paypal_plan_save_failed',__('PayPal created a subscription plan, but DropKey could not save its reference. Check the WordPress database log before retrying.','dropkey-v2'),array('status'=>500));
		$billing->paypal_plan_id=$plan_id;
		return $plan_id;
	}
	public function create_checkout($order,$billing,$product,$tier,$success_url,$cancel_url) {
		$token=$this->access_token(); if (is_wp_error($token)) return $token;
		if (in_array($billing->billing_type,array('monthly','yearly'),true)) {
			$plan_id=$this->ensure_subscription_plan($billing,$product,$tier,$token);
			if (is_wp_error($plan_id)) return $plan_id;
			$data=$this->paypal_request('POST','/v1/billing/subscriptions',$token,array(
				'plan_id'=>$plan_id,'custom_id'=>$order->public_id,
				'application_context'=>array('brand_name'=>get_bloginfo('name'),'user_action'=>'SUBSCRIBE_NOW','return_url'=>$success_url,'cancel_url'=>$cancel_url)
			));
		} else {
			$data=$this->paypal_request('POST','/v2/checkout/orders',$token,array(
				'intent'=>'CAPTURE','purchase_units'=>array(array('reference_id'=>$order->public_id,'custom_id'=>$order->public_id,
					'description'=>substr($product->name.' — '.$tier->name,0,127),
					'amount'=>array('currency_code'=>strtoupper($billing->currency),'value'=>number_format((float)$billing->price,2,'.','')))),
				'application_context'=>array('brand_name'=>get_bloginfo('name'),'user_action'=>'PAY_NOW','return_url'=>$success_url,'cancel_url'=>$cancel_url)
			));
		}
		if (is_wp_error($data)) return $data;
		$approve='';
		if (!empty($data['links'])&&is_array($data['links'])) foreach($data['links'] as $link) if (isset($link['rel'])&&'approve'===$link['rel']&&!empty($link['href'])) $approve=$link['href'];
		if (empty($data['id'])||''===$approve) return new \WP_Error('dk2_paypal_checkout_invalid',__('PayPal did not return an approval URL.','dropkey-v2'),array('status'=>502));
		return array('session_id'=>sanitize_text_field($data['id']),'order_id'=>sanitize_text_field($data['id']),'checkout_url'=>esc_url_raw($approve),'subscription_id'=>in_array($billing->billing_type,array('monthly','yearly'),true)?sanitize_text_field($data['id']):'');
	}
	public function capture_order($order,$paypal_order_id) {
		if (!$order||'paypal'!==$order->gateway||'pending'!==$order->status) return new \WP_Error('dk2_paypal_order_invalid',__('This order cannot be captured.','dropkey-v2'),array('status'=>409));
		if (!hash_equals((string)$order->gateway_order_id,(string)$paypal_order_id)) return new \WP_Error('dk2_paypal_order_mismatch',__('The PayPal order does not match this checkout.','dropkey-v2'),array('status'=>403));
		$token=$this->access_token(); if (is_wp_error($token)) return $token;
		$result=$this->paypal_request('POST','/v2/checkout/orders/'.rawurlencode($paypal_order_id).'/capture',$token,array());
		if (is_wp_error($result)) return $result;
		if (empty($result['status'])||'COMPLETED'!==$result['status']) return new \WP_Error('dk2_paypal_capture_incomplete',__('PayPal has not confirmed payment.','dropkey-v2'),array('status'=>402));
		$amount=null; $currency='';
		if (!empty($result['purchase_units'][0]['payments']['captures'][0]['amount'])) {
			$amount=$result['purchase_units'][0]['payments']['captures'][0]['amount'];
			$currency=isset($amount['currency_code'])?$amount['currency_code']:'';
			$amount=isset($amount['value'])?(float)$amount['value']:null;
		}
		if (null===$amount||strtoupper($order->currency)!==strtoupper($currency)||abs($amount-(float)$order->amount)>0.009) return new \WP_Error('dk2_paypal_amount_mismatch',__('The captured PayPal amount does not match this order.','dropkey-v2'),array('status'=>409));
		return array('provider_capture_id'=>isset($result['purchase_units'][0]['payments']['captures'][0]['id'])?sanitize_text_field($result['purchase_units'][0]['payments']['captures'][0]['id']):'','status'=>'paid');
	}
	public function subscription_details($subscription_id) {
		$token=$this->access_token(); if (is_wp_error($token)) return $token;
		$data=$this->paypal_request('GET','/v1/billing/subscriptions/'.rawurlencode($subscription_id),$token);
		if (is_wp_error($data)) return $data;
		$stamp=!empty($data['billing_info']['next_billing_time'])?strtotime($data['billing_info']['next_billing_time']):0;
		return array('period_end'=>$stamp?gmdate('Y-m-d H:i:s',$stamp):'','status'=>isset($data['status'])?sanitize_key($data['status']):'');
	}
	public function cancel_subscription_now($subscription_id) {
		$token=$this->access_token(); if (is_wp_error($token)) return $token;
		return $this->paypal_request('POST','/v1/billing/subscriptions/'.rawurlencode($subscription_id).'/cancel',$token,array('reason'=>'Subscription period ended as scheduled by DropKey.'));
	}
	public function verify_webhook($request) {
		$webhook_id=$this->config('webhook_id');
		if (''===$webhook_id) return new \WP_Error('dk2_paypal_webhook_unconfigured',__('PayPal webhook ID is not configured.','dropkey-v2'),array('status'=>503));
		$token=$this->access_token(); if (is_wp_error($token)) return $token;
		$raw=$request->get_body(); if (strlen($raw)>1000000) return new \WP_Error('dk2_paypal_webhook_too_large',__('The webhook payload is too large.','dropkey-v2'),array('status'=>413)); $event=json_decode($raw,true);
		if (!is_array($event)||empty($event['id'])||empty($event['event_type'])) return new \WP_Error('dk2_paypal_event_invalid',__('PayPal webhook payload is invalid.','dropkey-v2'),array('status'=>400));
		$headers=$request->get_headers(); $get=function($name) use ($headers) { $key=strtolower($name); return isset($headers[$key])?(is_array($headers[$key])?reset($headers[$key]):$headers[$key]):''; };
		$verification=array(
			'auth_algo'=>$get('paypal-auth-algo'),'cert_url'=>$get('paypal-cert-url'),
			'transmission_id'=>$get('paypal-transmission-id'),'transmission_sig'=>$get('paypal-transmission-sig'),
			'transmission_time'=>$get('paypal-transmission-time'),'webhook_id'=>$webhook_id,'webhook_event'=>$event
		);
		$verified=$this->paypal_request('POST','/v1/notifications/verify-webhook-signature',$token,$verification);
		if (is_wp_error($verified)) return $verified;
		if (empty($verified['verification_status'])||'SUCCESS'!==$verified['verification_status']) return new \WP_Error('dk2_paypal_signature_invalid',__('PayPal webhook signature verification failed.','dropkey-v2'),array('status'=>400));
		$r=isset($event['resource'])&&is_array($event['resource'])?$event['resource']:array();
		$type='ignored'; $event_type=$event['event_type'];
		if (in_array($event_type,array('PAYMENT.CAPTURE.COMPLETED','BILLING.SUBSCRIPTION.PAYMENT.COMPLETED'),true)) $type='payment_succeeded';
		elseif (in_array($event_type,array('BILLING.SUBSCRIPTION.PAYMENT.FAILED','PAYMENT.CAPTURE.DENIED'),true)) $type='payment_failed';
		elseif (in_array($event_type,array('BILLING.SUBSCRIPTION.CANCELLED','BILLING.SUBSCRIPTION.EXPIRED'),true)) $type='subscription_cancelled';
		elseif ('BILLING.SUBSCRIPTION.UPDATED'===$event_type) $type='subscription_updated';
		elseif ('BILLING.SUBSCRIPTION.SUSPENDED'===$event_type) $type='payment_failed';
		elseif ('PAYMENT.CAPTURE.REFUNDED'===$event_type) $type='refunded';
		elseif ('CUSTOMER.DISPUTE.CREATED'===$event_type) $type='chargeback';
		$related=isset($r['supplementary_data']['related_ids']['order_id'])?$r['supplementary_data']['related_ids']['order_id']:(isset($r['billing_agreement_id'])?$r['billing_agreement_id']:'');
		$custom=isset($r['custom_id'])?$r['custom_id']:(isset($r['purchase_units'][0]['custom_id'])?$r['purchase_units'][0]['custom_id']:'');
		$sub=isset($r['billing_agreement_id'])?$r['billing_agreement_id']:(isset($r['id'])&&false!==strpos($event_type,'SUBSCRIPTION')?$r['id']:'');
		$period=isset($r['billing_info']['next_billing_time'])?strtotime($r['billing_info']['next_billing_time']):0;
		$payment_id=isset($r['supplementary_data']['related_ids']['capture_id'])?$r['supplementary_data']['related_ids']['capture_id']:(isset($r['disputed_transactions'][0]['seller_transaction_id'])?$r['disputed_transactions'][0]['seller_transaction_id']:(in_array($event_type,array('PAYMENT.CAPTURE.COMPLETED','PAYMENT.CAPTURE.REFUNDED'),true)&&isset($r['id'])?$r['id']:''));
		return array('event_id'=>sanitize_text_field($event['id']),'event_type'=>sanitize_text_field($event_type),'type'=>$type,
			'order_public_id'=>sanitize_text_field($custom),'gateway_order_id'=>sanitize_text_field($related),
			'gateway_session_id'=>'','gateway_payment_id'=>sanitize_text_field($payment_id),'subscription_id'=>sanitize_text_field($sub),'period_end'=>$period?gmdate('Y-m-d H:i:s',$period):'',
			'status'=>isset($r['status'])?sanitize_key($r['status']):'','customer_email'=>'',
			'amount'=>isset($r['amount']['value'])?(float)$r['amount']['value']:(isset($r['seller_receivable_breakdown']['gross_amount']['value'])?(float)$r['seller_receivable_breakdown']['gross_amount']['value']:null),
			'currency'=>isset($r['amount']['currency_code'])?strtoupper(sanitize_text_field($r['amount']['currency_code'])):(isset($r['seller_receivable_breakdown']['gross_amount']['currency_code'])?strtoupper(sanitize_text_field($r['seller_receivable_breakdown']['gross_amount']['currency_code'])):''),
			'payload_hash'=>hash('sha256',$raw),'raw'=>$event);
	}
}
