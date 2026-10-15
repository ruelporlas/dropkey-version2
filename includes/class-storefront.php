<?php
/**
 * Public product catalog and hosted checkout shortcode.
 * Add [dropkey_store] to a WordPress page to display published offerings.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Storefront {
	public function register() {
		add_shortcode('dropkey_store',array($this,'render'));
		add_shortcode('dropkey_checkout',array($this,'render_checkout'));
		add_action('admin_post_dk2_public_checkout',array($this,'submit_checkout'));
		add_action('admin_post_nopriv_dk2_public_checkout',array($this,'submit_checkout'));
		add_action('wp_enqueue_scripts',array($this,'enqueue_assets'));
	}
	public function enqueue_assets() {
		if (!is_singular()) return;
		$post=get_post();
		if ($post&&(has_shortcode($post->post_content,'dropkey_store')||has_shortcode($post->post_content,'dropkey_checkout'))) wp_enqueue_style('dropkey-v2-storefront',DK2_URL.'assets/css/storefront.css',array(),DK2_VERSION);
	}
	private function checkout_url() {
		$id=absint(get_option('dk2_checkout_page_id',0));
		if ($id&&'publish'===get_post_status($id)) return get_permalink($id);
		$id=wp_insert_post(array('post_title'=>__('Checkout','dropkey-v2'),'post_name'=>'dropkey-checkout','post_status'=>'publish','post_type'=>'page','post_content'=>'[dropkey_checkout]'),true);
		if (is_wp_error($id)) return home_url('/');
		update_option('dk2_checkout_page_id',(int)$id,false);
		return get_permalink($id);
	}
	private function payment_settings() {
		$settings=get_option('dk2_payment_settings',array());
		return array(
			'stripe'=>!empty($settings['stripe']['enabled'])&&!empty($settings['stripe']['secret'])&&!empty($settings['stripe']['webhook_secret']),
			'paypal'=>!empty($settings['paypal']['enabled'])&&!empty($settings['paypal']['client_id'])&&!empty($settings['paypal']['secret'])&&!empty($settings['paypal']['webhook_id'])
		);
	}
	private function current_order_notice() {
		global $wpdb;
		if (empty($_GET['dk2_order'])) return '';
		$public_id=sanitize_text_field(wp_unslash($_GET['dk2_order']));
		$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE public_id=%s',$public_id));
		if (!$order) return '<div class="dk2-store-notice dk2-store-error">'.esc_html__('We could not find that checkout. Please contact support if you were charged.','dropkey-v2').'</div>';
		if ('paypal'===$order->gateway&&'pending'===$order->status) {
			$billing_type=$wpdb->get_var($wpdb->prepare('SELECT billing_type FROM '.Database::table('billing_options').' WHERE id=%d',(int)$order->billing_option_id));
			if (in_array($billing_type,array('monthly','yearly'),true)) {
				Logger::info('paypal','subscription_return_waiting_for_webhook','PayPal subscription return received; waiting for verified payment webhook',array('order_id'=>(int)$order->id,'billing_type'=>$billing_type));
			} elseif (!empty($_GET['token'])) {
				$captured=(new Commerce_Service())->capture_paypal($public_id,sanitize_text_field(wp_unslash($_GET['token'])));
				if (is_wp_error($captured)) Logger::error('paypal','capture_return_failed','PayPal return could not complete order fulfillment',array('order_id'=>(int)$order->id,'error_code'=>$captured->get_error_code()));
			}
			$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE id=%d',$order->id));
		}
		$product=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Database::table('products').' WHERE id=%d',(int)$order->product_id));
		$tier=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Database::table('tiers').' WHERE id=%d',(int)$order->tier_id));
		$billing=$wpdb->get_row($wpdb->prepare('SELECT billing_type,price,currency FROM '.Database::table('billing_options').' WHERE id=%d',(int)$order->billing_option_id));
		$status_labels=array('paid'=>__('Paid','dropkey-v2'),'pending'=>__('Awaiting payment confirmation','dropkey-v2'),'processing'=>__('Processing','dropkey-v2'),'failed'=>__('Payment failed','dropkey-v2'),'cancelled'=>__('Cancelled','dropkey-v2'));
		$status=isset($status_labels[$order->status])?$status_labels[$order->status]:ucfirst(str_replace('_',' ',$order->status));
		$cadence=$billing?('monthly'===$billing->billing_type?__('Monthly','dropkey-v2'):('yearly'===$billing->billing_type?__('Yearly','dropkey-v2'):('free'===$billing->billing_type?__('Free','dropkey-v2'):__('One-time','dropkey-v2')))):'—';
		$amount=('free'===$billing->billing_type)?__('Free','dropkey-v2'):strtoupper((string)$order->currency).' '.number_format((float)$order->amount,2);
		$html='<section class="dk2-order-confirmation"><div class="dk2-store-notice '.('paid'===$order->status?'dk2-store-success':('failed'===$order->status?'dk2-store-error':'')).'"><strong>'.esc_html('paid'===$order->status?__('Order confirmed','dropkey-v2'):$status).'</strong><span>'.esc_html('paid'===$order->status?__('Your payment is confirmed. Order details are below.','dropkey-v2'):('pending'===$order->status?__('We have recorded your order, but payment is not yet confirmed. Your license will be issued after payment verification.','dropkey-v2'):__('This order has not been completed. If you believe you were charged, please contact support.','dropkey-v2'))).'</span></div>';
		$html.='<h2>'.esc_html__('Order details','dropkey-v2').'</h2><dl class="dk2-order-details">';
		$rows=array(__('Order number','dropkey-v2')=>substr((string)$order->public_id,0,8),__('Status','dropkey-v2')=>$status,__('Plugin','dropkey-v2')=>$product?:__('Plugin','dropkey-v2'),__('Plan','dropkey-v2')=>$tier?:'—',__('Billing','dropkey-v2')=>$cadence,__('Total','dropkey-v2')=>$amount,__('Payment method','dropkey-v2')=>ucfirst((string)$order->gateway),__('Customer','dropkey-v2')=>trim($order->customer_first_name.' '.$order->customer_last_name),__('Email','dropkey-v2')=>$order->customer_email,__('Order date','dropkey-v2')=>get_date_from_gmt($order->created_at,get_option('date_format').' '.get_option('time_format')));
		foreach($rows as $label=>$value) $html.='<div><dt>'.esc_html($label).'</dt><dd>'.esc_html($value!==''?$value:'—').'</dd></div>';
		$html.='</dl>';
		if ('paid'===$order->status&&$order->license_id) {
			$license=$wpdb->get_row($wpdb->prepare('SELECT key_encrypted,status,expires_at FROM '.Database::table('licenses').' WHERE id=%d',(int)$order->license_id));
			if ($license) { $license_key=(new License_Service())->reveal_key($license); $html.='<h3>'.esc_html__('License information','dropkey-v2').'</h3><dl class="dk2-order-details"><div><dt>'.esc_html__('License key','dropkey-v2').'</dt><dd><code>'.esc_html($license_key?:__('Check your email for your license key.','dropkey-v2')).'</code></dd></div><div><dt>'.esc_html__('License status','dropkey-v2').'</dt><dd>'.esc_html(ucfirst($license->status)).'</dd></div><div><dt>'.esc_html__('Expires','dropkey-v2').'</dt><dd>'.esc_html($license->expires_at?:__('No expiration date set','dropkey-v2')).'</dd></div></dl>'; }
			$html.='<p>'.esc_html__('A receipt and account setup instructions will be sent to the email address above.','dropkey-v2').'</p>';
		}
		return $html.'</section>';
	}
	public function render() {
		global $wpdb;
		$products=Database::table('products'); $tiers=Database::table('tiers'); $billing=Database::table('billing_options');
		$settings=$this->payment_settings();
		$rows=$wpdb->get_results("SELECT p.id AS product_id,p.name AS product_name,p.description AS product_description,COUNT(DISTINCT b.id) AS option_count FROM {$products} p INNER JOIN {$tiers} t ON t.product_id=p.id INNER JOIN {$billing} b ON b.tier_id=t.id WHERE p.status='published' AND t.status='published' AND b.status='published' GROUP BY p.id,p.name,p.description ORDER BY p.name");
		ob_start();
		echo '<div class="dk2-store">';
		echo $this->current_order_notice();
		if (!empty($_GET['dk2_checkout_error'])) echo '<div class="dk2-store-notice dk2-store-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['dk2_checkout_error']))).'</div>';
		if (!$rows) echo '<div class="dk2-store-empty">'.esc_html__('No products are available for purchase yet.','dropkey-v2').'</div>';
		foreach($rows as $row) {
			$desc=wp_strip_all_tags($row->product_description);
			echo '<article class="dk2-store-card"><div class="dk2-store-card-top"><div><p class="dk2-store-eyebrow">'.esc_html__('Plugin','dropkey-v2').'</p><h3>'.esc_html($row->product_name).'</h3><p>'.esc_html($desc).'</p><p class="dk2-store-limit">'.sprintf(esc_html__('%d plan and billing option(s) available','dropkey-v2'),(int)$row->option_count).'</p></div></div>';
			echo '<a class="dk2-store-order-button" href="'.esc_url(add_query_arg('product_id',(int)$row->product_id,$this->checkout_url())).'">'.esc_html__('Order now','dropkey-v2').' <span aria-hidden="true">→</span></a></article>';
		}
		echo '</div>';
		return ob_get_clean();
	}
	public function render_checkout() {
		if (!empty($_GET['dk2_order'])) return $this->current_order_notice();
		$id=absint($_GET['billing_option_id']??0); $product_id=absint($_GET['product_id']??0); global $wpdb;
		$b=Database::table('billing_options'); $t=Database::table('tiers'); $p=Database::table('products');
		if (!$product_id && $id) $product_id=(int)$wpdb->get_var($wpdb->prepare("SELECT t.product_id FROM {$b} b INNER JOIN {$t} t ON t.id=b.tier_id WHERE b.id=%d",$id));
		if (!$product_id) return '<div class="dk2-store-notice dk2-store-error">'.esc_html__('Choose a plugin from the store before checking out.','dropkey-v2').'</div>';
		$options=$wpdb->get_results($wpdb->prepare("SELECT b.*,t.id AS tier_id,t.name AS tier_name,t.description AS tier_description,p.name AS product_name,p.description AS product_description FROM {$b} b INNER JOIN {$t} t ON t.id=b.tier_id INNER JOIN {$p} p ON p.id=t.product_id WHERE p.id=%d AND b.status='published' AND t.status='published' AND p.status='published' ORDER BY t.sort_order,t.id,b.id",$product_id));
		if (!$options) return '<div class="dk2-store-notice dk2-store-error">'.esc_html__('This plugin has no available plans.','dropkey-v2').'</div>';
		$selected=null; foreach($options as $option) if ((int)$option->id===$id) $selected=$option; if (!$selected) $selected=$options[0]; $o=$selected;
		$s=$this->payment_settings(); $price='free'===$o->billing_type?__('Free','dropkey-v2'):strtoupper($o->currency).' '.number_format((float)$o->price,2);
		$user=is_user_logged_in()?wp_get_current_user():null;
		ob_start(); echo '<div class="dk2-checkout"><main class="dk2-checkout-main"><p class="dk2-store-eyebrow">'.esc_html__('Secure checkout','dropkey-v2').'</p><h2>'.esc_html__('Checkout','dropkey-v2').'</h2>';
		if (!empty($_GET['dk2_checkout_error'])) echo '<div class="dk2-store-notice dk2-store-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['dk2_checkout_error']))).'</div>';
		echo '<form class="dk2-checkout-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dk2_public_checkout"><input type="hidden" name="return_url" value="'.esc_attr(esc_url_raw($this->checkout_url())).'">'; wp_nonce_field('dk2_public_checkout','dk2_checkout_nonce');
		echo '<h3>'.esc_html__('Choose your plan','dropkey-v2').'</h3><label>'.esc_html__('Plan and billing cycle','dropkey-v2').'<select required name="billing_option_id" id="dk2-billing-option">';
		foreach($options as $option) { $cadence='monthly'===$option->billing_type?__('Monthly','dropkey-v2'):('yearly'===$option->billing_type?__('Yearly','dropkey-v2'):('free'===$option->billing_type?__('Free','dropkey-v2'):__('One-time','dropkey-v2'))); $option_price='free'===$option->billing_type?__('Free','dropkey-v2'):strtoupper($option->currency).' '.number_format((float)$option->price,2); echo '<option value="'.(int)$option->id.'" data-tier="'.esc_attr($option->tier_name).'" data-price="'.esc_attr($option_price).'" data-cadence="'.esc_attr($cadence).'" '.selected((int)$option->id,(int)$o->id,false).'>'.esc_html($option->tier_name.' — '.$cadence.' — '.$option_price).'</option>'; }
		echo '</select></label><h3>'.esc_html__('Contact information','dropkey-v2').'</h3><div class="dk2-field-grid"><label>'.esc_html__('First name','dropkey-v2').'<input required name="first_name" autocomplete="given-name" maxlength="100"></label><label>'.esc_html__('Last name','dropkey-v2').'<input required name="last_name" autocomplete="family-name" maxlength="100"></label><label class="dk2-field-full">'.esc_html__('Email address','dropkey-v2').'<input required type="email" name="customer_email" autocomplete="email" maxlength="191" value="'.esc_attr($user?$user->user_email:'').'"></label><label class="dk2-field-full">'.esc_html__('Phone (optional)','dropkey-v2').'<input name="phone" autocomplete="tel" maxlength="40"></label></div>';
		echo '<h3>'.esc_html__('Billing address','dropkey-v2').'</h3><div class="dk2-field-grid"><label class="dk2-field-full">'.esc_html__('Street address','dropkey-v2').'<input required name="address_1" autocomplete="address-line1" maxlength="191"></label><label class="dk2-field-full">'.esc_html__('Apartment, suite, etc. (optional)','dropkey-v2').'<input name="address_2" autocomplete="address-line2" maxlength="191"></label><label>'.esc_html__('City','dropkey-v2').'<input required name="city" autocomplete="address-level2" maxlength="100"></label><label>'.esc_html__('State / Province','dropkey-v2').'<input required name="state" autocomplete="address-level1" maxlength="100"></label><label>'.esc_html__('Postal code','dropkey-v2').'<input required name="postcode" autocomplete="postal-code" maxlength="30"></label><label>'.esc_html__('Country code (2 letters)','dropkey-v2').'<input required name="country" autocomplete="country" minlength="2" maxlength="2" pattern="[A-Za-z]{2}" placeholder="US"></label></div><h3>'.esc_html__('Payment method','dropkey-v2').'</h3><div class="dk2-payment-options">';
		if ('free'===$o->billing_type) echo '<input type="hidden" name="gateway" value="free"><p>'.esc_html__('No payment is required for this plan.','dropkey-v2').'</p>';
		else { if ($s['paypal']) echo '<label class="dk2-payment-option"><input required type="radio" name="gateway" value="paypal"><span><strong>PayPal</strong><small>'.esc_html__('Pay securely on PayPal, then return here for confirmation.','dropkey-v2').'</small></span></label>'; if ($s['stripe']) echo '<label class="dk2-payment-option"><input required type="radio" name="gateway" value="stripe"><span><strong>'.esc_html__('Credit or debit card','dropkey-v2').'</strong><small>'.esc_html__('Continue to secure Stripe Checkout.','dropkey-v2').'</small></span></label>'; if (!$s['paypal']&&!$s['stripe']) echo '<p class="dk2-store-unavailable">'.esc_html__('Checkout is temporarily unavailable.','dropkey-v2').'</p>'; }
		echo '</div><p class="dk2-checkout-secure">'.esc_html__('Payment details are handled by the payment provider. DropKey never asks for your PayPal password or full card number.','dropkey-v2').'</p><button class="dk2-store-order-button" type="submit" '.(('free'!==$o->billing_type&&!$s['paypal']&&!$s['stripe'])?'disabled':'').'>'.esc_html('free'===$o->billing_type?__('Complete free order','dropkey-v2'):__('Proceed to payment','dropkey-v2')).'</button></form></main><aside class="dk2-order-summary"><h3>'.esc_html__('Order summary','dropkey-v2').'</h3><p>'.esc_html($o->product_name).'</p><strong id="dk2-summary-tier">'.esc_html($o->tier_name).'</strong><p><span id="dk2-summary-price">'.esc_html($price).'</span> <span id="dk2-summary-cadence">'.esc_html($o->billing_type).'</span></p><hr><strong>'.esc_html__('Total due today:','dropkey-v2').' <span id="dk2-summary-total">'.esc_html($price).'</span></strong><p>'.esc_html__('Your license will be delivered by email after payment confirmation.','dropkey-v2').'</p></aside></div>';
		echo '<script>(function(){var s=document.getElementById("dk2-billing-option");if(!s)return;function update(){var o=s.options[s.selectedIndex];if(!o)return;var tier=document.getElementById("dk2-summary-tier"),price=document.getElementById("dk2-summary-price"),total=document.getElementById("dk2-summary-total"),cadence=document.getElementById("dk2-summary-cadence");if(tier)tier.textContent=o.getAttribute("data-tier")||"";if(price)price.textContent=o.getAttribute("data-price")||"";if(total)total.textContent=o.getAttribute("data-price")||"";if(cadence)cadence.textContent=o.getAttribute("data-cadence")||"";}s.addEventListener("change",update);update();})();</script>';
		return ob_get_clean();
	}
	public function submit_checkout() {
		if (empty($_POST['dk2_checkout_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dk2_checkout_nonce'])),'dk2_public_checkout')) wp_die(esc_html__('Your checkout form expired. Refresh the page and try again.','dropkey-v2'),'',array('response'=>403));
		$submitted_return=isset($_POST['return_url'])?esc_url_raw(wp_unslash($_POST['return_url'])):'';
		$return=wp_validate_redirect($submitted_return,$this->checkout_url());
		if (!$return) $return=$this->checkout_url();
		$billing_id=absint($_POST['billing_option_id']??0); $email=sanitize_email(wp_unslash($_POST['customer_email']??'')); $gateway=sanitize_key($_POST['gateway']??'');
		$details=array(); foreach(array('first_name','last_name','phone','address_1','address_2','city','state','postcode') as $field) $details[$field]=sanitize_text_field(wp_unslash($_POST[$field]??'')); $details['country']=strtoupper(sanitize_text_field(wp_unslash($_POST['country']??'')));
		if (''===$details['first_name']||''===$details['last_name']||''===$details['address_1']||''===$details['city']||''===$details['state']||''===$details['postcode']||!preg_match('/^[A-Z]{2}$/',$details['country'])) { wp_safe_redirect(add_query_arg(array('billing_option_id'=>$billing_id,'dk2_checkout_error'=>__('Please complete all required billing fields.','dropkey-v2')),$return)); exit; }
		$result=(new Commerce_Service())->start_checkout($billing_id,$email,$gateway,$return,$return,$details);
		if (is_wp_error($result)) {
			$url=add_query_arg(array('billing_option_id'=>$billing_id,'dk2_checkout_error'=>$result->get_error_message()),$return);
			wp_safe_redirect($url); exit;
		}
		$target=esc_url_raw($result['checkout_url']); $parts=wp_parse_url($target); $host=isset($parts['host'])?strtolower($parts['host']):'';
		$is_https=isset($parts['scheme'])&&'https'===strtolower($parts['scheme']);
		$external_ok=('stripe'===$gateway&&'checkout.stripe.com'===$host)||('paypal'===$gateway&&preg_match('/(^|\.)paypal\.com$/i',$host));
		if ($external_ok&&$is_https) { wp_redirect($target,303,'DropKey'); exit; }
		wp_safe_redirect($target); exit;
	}
}
