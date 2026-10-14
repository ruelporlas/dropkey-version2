<?php
/**
 * Checkout, order fulfillment, subscription state, and idempotent webhook processing.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Commerce_Service {
	private function rate_limit_checkout($email) {
		$keys=array(); $ip=isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field($_SERVER['REMOTE_ADDR']):'';
		if (''!==$ip) $keys[]='dk2_checkout_ip_'.hash('sha256',$ip);
		$keys[]='dk2_checkout_email_'.hash('sha256',strtolower($email));
		foreach($keys as $key) if ((int)get_transient($key)>=12) return new \WP_Error('dk2_checkout_rate_limited',__('Too many checkout attempts. Please wait before trying again.','dropkey-v2'),array('status'=>429));
		foreach($keys as $key) set_transient($key,(int)get_transient($key)+1,HOUR_IN_SECONDS);
		return true;
	}
	public function start_checkout($billing_id,$email,$gateway,$success_url,$cancel_url) {
		global $wpdb;
		$billing_id=absint($billing_id); $email=sanitize_email($email); $gateway=sanitize_key($gateway);
		if (!is_email($email)) return new \WP_Error('dk2_checkout_email_invalid',__('Enter a valid email address.','dropkey-v2'),array('status'=>400));
		$rate_limit=$this->rate_limit_checkout($email); if (is_wp_error($rate_limit)) return $rate_limit;
		$b=Database::table('billing_options'); $t=Database::table('tiers'); $p=Database::table('products');
		$billing=$wpdb->get_row($wpdb->prepare("SELECT b.*,t.id AS tier_id,t.name AS tier_name,t.status AS tier_status,p.id AS product_id,p.name AS product_name,p.status AS product_status FROM {$b} b INNER JOIN {$t} t ON t.id=b.tier_id INNER JOIN {$p} p ON p.id=t.product_id WHERE b.id=%d",$billing_id));
		if (!$billing||'published'!==$billing->status||'published'!==$billing->tier_status||'published'!==$billing->product_status) return new \WP_Error('dk2_checkout_option_unavailable',__('This product option is not available.','dropkey-v2'),array('status'=>404));
		$billing->id=$billing_id;
		$product=(object)array('id'=>$billing->product_id,'name'=>$billing->product_name);
		$tier=(object)array('id'=>$billing->tier_id,'name'=>$billing->tier_name);
		if ('free'===$billing->billing_type) $gateway='free';
		elseif (!in_array($gateway,array('paypal','stripe'),true)) return new \WP_Error('dk2_checkout_gateway_invalid',__('Choose PayPal or Stripe for this paid option.','dropkey-v2'),array('status'=>400));
		if ('free'!==$gateway) {
			$instance=Payment_Gateway::make($gateway); if (is_wp_error($instance)) return $instance;
			$settings=get_option('dk2_payment_settings',array()); $config=isset($settings[$gateway])?$settings[$gateway]:array();
			if (empty($config['enabled'])||('stripe'===$gateway&&(empty($config['secret'])||empty($config['webhook_secret'])))||('paypal'===$gateway&&(empty($config['client_id'])||empty($config['secret'])||empty($config['webhook_id']))) return new \WP_Error('dk2_checkout_gateway_disabled',__('That payment method is not currently available.','dropkey-v2'),array('status'=>503));
		}
		$public_id=wp_generate_uuid4(); $now=current_time('mysql',true);
		$ok=$wpdb->insert(Database::table('orders'),array(
			'public_id'=>$public_id,'product_id'=>$billing->product_id,'tier_id'=>$billing->tier_id,'billing_option_id'=>$billing_id,
			'customer_email'=>$email,'customer_user_id'=>null,'gateway'=>$gateway,'gateway_order_id'=>'','gateway_session_id'=>'',
			'status'=>'pending','amount'=>(float)$billing->price,'currency'=>strtoupper($billing->currency?$billing->currency:'USD'),
			'created_at'=>$now,'updated_at'=>$now
		));
		if (!$ok) return new \WP_Error('dk2_checkout_order_failed',__('The checkout order could not be created.','dropkey-v2'),array('status'=>500));
		$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE id=%d',$wpdb->insert_id));
		$latest_release=$wpdb->get_var($wpdb->prepare("SELECT id FROM ".Database::table('releases')." WHERE product_id=%d AND status='published' ORDER BY created_at DESC,id DESC LIMIT 1",$billing->product_id));
		if (!$latest_release) {
			$wpdb->delete(Database::table('orders'),array('id'=>$order->id),array('%d'));
			return new \WP_Error('dk2_checkout_release_missing',__('This product has no published download release yet.','dropkey-v2'),array('status'=>409));
		}
		if ('free'===$gateway) {
			$completed=$this->complete_order($order,array());
			if (is_wp_error($completed)) return $completed;
			return array('order_id'=>$public_id,'status'=>'completed','checkout_url'=>add_query_arg('dk2_order',$public_id,$success_url));
		}
		$success_url=wp_validate_redirect($success_url,home_url('/')); $cancel_url=wp_validate_redirect($cancel_url,home_url('/'));
		$success_url=add_query_arg('dk2_order',$public_id,$success_url);
		$session=$instance->create_checkout($order,$billing,$product,$tier,$success_url,$cancel_url);
		if (is_wp_error($session)) { $wpdb->update(Database::table('orders'),array('status'=>'failed','updated_at'=>current_time('mysql',true)),array('id'=>$order->id)); return $session; }
		$wpdb->update(Database::table('orders'),array('gateway_order_id'=>$session['order_id'],'gateway_session_id'=>$session['session_id'],'updated_at'=>current_time('mysql',true)),array('id'=>$order->id));
		return array('order_id'=>$public_id,'status'=>'pending','checkout_url'=>$session['checkout_url']);
	}
	private function queue_fulfillment_email($order,$license_id,$license_key) {
		global $wpdb;
		$release=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Database::table('releases')." WHERE product_id=%d AND status='published' ORDER BY created_at DESC,id DESC LIMIT 1",$order->product_id));
		if (!$release) return new \WP_Error('dk2_fulfillment_release_missing',__('No published download release is available for this order.','dropkey-v2'));
		$link=(new Download_Service())->create_token($license_id,$release->id);
		if (is_wp_error($link)) return $link;
		$product_name=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Database::table('products').' WHERE id=%d',$order->product_id));
		$body="Thank you for your purchase.\n\nProduct: ".$product_name."\nLicense key: ".$license_key."\nDownload: ".$link['url']."\nThis download link expires in 48 hours and allows up to 5 downloads.\n";
		$queued=(new Email_Service())->enqueue($order->id,$order->customer_email,'Your DropKey purchase',$body);
		if (is_wp_error($queued)) { do_action('dk2_fulfillment_email_queue_error',(int)$order->id,$queued->get_error_code()); return $queued; }
		return $queued;
	}
	public function complete_order($order,$event=array()) {
		global $wpdb;
		$orders=Database::table('orders');
		$wpdb->query('START TRANSACTION');
		$locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d FOR UPDATE",$order->id));
		if (!$locked) { $wpdb->query('ROLLBACK'); return new \WP_Error('dk2_order_not_found',__('The checkout order was not found.','dropkey-v2'),array('status'=>404)); }
		if ('paid'===$locked->status && $locked->license_id) { $wpdb->query('COMMIT'); return $locked; }
		$wpdb->update($orders,array('status'=>'processing','updated_at'=>current_time('mysql',true)),array('id'=>$locked->id));
		$license=(new License_Service())->issue($locked->product_id,$locked->tier_id,$locked->customer_email,$locked->billing_option_id,'');
		if (is_wp_error($license)) { $wpdb->query('ROLLBACK'); return $license; }
		$now=current_time('mysql',true);
		$updated=$wpdb->update($orders,array('status'=>'paid','license_id'=>$license['id'],'updated_at'=>$now),array('id'=>$locked->id));
		if (false===$updated) { $wpdb->query('ROLLBACK'); return new \WP_Error('dk2_order_fulfillment_failed',__('The order could not be marked as paid.','dropkey-v2'),array('status'=>500)); }
		$locked->license_id=$license['id']; $locked->status='paid';
		if (!empty($event['gateway_order_id'])) $wpdb->update($orders,array('gateway_order_id'=>$event['gateway_order_id']),array('id'=>$locked->id));
		if (!empty($event['gateway_session_id'])) $wpdb->update($orders,array('gateway_session_id'=>$event['gateway_session_id']),array('id'=>$locked->id));
		if (!empty($event['gateway_payment_id'])) $wpdb->update($orders,array('gateway_payment_id'=>$event['gateway_payment_id']),array('id'=>$locked->id));
		if (!empty($event['subscription_id'])) $this->save_subscription($locked,$license['id'],$event);
		$queued=$this->queue_fulfillment_email($locked,$license['id'],$license['license_key']);
		if (is_wp_error($queued)) { $wpdb->query('ROLLBACK'); return $queued; }
		$wpdb->query('COMMIT');
		do_action('dk2_order_paid',(int)$locked->id,(int)$license['id'],$locked->gateway);
		return $locked;
	}
	private function save_subscription($order,$license_id,$event) {
		global $wpdb; $table=Database::table('subscriptions'); $now=current_time('mysql',true);
		$sub_id=sanitize_text_field($event['subscription_id']);
		$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_subscription_id=%s",$order->gateway,$sub_id));
		$data=array('order_id'=>$order->id,'license_id'=>$license_id,'gateway'=>$order->gateway,'gateway_subscription_id'=>$sub_id,
			'status'=>'active','current_period_end'=>!empty($event['period_end'])?$event['period_end']:null,'grace_until'=>null,'updated_at'=>$now);
		if ($existing) $wpdb->update($table,$data,array('id'=>$existing->id));
		else { $data['created_at']=$now; $wpdb->insert($table,$data); }
		$wpdb->update(Database::table('licenses'),array('status'=>'active','updated_at'=>$now),array('id'=>$license_id));
	}
	private function find_order($gateway,$event) {
		global $wpdb; $table=Database::table('orders'); $order=null;
		if (!empty($event['order_public_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE public_id=%s AND gateway=%s",$event['order_public_id'],$gateway));
		if (!$order&&!empty($event['gateway_order_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_order_id=%s",$gateway,$event['gateway_order_id']));
		if (!$order&&!empty($event['gateway_session_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_session_id=%s",$gateway,$event['gateway_session_id']));
		if (!$order&&!empty($event['gateway_payment_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_payment_id=%s",$gateway,$event['gateway_payment_id']));
		if (!$order&&!empty($event['subscription_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT o.* FROM {$table} o INNER JOIN ".Database::table('subscriptions')." s ON s.order_id=o.id WHERE s.gateway=%s AND s.gateway_subscription_id=%s",$gateway,$event['subscription_id']));
		return $order;
	}
	public function process_webhook($gateway,$event) {
		global $wpdb;
		$events=Database::table('payment_events'); $event_id=sanitize_text_field($event['event_id']); $hash=isset($event['payload_hash'])?$event['payload_hash']:hash('sha256',wp_json_encode($event));
		$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$events} WHERE gateway=%s AND event_id=%s",$gateway,$event_id));
		if ($existing&&'processed'===$existing->status) return array('duplicate'=>true,'processed'=>true);
		if ($existing) $wpdb->update($events,array('status'=>'processing','error_message'=>''),array('id'=>$existing->id));
		else {
			$ok=$wpdb->insert($events,array('gateway'=>$gateway,'event_id'=>$event_id,'event_type'=>sanitize_text_field($event['event_type']),
				'payload_hash'=>$hash,'status'=>'processing','error_message'=>'','created_at'=>current_time('mysql',true)));
			if (!$ok) return array('duplicate'=>true,'processed'=>false);
			$event_row_id=(int)$wpdb->insert_id;
		}
		if ($existing) $event_row_id=(int)$existing->id;
		$result=true; $order=$this->find_order($gateway,$event);
		if ('payment_succeeded'===$event['type']&&$order&&isset($event['amount'])&&null!==$event['amount']&&abs((float)$event['amount']-(float)$order->amount)>0.009) $result=new \WP_Error('dk2_payment_amount_mismatch',__('The payment amount does not match the checkout order.','dropkey-v2'));
		if ('payment_succeeded'===$event['type']&&$order&&!empty($event['currency'])&&strtoupper($event['currency'])!==strtoupper($order->currency)) $result=new \WP_Error('dk2_payment_currency_mismatch',__('The payment currency does not match the checkout order.','dropkey-v2'));
		if (is_wp_error($result)) {
			// A signed provider event with mismatched money values is retained as a failed event for review.
		} elseif ('ignored'===$event['type']) {
			$result=true;
		} elseif ('payment_succeeded'===$event['type']) {
			if (!$order) $result=new \WP_Error('dk2_webhook_order_missing',__('The payment event could not be matched to a DropKey order.','dropkey-v2'));
			else $result=$this->complete_order($order,$event);
			if (!is_wp_error($result)&&$order&&'paid'===$order->status&&!empty($event['subscription_id'])) $this->save_subscription($order,$order->license_id,$event);
		} elseif ('payment_failed'===$event['type']) {
			if ($order&&'paid'!==$order->status) $wpdb->update(Database::table('orders'),array('status'=>'failed','updated_at'=>current_time('mysql',true)),array('id'=>$order->id));
			if ($order&&!empty($event['subscription_id'])) {
				$until=gmdate('Y-m-d H:i:s',time()+3*DAY_IN_SECONDS);
				$wpdb->update(Database::table('subscriptions'),array('status'=>'past_due','grace_until'=>$until,'updated_at'=>current_time('mysql',true)),array('gateway'=>$gateway,'gateway_subscription_id'=>$event['subscription_id']));
			}
		} elseif ('subscription_updated'===$event['type']||'subscription_cancelled'===$event['type']) {
			if (!empty($event['subscription_id'])) {
				$status='subscription_cancelled'===$event['type']?'cancelled':('past_due'===(isset($event['status'])?$event['status']:'')?'past_due':'active');
				$wpdb->update(Database::table('subscriptions'),array('status'=>$status,'current_period_end'=>!empty($event['period_end'])?$event['period_end']:null,'updated_at'=>current_time('mysql',true)),array('gateway'=>$gateway,'gateway_subscription_id'=>$event['subscription_id']));
			}
		} elseif ('refunded'===$event['type']||'chargeback'===$event['type']) {
			if ($order) {
				$wpdb->update(Database::table('orders'),array('status'=>'refunded'===$event['type']?'refunded':'disputed','updated_at'=>current_time('mysql',true)),array('id'=>$order->id));
				if ($order->license_id) $wpdb->update(Database::table('licenses'),array('status'=>'suspended','updated_at'=>current_time('mysql',true)),array('id'=>$order->license_id));
			}
		}
		if (is_wp_error($result)) {
			$wpdb->update($events,array('status'=>'failed','error_message'=>$result->get_error_code()),array('id'=>$event_row_id));
			return $result;
		}
		$wpdb->update($events,array('status'=>'processed','error_message'=>'','processed_at'=>current_time('mysql',true)),array('id'=>$event_row_id));
		do_action('dk2_payment_event_processed',$gateway,$event_id,$event['event_type']);
		return array('processed'=>true,'duplicate'=>false);
	}
	public function capture_paypal($public_id,$paypal_order_id) {
		global $wpdb; $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE public_id=%s AND gateway=%s',$public_id,'paypal'));
		if (!$order) return new \WP_Error('dk2_order_not_found',__('The checkout order was not found.','dropkey-v2'),array('status'=>404));
		$gateway=Payment_Gateway::make('paypal'); if (is_wp_error($gateway)) return $gateway;
		$result=$gateway->capture_order($order,$paypal_order_id); if (is_wp_error($result)) return $result;
		$event=array('gateway_order_id'=>$paypal_order_id,'gateway_payment_id'=>$result['provider_capture_id'],'type'=>'payment_succeeded');
		$completed=$this->complete_order($order,$event); if (is_wp_error($completed)) return $completed;
		return array('order_id'=>$public_id,'status'=>'completed');
	}
	public function process_subscription_lifecycle() {
		global $wpdb; $subs=Database::table('subscriptions'); $licenses=Database::table('licenses'); $now=current_time('mysql',true);
		$expired=$wpdb->get_results($wpdb->prepare("SELECT license_id FROM {$subs} WHERE (status='cancelled' AND current_period_end IS NOT NULL AND current_period_end<=%s) OR (status='past_due' AND grace_until IS NOT NULL AND grace_until<=%s)",$now,$now));
		foreach($expired as $row) $wpdb->update($licenses,array('status'=>'suspended','updated_at'=>$now),array('id'=>$row->license_id,'status'=>'active'));
	}
}
