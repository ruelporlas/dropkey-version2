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
		add_action('admin_post_dk2_public_checkout',array($this,'submit_checkout'));
		add_action('admin_post_nopriv_dk2_public_checkout',array($this,'submit_checkout'));
		add_action('wp_enqueue_scripts',array($this,'enqueue_assets'));
	}
	public function enqueue_assets() {
		if (!is_singular()) return;
		$post=get_post();
		if ($post&&has_shortcode($post->post_content,'dropkey_store')) wp_enqueue_style('dropkey-v2-storefront',DK2_URL.'assets/css/storefront.css',array(),DK2_VERSION);
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
			echo '<form class="dk2-store-checkout" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dk2_public_checkout"><input type="hidden" name="billing_option_id" value="'.(int)$row->billing_option_id.'">';
			wp_nonce_field('dk2_public_checkout','dk2_checkout_nonce');
			echo '<label>'.esc_html__('Email address','dropkey-v2').'<input required type="email" name="customer_email" autocomplete="email" placeholder="you@example.com"></label>';
			if ('free'===$row->billing_type) echo '<input type="hidden" name="gateway" value="free">';
			else {
				echo '<label>'.esc_html__('Payment method','dropkey-v2').'<select required name="gateway"><option value="">'.esc_html__('Choose a payment method','dropkey-v2').'</option>';
				if ($settings['stripe']) echo '<option value="stripe">Stripe Checkout</option>';
				if ($settings['paypal']&&(!in_array($row->billing_type,array('monthly','yearly'),true)||!empty($row->paypal_plan_id))) echo '<option value="paypal">PayPal</option>';
				echo '</select></label>';
				if (!$settings['stripe']&&!$settings['paypal']) echo '<p class="dk2-store-unavailable">'.esc_html__('Checkout is temporarily unavailable.','dropkey-v2').'</p>';
			}
			echo '<button type="submit" '.(('free'!==$row->billing_type&&!$settings['stripe']&&!$settings['paypal'])?'disabled':'').'>'.esc_html('free'===$row->billing_type?__('Get this product','dropkey-v2'):__('Continue to checkout','dropkey-v2')).'</button></form></article>';
		}
		echo '</div>';
		return ob_get_clean();
	}
	public function submit_checkout() {
		if (empty($_POST['dk2_checkout_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dk2_checkout_nonce'])),'dk2_public_checkout')) wp_die(esc_html__('Your checkout form expired. Refresh the page and try again.','dropkey-v2'),'',array('response'=>403));
		$return=wp_get_referer(); if (!$return) $return=home_url('/');
		$billing_id=absint($_POST['billing_option_id']??0); $email=sanitize_email(wp_unslash($_POST['customer_email']??'')); $gateway=sanitize_key($_POST['gateway']??'');
		$result=(new Commerce_Service())->start_checkout($billing_id,$email,$gateway,$return,$return);
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
