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
		if (''!==$ip) $keys[]='dk2_checkout_ip_v2_'.hash('sha256',$ip);
		$keys[]='dk2_checkout_email_v2_'.hash('sha256',strtolower($email));
		foreach($keys as $key) if ((int)get_transient($key)>=12) return new \WP_Error('dk2_checkout_rate_limited',__('Too many checkout attempts. Please wait before trying again.','dropkey-v2'),array('status'=>429));
		foreach($keys as $key) set_transient($key,(int)get_transient($key)+1,15*MINUTE_IN_SECONDS);
		return true;
	}
	public function start_checkout($billing_id,$email,$gateway,$success_url,$cancel_url,$customer_details=array()) {
		global $wpdb;
		$billing_id=absint($billing_id); $email=sanitize_email($email); $gateway=sanitize_key($gateway);
		Logger::info('checkout','checkout_started','Checkout attempt started',array('billing_option_id'=>$billing_id,'gateway'=>$gateway));
		if (!is_email($email)) return new \WP_Error('dk2_checkout_email_invalid',__('Enter a valid email address.','dropkey-v2'),array('status'=>400));
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
			if (empty($config['enabled'])||('stripe'===$gateway&&(empty($config['secret'])||empty($config['webhook_secret'])))||('paypal'===$gateway&&(empty($config['client_id'])||empty($config['secret'])||empty($config['webhook_id'])))) return new \WP_Error('dk2_checkout_gateway_disabled',__('That payment method is not currently available.','dropkey-v2'),array('status'=>503));
		}
		$rate_limit=$this->rate_limit_checkout($email); if (is_wp_error($rate_limit)) return $rate_limit;
		$public_id=wp_generate_uuid4(); $now=current_time('mysql',true); $customer_user_id=null;
		if (is_user_logged_in()) { $current_user=wp_get_current_user(); if ($current_user&&strtolower($current_user->user_email)===strtolower($email)) $customer_user_id=(int)$current_user->ID; }
		$ok=$wpdb->insert(Database::table('orders'),array(
			'public_id'=>$public_id,'product_id'=>$billing->product_id,'tier_id'=>$billing->tier_id,'billing_option_id'=>$billing_id,
			'customer_email'=>$email,'customer_user_id'=>$customer_user_id?:null,
			'customer_first_name'=>isset($customer_details['first_name'])?sanitize_text_field($customer_details['first_name']):'', 'customer_last_name'=>isset($customer_details['last_name'])?sanitize_text_field($customer_details['last_name']):'', 'customer_phone'=>isset($customer_details['phone'])?sanitize_text_field($customer_details['phone']):'',
			'billing_address_1'=>isset($customer_details['address_1'])?sanitize_text_field($customer_details['address_1']):'', 'billing_address_2'=>isset($customer_details['address_2'])?sanitize_text_field($customer_details['address_2']):'', 'billing_city'=>isset($customer_details['city'])?sanitize_text_field($customer_details['city']):'',
			'billing_state'=>isset($customer_details['state'])?sanitize_text_field($customer_details['state']):'', 'billing_postcode'=>isset($customer_details['postcode'])?sanitize_text_field($customer_details['postcode']):'', 'billing_country'=>isset($customer_details['country'])?sanitize_text_field($customer_details['country']):'', 'gateway'=>$gateway,'gateway_order_id'=>'','gateway_session_id'=>'',
			'status'=>'pending','amount'=>(float)$billing->price,'currency'=>strtoupper($billing->currency?$billing->currency:'USD'),
			'created_at'=>$now,'updated_at'=>$now
		));
		if (!$ok) {
			$db_error=substr((string)$wpdb->last_error,0,500);
			$attempted_columns='public_id,product_id,tier_id,billing_option_id,customer_email,customer_user_id,customer_first_name,customer_last_name,customer_phone,billing_address_1,billing_address_2,billing_city,billing_state,billing_postcode,billing_country,gateway,gateway_order_id,gateway_session_id,status,amount,currency,created_at,updated_at';
			Logger::error('checkout','order_insert_failed','Checkout order insert failed',array('billing_option_id'=>$billing_id,'gateway'=>$gateway,'db_error'=>$db_error,'attempted_columns'=>$attempted_columns));
			error_log('[DropKey V2] Checkout order insert failed: '.wp_json_encode(array('billing_option_id'=>$billing_id,'gateway'=>$gateway,'db_error'=>$db_error,'attempted_columns'=>$attempted_columns)));
			return new \WP_Error('dk2_checkout_order_failed',__('The checkout order could not be created. The database schema may need an upgrade. Please contact the site administrator.','dropkey-v2'),array('status'=>500));
		}
		$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE id=%d',$wpdb->insert_id));
		$latest_release=$wpdb->get_var($wpdb->prepare("SELECT id FROM ".Database::table('releases')." WHERE product_id=%d AND status='published' ORDER BY created_at DESC,id DESC LIMIT 1",$billing->product_id));
		if (!$latest_release) {
			Logger::warning('checkout','published_release_missing','Checkout blocked because the product has no published release',array('order_id'=>(int)$order->id,'product_id'=>(int)$billing->product_id));
			$wpdb->delete(Database::table('orders'),array('id'=>$order->id),array('%d'));
			return new \WP_Error('dk2_checkout_release_missing',__('This product has no published download release yet.','dropkey-v2'),array('status'=>409));
		}
		if ('free'===$gateway) {
			$completed=$this->complete_order($order,array());
			if (is_wp_error($completed)) return $completed;
			return array('order_id'=>$public_id,'status'=>'completed','checkout_url'=>add_query_arg('dk2_order',$public_id,$success_url));
		}
		$return_url_fallback_used=false;
		$success_url=wp_validate_redirect($success_url,home_url('/')); $cancel_url=wp_validate_redirect($cancel_url,home_url('/'));
		$success_url=esc_url_raw(wp_sanitize_redirect(add_query_arg('dk2_order',$public_id,$success_url)),array('http','https'));
		$cancel_url=esc_url_raw(wp_sanitize_redirect($cancel_url),array('http','https'));
		$valid_url=function($url) {
			$parts=wp_parse_url($url);
			return is_array($parts)&&!empty($parts['host'])&&isset($parts['scheme'])&&in_array(strtolower($parts['scheme']),array('http','https'),true);
		};
		if (!$valid_url($success_url)||!$valid_url($cancel_url)) {
			// If the HTTP referer is malformed or proxy-rewritten, use the canonical site URL.
			$fallback_url=esc_url_raw(home_url('/'),array('http','https'));
			$fallback_success=esc_url_raw(wp_sanitize_redirect(add_query_arg('dk2_order',$public_id,$fallback_url)),array('http','https'));
			$fallback_cancel=esc_url_raw(wp_sanitize_redirect($fallback_url),array('http','https'));
			if ($valid_url($fallback_success)&&$valid_url($fallback_cancel)) {
				$success_url=$fallback_success;
				$cancel_url=$fallback_cancel;
				$return_url_fallback_used=true;
				error_log('[DropKey V2] Checkout return URL fallback used; the referer URL failed URL parsing.');
				Logger::warning('checkout','return_url_fallback_used','Referer-derived checkout URLs failed parsing; canonical home URL used');
			} else {
				$parts=wp_parse_url($fallback_url);
				error_log('[DropKey V2] Checkout canonical URL invalid: '.wp_json_encode(array('scheme'=>is_array($parts)&&isset($parts['scheme'])?strtolower($parts['scheme']):'missing','host_present'=>is_array($parts)&&!empty($parts['host']),'url_length'=>strlen($fallback_url))));
				Logger::error('checkout','canonical_url_invalid','The canonical site URL failed checkout URL validation',array('scheme'=>is_array($parts)&&isset($parts['scheme'])?strtolower($parts['scheme']):'missing','host_present'=>is_array($parts)&&!empty($parts['host']),'url_length'=>strlen($fallback_url)));
				return new \WP_Error('dk2_checkout_return_url_invalid',__('The checkout return URL is invalid. Please contact the site administrator.','dropkey-v2'),array('status'=>500));
			}
		}
		Logger::info('checkout','checkout_urls_validated','Final checkout return and cancel URLs validated',array(
			'return_url_valid'=>$valid_url($success_url),
			'cancel_url_valid'=>$valid_url($cancel_url),
			'return_url_fallback_used'=>$return_url_fallback_used
		));
		$session=$instance->create_checkout($order,$billing,$product,$tier,$success_url,$cancel_url);
		if (is_wp_error($session)) { Logger::error('checkout','gateway_checkout_failed','Payment gateway could not create a checkout session',array('order_id'=>(int)$order->id,'gateway'=>$gateway,'error_code'=>$session->get_error_code())); $wpdb->update(Database::table('orders'),array('status'=>'failed','updated_at'=>current_time('mysql',true)),array('id'=>$order->id)); return $session; }
		$wpdb->update(Database::table('orders'),array('gateway_order_id'=>$session['order_id'],'gateway_session_id'=>$session['session_id'],'updated_at'=>current_time('mysql',true)),array('id'=>$order->id));
		Logger::info('checkout','checkout_session_created','Hosted checkout session created',array('order_id'=>(int)$order->id,'gateway'=>$gateway,'billing_type'=>$billing->billing_type));
		return array('order_id'=>$public_id,'status'=>'pending','checkout_url'=>$session['checkout_url']);
	}
	private function queue_fulfillment_email($order,$license_id,$license_key) {
		global $wpdb;
		$release=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Database::table('releases')." WHERE product_id=%d AND status='published' ORDER BY created_at DESC,id DESC LIMIT 1",$order->product_id));
		$product_name=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Database::table('products').' WHERE id=%d',$order->product_id));
		$tier_name=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Database::table('tiers').' WHERE id=%d',$order->tier_id));
		$billing=$wpdb->get_row($wpdb->prepare('SELECT billing_type FROM '.Database::table('billing_options').' WHERE id=%d',$order->billing_option_id));
		$download_line='';
		if ($release) {
			$link=(new Download_Service())->create_token($license_id,$release->id);
			if (!is_wp_error($link)) $download_line="\nDownload: ".$link['url']."\nThis download link expires in 48 hours and allows up to 5 downloads.\n";
			else Logger::warning('email','download_link_unavailable','Purchase email will be sent without a download link',array('order_id'=>(int)$order->id,'error_code'=>$link->get_error_code()));
		}
		$billing_label=$billing?('monthly'===$billing->billing_type?'Monthly':('yearly'===$billing->billing_type?'Yearly':('free'===$billing->billing_type?'Free':'One-time'))):'—';
		$amount=$billing&&'free'===$billing->billing_type?'Free':strtoupper((string)$order->currency).' '.number_format((float)$order->amount,2);
		$body="Thank you for your purchase.\n\nOrder: ".(string)$order->public_id."\nStatus: Paid\nProduct: ".$product_name."\nPlan: ".$tier_name."\nBilling: ".$billing_label."\nTotal: ".$amount."\nLicense key: ".$license_key."\n".$download_line."\nManage your account: ".wp_login_url()."\nIf you have not set a password yet, use the password reset link on the sign-in page to set one.\n";
		$email_service=new Email_Service(); $queue=Database::table('email_queue');
		$existing=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$queue} WHERE order_id=%d AND subject=%s ORDER BY id DESC LIMIT 1",(int)$order->id,'Your DropKey order confirmation'));
		if ($existing) {
			if ('sent'===$existing->status) return (int)$existing->id;
			if ('failed'===$existing->status) $email_service->resend((int)$existing->id);
			$email_service->process_queue();
			return (int)$existing->id;
		}
		$queued=$email_service->enqueue($order->id,$order->customer_email,'Your DropKey order confirmation',$body);
		if (is_wp_error($queued)) { do_action('dk2_fulfillment_email_queue_error',(int)$order->id,$queued->get_error_code()); return $queued; }
		// Attempt delivery immediately; scheduled retries remain available if wp_mail fails.
		$email_service->process_queue();
		return $queued;
	}
	private function ensure_customer_account($order) {
		global $wpdb;
		$email=sanitize_email($order->customer_email);
		if (!is_email($email)) return new \WP_Error('dk2_customer_email_invalid',__('A valid customer email is required to create the account.','dropkey-v2'));
		$user=get_user_by('email',$email);
		$created=false;
		if (!$user) {
			$base=sanitize_user(current(explode('@',$email)),true); if (''===$base) $base='customer';
			$username=$base; $suffix=1; while (username_exists($username)) { $username=$base.$suffix; $suffix++; }
			$user_id=wp_insert_user(array('user_login'=>$username,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$email,'first_name'=>sanitize_text_field($order->customer_first_name),'last_name'=>sanitize_text_field($order->customer_last_name),'display_name'=>trim($order->customer_first_name.' '.$order->customer_last_name)?:$username,'role'=>'subscriber'));
			if (is_wp_error($user_id)) return $user_id;
			$user=get_user_by('id',$user_id); $created=true;
		} else $user_id=(int)$user->ID;
		$orders=Database::table('orders');
		$wpdb->update($orders,array('customer_user_id'=>(int)$user_id,'updated_at'=>current_time('mysql',true)),array('id'=>(int)$order->id));
		if (!empty($order->license_id)) $wpdb->update(Database::table('licenses'),array('customer_user_id'=>(int)$user_id,'updated_at'=>current_time('mysql',true)),array('id'=>(int)$order->license_id));
		$order->customer_user_id=(int)$user_id;
		if ($created) {
			if (function_exists('wp_new_user_notification')) wp_new_user_notification((int)$user_id,null,'user');
			Logger::info('customer','account_created','Customer account created after order fulfillment began',array('order_id'=>(int)$order->id,'user_id'=>(int)$user_id));
		}
		return (int)$user_id;
	}
	public function complete_order($order,$event=array()) {
		global $wpdb;
		$account=$this->ensure_customer_account($order);
		if (is_wp_error($account)) { Logger::error('customer','account_creation_failed','Could not link customer account to order',array('order_id'=>(int)$order->id,'error_code'=>$account->get_error_code())); return $account; }
		$orders=Database::table('orders');
		$wpdb->query('START TRANSACTION');
		$locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d FOR UPDATE",$order->id));
		if (!$locked) { $wpdb->query('ROLLBACK'); Logger::error('commerce','fulfillment_order_missing','The order disappeared before fulfillment',array('order_id'=>(int)$order->id)); return new \WP_Error('dk2_order_not_found',__('The checkout order was not found.','dropkey-v2'),array('status'=>404)); }
		if ('paid'===$locked->status && $locked->license_id) {
			$wpdb->query('COMMIT');
			$existing_license=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('licenses').' WHERE id=%d',(int)$locked->license_id));
			if ($existing_license) { $existing_key=(new License_Service())->reveal_key($existing_license); if ($existing_key) { $mail_result=$this->queue_fulfillment_email($locked,(int)$existing_license->id,$existing_key); if (is_wp_error($mail_result)) Logger::error('commerce','fulfillment_email_retry_failed','Paid order email could not be queued or retried',array('order_id'=>(int)$locked->id,'error_code'=>$mail_result->get_error_code())); } }
			return $locked;
		}
		$billing_type=$wpdb->get_var($wpdb->prepare('SELECT billing_type FROM '.Database::table('billing_options').' WHERE id=%d',(int)$locked->billing_option_id));
		if (in_array($billing_type,array('monthly','yearly'),true) && empty($event['subscription_id'])) {
			$wpdb->query('ROLLBACK');
			Logger::error('commerce','subscription_reference_missing','Recurring order fulfillment stopped because the verified event did not identify its subscription',array('order_id'=>(int)$locked->id,'billing_type'=>$billing_type,'gateway'=>$locked->gateway));
			return new \WP_Error('dk2_subscription_reference_missing',__('The payment was received, but the subscription could not be verified yet. Please contact support before retrying.','dropkey-v2'),array('status'=>409));
		}
		$wpdb->update($orders,array('status'=>'processing','updated_at'=>current_time('mysql',true)),array('id'=>$locked->id));
		$license=(new License_Service())->issue($locked->product_id,$locked->tier_id,$locked->customer_email,$locked->billing_option_id,'',(int)$locked->customer_user_id);
		if (is_wp_error($license)) { $wpdb->query('ROLLBACK'); Logger::error('commerce','license_issuance_failed','Payment fulfillment could not issue a license',array('order_id'=>(int)$locked->id,'error_code'=>$license->get_error_code(),'db_error'=>substr((string)$wpdb->last_error,0,250))); return $license; }
		$now=current_time('mysql',true);
		$updated=$wpdb->update($orders,array('status'=>'paid','license_id'=>$license['id'],'updated_at'=>$now),array('id'=>$locked->id));
		if (false===$updated) { $db_error=substr((string)$wpdb->last_error,0,250); $wpdb->query('ROLLBACK'); Logger::error('commerce','order_paid_update_failed','License was issued but the order paid-state update failed',array('order_id'=>(int)$locked->id,'db_error'=>$db_error)); return new \WP_Error('dk2_order_fulfillment_failed',__('The order could not be marked as paid.','dropkey-v2'),array('status'=>500)); }
		$locked->license_id=$license['id']; $locked->status='paid';
		if (!empty($event['gateway_order_id'])) $wpdb->update($orders,array('gateway_order_id'=>$event['gateway_order_id']),array('id'=>$locked->id));
		if (!empty($event['gateway_session_id'])) $wpdb->update($orders,array('gateway_session_id'=>$event['gateway_session_id']),array('id'=>$locked->id));
		if (!empty($event['gateway_payment_id'])) $wpdb->update($orders,array('gateway_payment_id'=>$event['gateway_payment_id']),array('id'=>$locked->id));
		if (!empty($event['gateway_payment_id'])) { $payment_record=$this->record_payment_reference($locked,$event); if (is_wp_error($payment_record)) { $wpdb->query('ROLLBACK'); Logger::error('commerce','payment_record_failed','Payment was verified but its local payment record could not be saved',array('order_id'=>(int)$locked->id,'error_code'=>$payment_record->get_error_code(),'db_error'=>substr((string)$wpdb->last_error,0,250))); return $payment_record; } }
		if (!empty($event['subscription_id'])) $this->save_subscription($locked,$license['id'],$event);
		$wpdb->query('COMMIT');
		Logger::info('commerce','order_fulfilled','Order payment confirmed and license issued',array('order_id'=>(int)$locked->id,'license_id'=>(int)$license['id'],'gateway'=>$locked->gateway));
		do_action('dk2_order_paid',(int)$locked->id,(int)$license['id'],$locked->gateway);
		// Email delivery is post-commit: a queue failure must not undo verified payment or license issuance.
		$queued=$this->queue_fulfillment_email($locked,$license['id'],$license['license_key']);
		if (is_wp_error($queued)) {
			Logger::error('commerce','fulfillment_email_queue_failed','Order is paid and licensed, but the delivery email could not be queued',array('order_id'=>(int)$locked->id,'license_id'=>(int)$license['id'],'error_code'=>$queued->get_error_code(),'db_error'=>substr((string)$wpdb->last_error,0,250)));
			do_action('dk2_fulfillment_email_queue_error',(int)$locked->id,$queued->get_error_code());
		}
		return $locked;
	}
	private function normalize_subscription_status($status,$event_type='') {
		if ('subscription_cancelled'===$event_type) return 'cancelled';
		$status=strtolower(sanitize_key((string)$status));
		if (in_array($status,array('active','approved','trialing'),true)) return 'active';
		if (in_array($status,array('past_due','suspended','unpaid'),true)) return 'past_due';
		if (in_array($status,array('cancelled','canceled','expired'),true)) return 'cancelled';
		if ('cancel_pending'===$status) return 'cancel_pending';
		if (''===$status && 'payment_succeeded'===$event_type) return 'active';
		return 'inactive';
	}
	private function save_subscription($order,$license_id,$event) {
		global $wpdb;
		$table=Database::table('subscriptions');
		$now=current_time('mysql',true);
		$sub_id=sanitize_text_field($event['subscription_id']);
		$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_subscription_id=%s",$order->gateway,$sub_id));
		$period_end='';
		if (!empty($event['period_end'])) {
			$stamp=strtotime((string)$event['period_end'].' UTC');
			if ($stamp) $period_end=gmdate('Y-m-d H:i:s',$stamp);
		}
		if (''===$period_end) {
			$billing_type=$wpdb->get_var($wpdb->prepare('SELECT billing_type FROM '.Database::table('billing_options').' WHERE id=%d',(int)$order->billing_option_id));
			if (in_array($billing_type,array('monthly','yearly'),true)) {
				$base=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
				$period_end=$base->modify('monthly'===$billing_type?'+1 month':'+1 year')->format('Y-m-d H:i:s');
				Logger::warning('commerce','subscription_period_estimated','Provider did not supply a billing period end; a temporary period was estimated from the plan interval',array('order_id'=>(int)$order->id,'license_id'=>(int)$license_id,'billing_type'=>$billing_type));
			}
		}
		$status_raw=isset($event['status'])?sanitize_key($event['status']):'';
		if (isset($event['type'])&&'payment_succeeded'===$event['type']&&!in_array($status_raw,array('active','approved','trialing','past_due','suspended','unpaid','cancelled','canceled','expired','cancel_pending'),true)) $status_raw='active';
		$data=array(
			'order_id'=>$existing?(int)$existing->order_id:(int)$order->id,
			'license_id'=>(int)$license_id,
			'gateway'=>$order->gateway,
			'gateway_subscription_id'=>$sub_id,
			'status'=>$this->normalize_subscription_status($status_raw,isset($event['type'])?$event['type']:'payment_succeeded'),
			'grace_until'=>null,
			'updated_at'=>$now
		);
		if (''!==$period_end) $data['current_period_end']=$period_end;
		if (isset($event['cancel_at_period_end'])) $data['cancel_at_period_end']=absint($event['cancel_at_period_end']);
		if ($existing) $wpdb->update($table,$data,array('id'=>$existing->id));
		else {
			if (!isset($data['current_period_end'])) $data['current_period_end']=null;
			if (!isset($data['cancel_at_period_end'])) $data['cancel_at_period_end']=0;
			$data['created_at']=$now;
			$wpdb->insert($table,$data);
		}
		$license_data=array('status'=>'active','updated_at'=>$now);
		if (''!==$period_end) $license_data['expires_at']=$period_end;
		$wpdb->update(Database::table('licenses'),$license_data,array('id'=>(int)$license_id));
	}
	private function record_payment_reference($order,$event) {
		global $wpdb;
		if (empty($event['gateway_payment_id'])) return true;
		$table=Database::table('payments'); $gateway=sanitize_key($order->gateway); $payment_id=sanitize_text_field($event['gateway_payment_id']);
		$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_payment_id=%s",$gateway,$payment_id));
		$data=array('order_id'=>(int)$order->id,'gateway'=>$gateway,'gateway_payment_id'=>$payment_id,
			'provider_event_id'=>isset($event['event_id'])?sanitize_text_field($event['event_id']):'',
			'amount'=>isset($event['amount'])&&null!==$event['amount']?(float)$event['amount']:(float)$order->amount,
			'currency'=>!empty($event['currency'])?strtoupper($event['currency']):strtoupper($order->currency),
			'status'=>'paid','updated_at'=>current_time('mysql',true));
		if ($existing) return false===$wpdb->update($table,$data,array('id'=>$existing->id))?new \WP_Error('dk2_payment_record_failed',__('Payment history could not be updated.','dropkey-v2')):true;
		$data['created_at']=current_time('mysql',true);
		return $wpdb->insert($table,$data)?true:new \WP_Error('dk2_payment_record_failed',__('Payment history could not be saved.','dropkey-v2'));
	}
	private function find_order($gateway,$event) {
		global $wpdb; $table=Database::table('orders'); $order=null;
		if (!empty($event['order_public_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE public_id=%s AND gateway=%s",$event['order_public_id'],$gateway));
		if (!$order&&!empty($event['gateway_order_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_order_id=%s",$gateway,$event['gateway_order_id']));
		if (!$order&&!empty($event['gateway_session_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway=%s AND gateway_session_id=%s",$gateway,$event['gateway_session_id']));
		if (!$order&&!empty($event['gateway_payment_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT o.* FROM {$table} o LEFT JOIN ".Database::table('payments')." pay ON pay.order_id=o.id WHERE o.gateway=%s AND (o.gateway_payment_id=%s OR pay.gateway_payment_id=%s) LIMIT 1",$gateway,$event['gateway_payment_id'],$event['gateway_payment_id']));
		if (!$order&&!empty($event['subscription_id'])) $order=$wpdb->get_row($wpdb->prepare("SELECT o.* FROM {$table} o INNER JOIN ".Database::table('subscriptions')." s ON s.order_id=o.id WHERE s.gateway=%s AND s.gateway_subscription_id=%s",$gateway,$event['subscription_id']));
		return $order;
	}

	/**
	 * Create or recover one order for a verified recurring payment.
	 * Renewals reuse the original license and subscription; they never issue another license.
	 */
	private function create_renewal_order($subscription,$event) {
		global $wpdb;
		if (empty($event['gateway_payment_id'])||!isset($event['amount'])||null===$event['amount']||empty($event['currency'])) return new \WP_Error('dk2_renewal_payment_reference_missing',__('The recurring payment is verified but lacks a payment ID, amount, or currency required to record a renewal safely.','dropkey-v2'));
		$gateway=sanitize_key($subscription->gateway); $payment_id=sanitize_text_field($event['gateway_payment_id']); $renewal_key=hash('sha256',$gateway.'|'.$payment_id);
		$orders=Database::table('orders'); $payments=Database::table('payments');
		$base=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d",(int)$subscription->order_id));
		if ($base && 'paid'===$base->status && (string)$base->gateway_payment_id===$payment_id) return $base;
		$known_payment=$wpdb->get_row($wpdb->prepare("SELECT order_id FROM {$payments} WHERE gateway=%s AND gateway_payment_id=%s",$gateway,$payment_id));
		if ($known_payment) {
			$known_order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d",(int)$known_payment->order_id));
			if ($known_order && (int)$known_order->id===(int)$subscription->order_id) return $known_order;
			if ($known_order && 'renewal'===$known_order->order_type && (int)$known_order->subscription_id===(int)$subscription->id) return $known_order;
			return new \WP_Error('dk2_renewal_payment_already_linked',__('This provider payment is already linked to a different DropKey order.','dropkey-v2'));
		}
		$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE renewal_key=%s AND order_type='renewal'",$renewal_key));
		if ($existing) return (int)$existing->subscription_id===(int)$subscription->id?$existing:new \WP_Error('dk2_renewal_payment_already_linked',__('This provider payment is already linked to a different DropKey renewal.','dropkey-v2'));
		if (!$base || empty($subscription->license_id) || 'paid'!==$base->status) return new \WP_Error('dk2_renewal_parent_order_invalid',__('The original paid order or its license could not be found for this renewal.','dropkey-v2'));
		$currency=strtoupper(sanitize_text_field($event['currency'])); $amount=(float)$event['amount'];
		if (!preg_match('/^[A-Z]{3}$/',$currency)||$amount<0) return new \WP_Error('dk2_renewal_amount_invalid',__('The recurring payment amount or currency is invalid.','dropkey-v2'));
		if (strtoupper((string)$base->currency)!==$currency) return new \WP_Error('dk2_renewal_currency_mismatch',__('The recurring payment currency does not match the original subscription currency.','dropkey-v2'));
		$now=current_time('mysql',true);
		$data=array(
			'public_id'=>wp_generate_uuid4(),'product_id'=>(int)$base->product_id,'tier_id'=>(int)$base->tier_id,'billing_option_id'=>(int)$base->billing_option_id,
			'license_id'=>(int)$subscription->license_id,'parent_order_id'=>(int)$base->id,'subscription_id'=>(int)$subscription->id,'order_type'=>'renewal','renewal_key'=>$renewal_key,
			'customer_email'=>$base->customer_email,'customer_user_id'=>$base->customer_user_id,'customer_first_name'=>$base->customer_first_name,'customer_last_name'=>$base->customer_last_name,
			'customer_phone'=>$base->customer_phone,'billing_address_1'=>$base->billing_address_1,'billing_address_2'=>$base->billing_address_2,'billing_city'=>$base->billing_city,
			'billing_state'=>$base->billing_state,'billing_postcode'=>$base->billing_postcode,'billing_country'=>$base->billing_country,'gateway'=>$gateway,
			'gateway_order_id'=>isset($event['gateway_order_id'])?sanitize_text_field($event['gateway_order_id']):'',
			'gateway_session_id'=>isset($event['gateway_session_id'])?sanitize_text_field($event['gateway_session_id']):'',
			'gateway_payment_id'=>$payment_id,'status'=>'paid','amount'=>$amount,'currency'=>$currency,'created_at'=>$now,'updated_at'=>$now
		);
		if (!$wpdb->insert($orders,$data)) {
			$retry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE renewal_key=%s AND order_type='renewal'",$renewal_key));
			if ($retry && (int)$retry->subscription_id===(int)$subscription->id) return $retry;
			Logger::error('commerce','renewal_order_insert_failed','Could not create a renewal order for a verified provider payment',array('subscription_id'=>(int)$subscription->id,'gateway'=>$gateway,'db_error'=>substr((string)$wpdb->last_error,0,250)));
			return new \WP_Error('dk2_renewal_order_failed',__('The recurring payment could not be recorded as a renewal order.','dropkey-v2'));
		}
		$renewal=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d",(int)$wpdb->insert_id));
		Logger::info('commerce','renewal_order_created','A verified recurring payment was recorded as a renewal order',array('order_id'=>(int)$renewal->id,'parent_order_id'=>(int)$base->id,'subscription_id'=>(int)$subscription->id,'license_id'=>(int)$subscription->license_id,'gateway'=>$gateway,'amount'=>$amount,'currency'=>$currency));
		return $renewal;
	}
	public function process_webhook($gateway,$event) {
		global $wpdb;
		$events=Database::table('payment_events'); $event_id=sanitize_text_field($event['event_id']); $hash=isset($event['payload_hash'])?$event['payload_hash']:hash('sha256',wp_json_encode($event));
		$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$events} WHERE gateway=%s AND event_id=%s",$gateway,$event_id));
		if ($existing&&'processed'===$existing->status) return array('duplicate'=>true,'processed'=>true);
		if ($existing&&'processing'===$existing->status&&strtotime($existing->created_at.' UTC')>time()-600) return new \WP_Error('dk2_webhook_in_progress',__('This provider event is already being processed. Retry delivery shortly.','dropkey-v2'),array('status'=>503));
		if ($existing) $wpdb->update($events,array('status'=>'processing','error_message'=>''),array('id'=>$existing->id));
		else {
			$ok=$wpdb->insert($events,array('gateway'=>$gateway,'event_id'=>$event_id,'event_type'=>sanitize_text_field($event['event_type']),
				'payload_hash'=>$hash,'status'=>'processing','error_message'=>'','created_at'=>current_time('mysql',true)));
			if (!$ok) {
				$duplicate=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$events} WHERE gateway=%s AND event_id=%s",$gateway,$event_id));
				if ($duplicate) return new \WP_Error('dk2_webhook_in_progress',__('This provider event is already being processed. Retry delivery shortly.','dropkey-v2'),array('status'=>503));
				return new \WP_Error('dk2_webhook_store_failed',__('The payment event could not be recorded.','dropkey-v2'),array('status'=>500));
			}
			$event_row_id=(int)$wpdb->insert_id;
		}
		if ($existing) $event_row_id=(int)$existing->id;
		if (!empty($event['subscription_id'])&&empty($event['period_end'])&&in_array($event['type'],array('payment_succeeded','subscription_updated'),true)) {
			$provider=Payment_Gateway::make($gateway);
			if (!is_wp_error($provider)&&method_exists($provider,'subscription_details')) {
				$details=$provider->subscription_details($event['subscription_id']);
				if (!is_wp_error($details)) foreach($details as $key=>$value) if (''!==$value&&null!==$value) $event[$key]=$value;
			}
		}
		$result=true; $order=$this->find_order($gateway,$event); $is_renewal=false;
		if ('payment_succeeded'===$event['type']&&!empty($event['subscription_id'])) {
			$subscription=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Database::table('subscriptions')." WHERE gateway=%s AND gateway_subscription_id=%s",$gateway,$event['subscription_id']));
			if ($subscription) { $renewal=$this->create_renewal_order($subscription,$event); if (is_wp_error($renewal)) $result=$renewal; else { $order=$renewal; $is_renewal=('renewal'===$renewal->order_type); } }
		}
		if (!is_wp_error($result)&&'payment_succeeded'===$event['type']&&!$is_renewal&&$order&&isset($event['amount'])&&null!==$event['amount']&&abs((float)$event['amount']-(float)$order->amount)>0.009) $result=new \WP_Error('dk2_payment_amount_mismatch',__('The payment amount does not match the checkout order.','dropkey-v2'));
		if (!is_wp_error($result)&&'payment_succeeded'===$event['type']&&!$is_renewal&&$order&&!empty($event['currency'])&&strtoupper($event['currency'])!==strtoupper($order->currency)) $result=new \WP_Error('dk2_payment_currency_mismatch',__('The payment currency does not match the checkout order.','dropkey-v2'));
		if (is_wp_error($result)) {
			// A signed provider event with mismatched money values is retained as a failed event for review.
		} elseif ('ignored'===$event['type']) {
			$result=true;
		} elseif ('payment_succeeded'===$event['type']) {
			if (!$order) $result=new \WP_Error('dk2_webhook_order_missing',__('The payment event could not be matched to a DropKey order.','dropkey-v2'));
			elseif ($is_renewal) $result=$order;
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
				$subs=Database::table('subscriptions');
				$subscription=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$subs} WHERE gateway=%s AND gateway_subscription_id=%s",$gateway,$event['subscription_id']));
				$status=$this->normalize_subscription_status(isset($event['status'])?$event['status']:'',$event['type']);
				$update=array('status'=>$status,'updated_at'=>current_time('mysql',true));
				$period_end='';
				if (!empty($event['period_end'])) {
					$stamp=strtotime((string)$event['period_end'].' UTC');
					if ($stamp) $period_end=gmdate('Y-m-d H:i:s',$stamp);
				}
				if (''!==$period_end) $update['current_period_end']=$period_end;
				if (isset($event['cancel_at_period_end'])) $update['cancel_at_period_end']=absint($event['cancel_at_period_end']);
				$wpdb->update($subs,$update,array('gateway'=>$gateway,'gateway_subscription_id'=>$event['subscription_id']));
				if ($subscription) {
					$license_update=array('updated_at'=>current_time('mysql',true));
					if (''!==$period_end) $license_update['expires_at']=$period_end;
					if ('past_due'===$status && empty($subscription->grace_until)) $license_update['status']='active';
					$wpdb->update(Database::table('licenses'),$license_update,array('id'=>(int)$subscription->license_id));
				}
			}
		} elseif ('refunded'===$event['type']||'chargeback'===$event['type']) {
			if (!$order) $result=new \WP_Error('dk2_payment_event_order_missing',__('A refund or dispute event could not be matched to an order.','dropkey-v2'),array('status'=>500));
			if ($order) {
				$wpdb->update(Database::table('orders'),array('status'=>'refunded'===$event['type']?'refunded':'disputed','updated_at'=>current_time('mysql',true)),array('id'=>$order->id));
				if ($order->license_id) $wpdb->update(Database::table('licenses'),array('status'=>'suspended','updated_at'=>current_time('mysql',true)),array('id'=>$order->license_id));
			}
		}
		if (is_wp_error($result)) {
			$wpdb->update($events,array('status'=>'failed','error_message'=>$result->get_error_code()),array('id'=>$event_row_id));
			Logger::error('webhook','event_processing_failed','A verified payment event could not be applied to its order',array('gateway'=>$gateway,'event_type'=>isset($event['event_type'])?$event['event_type']:'unknown','event_id'=>$event_id,'error_code'=>$result->get_error_code(),'order_matched'=>(bool)$order,'order_id'=>$order?(int)$order->id:0));
			return $result;
		}
		if ('payment_succeeded'===$event['type']&&$order&&!empty($event['gateway_payment_id'])) {
			$payment_record=$this->record_payment_reference($order,$event);
			if (is_wp_error($payment_record)) { $wpdb->update($events,array('status'=>'failed','error_message'=>$payment_record->get_error_code()),array('id'=>$event_row_id)); return $payment_record; }
		}
		if (in_array($event['type'],array('refunded','chargeback'),true)&&!empty($event['gateway_payment_id'])) $wpdb->update(Database::table('payments'),array('status'=>'refunded'===$event['type']?'refunded':'disputed','updated_at'=>current_time('mysql',true)),array('gateway'=>$gateway,'gateway_payment_id'=>$event['gateway_payment_id']));
		$wpdb->update($events,array('status'=>'processed','error_message'=>'','processed_at'=>current_time('mysql',true)),array('id'=>$event_row_id));
		do_action('dk2_payment_event_processed',$gateway,$event_id,$event['event_type']);
		return array('processed'=>true,'duplicate'=>false);
	}
	public function capture_paypal($public_id,$paypal_order_id) {
		global $wpdb; $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE public_id=%s AND gateway=%s',$public_id,'paypal'));
		if (!$order) return new \WP_Error('dk2_order_not_found',__('The checkout order was not found.','dropkey-v2'),array('status'=>404));
		$billing_type=$wpdb->get_var($wpdb->prepare('SELECT billing_type FROM '.Database::table('billing_options').' WHERE id=%d',(int)$order->billing_option_id));
		if (in_array($billing_type,array('monthly','yearly'),true)) return new \WP_Error('dk2_paypal_subscription_capture_not_supported',__('Recurring PayPal subscriptions are fulfilled only after a verified subscription payment webhook.','dropkey-v2'),array('status'=>409));
		$gateway=Payment_Gateway::make('paypal'); if (is_wp_error($gateway)) return $gateway;
		$result=$gateway->capture_order($order,$paypal_order_id); if (is_wp_error($result)) return $result;
		$event=array('gateway_order_id'=>$paypal_order_id,'gateway_payment_id'=>$result['provider_capture_id'],'type'=>'payment_succeeded');
		$completed=$this->complete_order($order,$event); if (is_wp_error($completed)) return $completed;
		return array('order_id'=>$public_id,'status'=>'completed');
	}
	public function request_cancel_subscription($subscription_id) {
		global $wpdb; $id=absint($subscription_id); $table=Database::table('subscriptions');
		$sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id));
		if (!$sub||!in_array($sub->status,array('active','past_due'),true)) return new \WP_Error('dk2_subscription_not_cancellable',__('This subscription cannot be scheduled for cancellation.','dropkey-v2'),array('status'=>409));
		if (empty($sub->current_period_end)||strtotime($sub->current_period_end.' UTC')<=time()) return new \WP_Error('dk2_subscription_period_missing',__('A future paid-through date is required before cancellation can be scheduled.','dropkey-v2'));
		if ('stripe'===$sub->gateway) {
			$gateway=Payment_Gateway::make('stripe'); $result=$gateway->schedule_cancel($sub->gateway_subscription_id);
			if (is_wp_error($result)) return $result;
		}
		$updated=$wpdb->update($table,array('cancel_at_period_end'=>1,'updated_at'=>current_time('mysql',true)),array('id'=>$id));
		if (false===$updated) return new \WP_Error('dk2_subscription_cancel_failed',__('The cancellation could not be saved.','dropkey-v2'));
		do_action('dk2_subscription_cancel_scheduled',$id,$sub->current_period_end);
		return true;
	}
	public function process_subscription_lifecycle() {
		global $wpdb; $subs=Database::table('subscriptions'); $licenses=Database::table('licenses'); $now=current_time('mysql',true);
		$due=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$subs} WHERE cancel_at_period_end=1 AND current_period_end IS NOT NULL AND current_period_end<=%s AND status IN ('active','past_due','cancel_pending')",$now));
		foreach($due as $sub) {
			if ('paypal'===$sub->gateway) {
				$gateway=Payment_Gateway::make('paypal'); $cancel=$gateway->cancel_subscription_now($sub->gateway_subscription_id);
				if (is_wp_error($cancel)) { do_action('dk2_subscription_cancel_provider_error',(int)$sub->id,$cancel->get_error_code()); $wpdb->update($subs,array('status'=>'cancel_pending','updated_at'=>$now),array('id'=>$sub->id)); $wpdb->update($licenses,array('status'=>'suspended','updated_at'=>$now),array('id'=>$sub->license_id,'status'=>'active')); continue; }
			}
			$wpdb->update($subs,array('status'=>'cancelled','updated_at'=>$now),array('id'=>$sub->id));
		}
		$expired=$wpdb->get_results($wpdb->prepare("SELECT license_id FROM {$subs} WHERE ((status IN ('cancelled','cancel_pending')) AND current_period_end IS NOT NULL AND current_period_end<=%s) OR (status='past_due' AND grace_until IS NOT NULL AND grace_until<=%s)",$now,$now));
		foreach($expired as $row) $wpdb->update($licenses,array('status'=>'suspended','updated_at'=>$now),array('id'=>$row->license_id,'status'=>'active'));
	}
}
