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
		if (!$order) return '<div class="dk2-store-notice dk2-store-error">'.esc_html__('We could not find that checkout. Please contact the site administrator if you were charged.','dropkey-v2').'</div>';
		if ('paypal'===$order->gateway&&!empty($_GET['token'])&&'pending'===$order->status) {
			$captured=(new Commerce_Service())->capture_paypal($public_id,sanitize_text_field(wp_unslash($_GET['token'])));
			if (is_wp_error($captured)) do_action('dk2_paypal_capture_return_error',(int)$order->id,$captured->get_error_code());
			$order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE id=%d',$order->id));
		}
		if ('paid'===$order->status) return '<div class="dk2-store-notice dk2-store-success"><strong>'.esc_html__('Order confirmed','dropkey-v2').'</strong><span>'.esc_html__('Payment is confirmed. Your license key and protected download link will be sent to your email address.','dropkey-v2').'</span></div>';
		if ('pending'===$order->status) return '<div class="dk2-store-notice"><strong>'.esc_html__('Payment confirmation pending','dropkey-v2').'</strong><span>'.esc_html__('The payment provider has not confirmed this order yet. Your license will be issued only after confirmation. Reload this page in a moment.','dropkey-v2').'</span></div>';
		return '<div class="dk2-store-notice dk2-store-error"><strong>'.esc_html__('Checkout not completed','dropkey-v2').'</strong><span>'.esc_html__('No active license was issued for this order. You can try checkout again or contact support if you believe you were charged.','dropkey-v2').'</span></div>';
	}
	public function render() {
		global $wpdb;
		$products=Database::table('products'); $tiers=Database::table('tiers'); $billing=Database::table('billing_options');
		$settings=$this->payment_settings();
		$rows=$wpdb->get_results("SELECT p.id AS product_id,p.name AS product_name,p.description AS product_description,t.id AS tier_id,t.name AS tier_name,t.description AS tier_description,t.activation_limit,b.id AS billing_option_id,b.billing_type,b.price,b.currency,b.paypal_plan_id FROM {$products} p INNER JOIN {$tiers} t ON t.product_id=p.id INNER JOIN {$billing} b ON b.tier_id=t.id WHERE p.status='published' AND t.status='published' AND b.status='published' ORDER BY p.name,t.sort_order,t.id,b.id");
		ob_start();
		echo '<div class="dk2-store">';
		echo $this->current_order_notice();
		if (!empty($_GET['dk2_checkout_error'])) echo '<div class="dk2-store-notice dk2-store-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['dk2_checkout_error']))).'</div>';
		if (!$rows) echo '<div class="dk2-store-empty">'.esc_html__('No products are available for purchase yet.','dropkey-v2').'</div>';
		foreach($rows as $row) {
			$price='free'===$row->billing_type?__('Free','dropkey-v2'):strtoupper($row->currency).' '.number_format((float)$row->price,2);
			$cadence='monthly'===$row->billing_type?__('per month','dropkey-v2'):('yearly'===$row->billing_type?__('per year','dropkey-v2'):('one_time'===$row->billing_type?__('one-time','dropkey-v2'):'')); 
			echo '<article class="dk2-store-card"><div class="dk2-store-card-top"><div><p class="dk2-store-eyebrow">'.esc_html($row->product_name).'</p><h3>'.esc_html($row->tier_name).'</h3><p>'.esc_html(wp_strip_all_tags($row->tier_description?$row->tier_description:$row->product_description)).'</p></div><div class="dk2-store-price"><strong>'.esc_html($price).'</strong><span>'.esc_html($cadence).'</span></div></div>';
			echo '<p class="dk2-store-limit">'.sprintf(esc_html__('%d active website%s included','dropkey-v2'),(int)$row->activation_limit,(1===(int)$row->activation_limit?'':'s')).'</p>';
			echo '<a class="dk2-store-order-button" href="'.esc_url(add_query_arg('billing_option_id',(int)$row->billing_option_id,$this->checkout_url())).'">'.esc_html__('Order now','dropkey-v2').' <span aria-hidden="true">→</span></a></article>';
		}
		echo '</div>';
		return ob_get_clean();
	}
	public function render_checkout() {
		if (!empty($_GET['dk2_order'])) return $this->current_order_notice();
		$id=absint($_GET['billing_option_id']??0); global $wpdb;
		$b=Database::table('billing_options'); $t=Database::table('tiers'); $p=Database::table('products');
		$o=$wpdb->get_row($wpdb->prepare("SELECT b.*,t.name AS tier_name,t.description AS tier_description,p.name AS product_name,p.description AS product_description FROM {$b} b INNER JOIN {$t} t ON t.id=b.tier_id INNER JOIN {$p} p ON p.id=t.product_id WHERE b.id=%d AND b.status='published' AND t.status='published' AND p.status='published'",$id));
		if (!$o) return '<div class="dk2-store-notice dk2-store-error">'.esc_html__('Choose a product from the store before checking out.','dropkey-v2').'</div>';
		$s=$this->payment_settings(); $price='free'===$o->billing_type?__('Free','dropkey-v2'):strtoupper($o->currency).' '.number_format((float)$o->price,2);
		$user=is_user_logged_in()?wp_get_current_user():null;
		ob_start(); echo '<div class="dk2-checkout"><main class="dk2-checkout-main"><p class="dk2-store-eyebrow">'.esc_html__('Secure checkout','dropkey-v2').'</p><h2>'.esc_html__('Checkout','dropkey-v2').'</h2>';
		if (!empty($_GET['dk2_checkout_error'])) echo '<div class="dk2-store-notice dk2-store-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['dk2_checkout_error']))).'</div>';
		echo '<form class="dk2-checkout-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dk2_public_checkout"><input type="hidden" name="billing_option_id" value="'.(int)$id.'"><input type="hidden" name="return_url" value="'.esc_attr(esc_url_raw($this->checkout_url())).'">'; wp_nonce_field('dk2_public_checkout','dk2_checkout_nonce');
		echo '<h3>'.esc_html__('Contact information','dropkey-v2').'</h3><div class="dk2-field-grid"><label>'.esc_html__('First name','dropkey-v2').'<input required name="first_name" autocomplete="given-name" maxlength="100"></label><label>'.esc_html__('Last name','dropkey-v2').'<input required name="last_name" autocomplete="family-name" maxlength="100"></label><label class="dk2-field-full">'.esc_html__('Email address','dropkey-v2').'<input required type="email" name="customer_email" autocomplete="email" maxlength="191" value="'.esc_attr($user?$user->user_email:'').'"></label><label class="dk2-field-full">'.esc_html__('Phone (optional)','dropkey-v2').'<input name="phone" autocomplete="tel" maxlength="40"></label></div>';
		echo '<h3>'.esc_html__('Billing address','dropkey-v2').'</h3><div class="dk2-field-grid"><label class="dk2-field-full">'.esc_html__('Street address','dropkey-v2').'<input required name="address_1" autocomplete="address-line1" maxlength="191"></label><label class="dk2-field-full">'.esc_html__('Apartment, suite, etc. (optional)','dropkey-v2').'<input name="address_2" autocomplete="address-line2" maxlength="191"></label><label>'.esc_html__('City','dropkey-v2').'<input required name="city" autocomplete="address-level2" maxlength="100"></label><label>'.esc_html__('State / Province','dropkey-v2').'<input required name="state" autocomplete="address-level1" maxlength="100"></label><label>'.esc_html__('Postal code','dropkey-v2').'<input required name="postcode" autocomplete="postal-code" maxlength="30"></label><label>'.esc_html__('Country code (2 letters)','dropkey-v2').'<input required name="country" autocomplete="country" minlength="2" maxlength="2" pattern="[A-Za-z]{2}" placeholder="US"></label></div><h3>'.esc_html__('Payment method','dropkey-v2').'</h3><div class="dk2-payment-options">';
		if ('free'===$o->billing_type) echo '<input type="hidden" name="gateway" value="free"><p>'.esc_html__('No payment is required for this plan.','dropkey-v2').'</p>';
		else { if ($s['paypal']) echo '<label class="dk2-payment-option"><input required type="radio" name="gateway" value="paypal"><span><strong>PayPal</strong><small>'.esc_html__('Pay securely on PayPal, then return here for confirmation.','dropkey-v2').'</small></span></label>'; if ($s['stripe']) echo '<label class="dk2-payment-option"><input required type="radio" name="gateway" value="stripe"><span><strong>'.esc_html__('Credit or debit card','dropkey-v2').'</strong><small>'.esc_html__('Continue to secure Stripe Checkout.','dropkey-v2').'</small></span></label>'; if (!$s['paypal']&&!$s['stripe']) echo '<p class="dk2-store-unavailable">'.esc_html__('Checkout is temporarily unavailable.','dropkey-v2').'</p>'; }
		echo '</div><p class="dk2-checkout-secure">'.esc_html__('Payment details are handled by the payment provider. DropKey never asks for your PayPal password or full card number.','dropkey-v2').'</p><button class="dk2-store-order-button" type="submit" '.(('free'!==$o->billing_type&&!$s['paypal']&&!$s['stripe'])?'disabled':'').'>'.esc_html('free'===$o->billing_type?__('Complete free order','dropkey-v2'):__('Proceed to payment','dropkey-v2')).'</button></form></main><aside class="dk2-order-summary"><h3>'.esc_html__('Order summary','dropkey-v2').'</h3><p>'.esc_html($o->product_name).'</p><strong>'.esc_html($o->tier_name).'</strong><p>'.esc_html($price).'</p><hr><strong>'.esc_html__('Total due today:','dropkey-v2').' '.esc_html($price).'</strong><p>'.esc_html__('Your license will be delivered by email after payment confirmation.','dropkey-v2').'</p></aside></div>';
		return ob_get_clean();
	}
	public function submit_checkout() {
		if (empty($_POST['dk2_checkout_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dk2_checkout_nonce'])),'dk2_public_checkout')) wp_die(esc_html__('Your checkout form expired. Refresh the page and try again.','dropkey-v2'),'',array('response'=>403));
		$submitted_return=isset($_POST['return_url'])?esc_url_raw(wp_unslash($_POST['return_url'])):'';
		$return=wp_validate_redirect($submitted_return,home_url('/'));
		if (!$return) $return=home_url('/');
		$billing_id=absint($_POST['billing_option_id']??0); $email=sanitize_email(wp_unslash($_POST['customer_email']??'')); $gateway=sanitize_key($_POST['gateway']??'');
		$details=array(); foreach(array('first_name','last_name','phone','address_1','address_2','city','state','postcode') as $field) $details[$field]=sanitize_text_field(wp_unslash($_POST[$field]??'')); $details['country']=strtoupper(sanitize_text_field(wp_unslash($_POST['country']??'')));\n\t\tif (''===$details['first_name']||''===$details['last_name']||''===$details['address_1']||''===$details['city']||''===$details['state']||''===$details['postcode']||!preg_match('/^[A-Z]{2}$/',$details['country'])) { wp_safe_redirect(add_query_arg(array('billing_option_id'=>$billing_id,'dk2_checkout_error'=>__('Please complete all required billing fields.','dropkey-v2')),$return)); exit; }\n\t\t$result=(new Commerce_Service())->start_checkout($billing_id,$email,$gateway,$return,$return,$details);
		if (is_wp_error($result)) {
			$url=add_query_arg('dk2_checkout_error',$result->get_error_message(),$return);
			wp_safe_redirect($url); exit;
		}
		$target=esc_url_raw($result['checkout_url']); $parts=wp_parse_url($target); $host=isset($parts['host'])?strtolower($parts['host']):'';
		$is_https=isset($parts['scheme'])&&'https'===strtolower($parts['scheme']);
		$external_ok=('stripe'===$gateway&&'checkout.stripe.com'===$host)||('paypal'===$gateway&&preg_match('/(^|\.)paypal\.com$/i',$host));
		if ($external_ok&&$is_https) { wp_redirect($target,303,'DropKey'); exit; }
		wp_safe_redirect($target); exit;
	}
}
