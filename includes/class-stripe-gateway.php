<?php
/**
 * Stripe hosted Checkout and signed webhook verification.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Stripe_Gateway extends Payment_Gateway {
	public function name() { return 'stripe'; }
	public function create_checkout($order,$billing,$product,$tier,$success_url,$cancel_url) {
		if (!$this->enabled()) return new \WP_Error('dk2_stripe_not_configured',__('Stripe is not enabled or its secret key is missing.','dropkey-v2'),array('status'=>503));
		$mode=in_array($billing->billing_type,array('monthly','yearly'),true)?'subscription':'payment';
		$currency=strtolower($billing->currency);
		$body=array(
			'mode'=>$mode,'success_url'=>$success_url,'cancel_url'=>$cancel_url,
			'customer_email'=>$order->customer_email,'client_reference_id'=>$order->public_id,
			'metadata[order_public_id]'=>$order->public_id,
			'line_items[0][quantity]'=>'1',
			'line_items[0][price_data][currency]'=>$currency,
			'line_items[0][price_data][unit_amount]'=> (string)round(((float)$billing->price)*100),
			'line_items[0][price_data][product_data][name]'=>$product->name.' — '.$tier->name,
			
		);
		if ('subscription'===$mode) {
			$body['line_items[0][price_data][recurring][interval]']='monthly'===$billing->billing_type?'month':'year';
			$body['subscription_data[metadata][order_public_id]']=$order->public_id;
		}
		$result=$this->json_request('POST','https://api.stripe.com/v1/checkout/sessions',array(
			'headers'=>array('Authorization'=>'Bearer '.$this->config('secret'),'Content-Type'=>'application/x-www-form-urlencoded'),
			'body'=>http_build_query($body,'','&')
		));
		if (is_wp_error($result)) return $result;
		if (empty($result['id'])||empty($result['url'])) return new \WP_Error('dk2_stripe_session_invalid',__('Stripe did not return a checkout URL.','dropkey-v2'),array('status'=>502));
		return array('session_id'=>sanitize_text_field($result['id']),'order_id'=>'','checkout_url'=>esc_url_raw($result['url']),'subscription_id'=>isset($result['subscription'])?sanitize_text_field($result['subscription']):'');
	}
	public function schedule_cancel($subscription_id) {
		if (!$this->enabled()) return new \\WP_Error('dk2_stripe_not_configured',__('Stripe is not configured.','dropkey-v2'));
		return $this->json_request('POST','https://api.stripe.com/v1/subscriptions/'.rawurlencode($subscription_id),array('headers'=>array('Authorization'=>'Bearer '.$this->config('secret'),'Content-Type'=>'application/x-www-form-urlencoded'),'body'=>http_build_query(array('cancel_at_period_end'=>'true'))));
	}
	public function verify_webhook($request) {
		$secret=$this->config('webhook_secret');
		if (''===$secret) return new \WP_Error('dk2_stripe_webhook_unconfigured',__('Stripe webhook signing secret is not configured.','dropkey-v2'),array('status'=>503));
		$raw=$request->get_body(); $header=$request->get_header('stripe-signature');
		$timestamp=0; $signatures=array();
		foreach(explode(',',(string)$header) as $part) {
			$bits=explode('=',trim($part),2);
			if (2!==count($bits)) continue;
			if ('t'===$bits[0]) $timestamp=(int)$bits[1];
			if ('v1'===$bits[0]) $signatures[]=$bits[1];
		}
		if (!$timestamp||abs(time()-$timestamp)>300||empty($signatures)) return new \WP_Error('dk2_stripe_signature_invalid',__('Stripe webhook signature is missing or expired.','dropkey-v2'),array('status'=>400));
		$expected=hash_hmac('sha256',$timestamp.'.'.$raw,$secret); $valid=false;
		foreach($signatures as $signature) if (hash_equals($expected,$signature)) $valid=true;
		if (!$valid) return new \WP_Error('dk2_stripe_signature_invalid',__('Stripe webhook signature verification failed.','dropkey-v2'),array('status'=>400));
		$event=json_decode($raw,true);
		if (!is_array($event)||empty($event['id'])||empty($event['type'])||empty($event['data']['object'])) return new \WP_Error('dk2_stripe_event_invalid',__('Stripe webhook payload is invalid.','dropkey-v2'),array('status'=>400));
		$o=$event['data']['object']; $metadata=isset($o['metadata'])&&is_array($o['metadata'])?$o['metadata']:array();
		if (empty($metadata['order_public_id'])&&isset($o['client_reference_id'])) $metadata['order_public_id']=$o['client_reference_id'];
		if (empty($metadata['order_public_id'])&&isset($o['subscription_details']['metadata']['order_public_id'])) $metadata['order_public_id']=$o['subscription_details']['metadata']['order_public_id'];
		$type='ignored';
		if ('invoice.payment_succeeded'===$event['type']||'checkout.session.async_payment_succeeded'===$event['type']) $type='payment_succeeded';
		elseif ('checkout.session.completed'===$event['type']&&isset($o['payment_status'])&&in_array($o['payment_status'],array('paid','no_payment_required'),true)) $type='payment_succeeded';
		elseif (in_array($event['type'],array('invoice.payment_failed','checkout.session.async_payment_failed'),true)) $type='payment_failed';
		elseif ('customer.subscription.updated'===$event['type']) $type='subscription_updated';
		elseif ('customer.subscription.deleted'===$event['type']) $type='subscription_cancelled';
		elseif ('charge.refunded'===$event['type']) $type='refunded';
		elseif ('charge.dispute.created'===$event['type']) $type='chargeback';
		$sub=isset($o['subscription'])?$o['subscription']:(isset($o['id'])&&0===strpos($event['type'],'customer.subscription.')?$o['id']:'');
		$period=isset($o['current_period_end'])?(int)$o['current_period_end']:(isset($o['lines']['data'][0]['period']['end'])?(int)$o['lines']['data'][0]['period']['end']:0);
		return array('event_id'=>sanitize_text_field($event['id']),'event_type'=>sanitize_text_field($event['type']),'type'=>$type,
			'order_public_id'=>isset($metadata['order_public_id'])?sanitize_text_field($metadata['order_public_id']):'',
			'gateway_order_id'=>'','gateway_session_id'=>isset($o['id'])&&0===strpos($event['type'],'checkout.session.')?sanitize_text_field($o['id']):'',
			'gateway_payment_id'=>isset($o['payment_intent'])&&is_string($o['payment_intent'])?sanitize_text_field($o['payment_intent']):'',
			'subscription_id'=>is_string($sub)?sanitize_text_field($sub):'','period_end'=>$period?gmdate('Y-m-d H:i:s',$period):'',
			'status'=>isset($o['status'])?sanitize_key($o['status']):'','customer_email'=>isset($o['customer_details']['email'])?sanitize_email($o['customer_details']['email']):'',
			'amount'=>isset($o['amount_total'])?((float)$o['amount_total']/100):(isset($o['amount_paid'])?((float)$o['amount_paid']/100):null),
			'currency'=>isset($o['currency'])?strtoupper(sanitize_text_field($o['currency'])):'',
			'payload_hash'=>hash('sha256',$raw),'raw'=>$event);
	}
}
