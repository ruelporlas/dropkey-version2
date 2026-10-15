<?php
/**
 * Authenticated customer account area.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Customer_Account {
	public function register() {
		add_shortcode('dropkey_account',array($this,'render'));
		add_action('admin_post_dk2_account_action',array($this,'handle_action'));
		add_action('wp_enqueue_scripts',array($this,'enqueue_assets'));
	}
	public function enqueue_assets() {
		if (!is_singular()) return;
		$post=get_post();
		if ($post&&has_shortcode($post->post_content,'dropkey_account')) wp_enqueue_style('dropkey-v2-account',DK2_URL.'assets/css/account.css',array(),DK2_VERSION);
	}
	private function account_url() { $url=get_permalink(); return $url?$url:home_url('/'); }
	private function owned_licenses($user_id) {
		global $wpdb; $l=Database::table('licenses'); $o=Database::table('orders'); $p=Database::table('products'); $t=Database::table('tiers');
		return $wpdb->get_results($wpdb->prepare("SELECT DISTINCT l.*,p.name AS product_name,t.name AS tier_name FROM {$l} l LEFT JOIN {$o} o ON o.license_id=l.id INNER JOIN {$p} p ON p.id=l.product_id INNER JOIN {$t} t ON t.id=l.tier_id WHERE (l.customer_user_id=%d OR o.customer_user_id=%d) ORDER BY l.created_at DESC,l.id DESC",$user_id,$user_id));
	}
	private function form($action,$id,$label) {
		echo '<form class="dk2-account-inline-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dk2_account_action"><input type="hidden" name="account_action" value="'.esc_attr($action).'"><input type="hidden" name="record_id" value="'.absint($id).'"><input type="hidden" name="account_url" value="'.esc_url($this->account_url()).'">';
		wp_nonce_field('dk2_customer_account_action','dk2_account_nonce'); echo '<button type="submit">'.esc_html($label).'</button></form>';
	}
	private function notice() {
		$key=isset($_GET['dk2_account_notice'])?sanitize_key(wp_unslash($_GET['dk2_account_notice'])):'';
		$messages=array('activation_removed'=>array('success',__('Website activation removed.','dropkey-v2')),'profile_saved'=>array('success',__('Your profile was updated.','dropkey-v2')),'password_saved'=>array('success',__('Your password was updated.','dropkey-v2')),'download_ready'=>array('success',__('Your protected download link is ready. It expires in 48 hours and allows up to 5 downloads.','dropkey-v2')),'error'=>array('error',isset($_GET['dk2_account_message'])?sanitize_text_field(wp_unslash($_GET['dk2_account_message'])):__('The requested action could not be completed.','dropkey-v2')));
		if (!isset($messages[$key])) return '';
		$html='<div class="dk2-account-notice dk2-account-notice-'.esc_attr($messages[$key][0]).'" role="status">'.esc_html($messages[$key][1]);
		if ('download_ready'===$key) { $url=get_transient('dk2_account_download_'.get_current_user_id()); if ($url&&is_string($url)) { delete_transient('dk2_account_download_'.get_current_user_id()); $html.=' <a rel="nofollow" href="'.esc_url($url).'">'.esc_html__('Download product ZIP','dropkey-v2').'</a>'; } }
		return $html.'</div>';
	}
	private function render_licenses($user_id,$licenses) {
		global $wpdb; $a=Database::table('activations'); $service=new License_Service();
		echo '<section class="dk2-account-section" id="dk2-licenses"><div class="dk2-account-section-heading"><h2>'.esc_html__('Your licenses','dropkey-v2').'</h2><span class="dk2-account-count">'.number_format_i18n(count($licenses)).'</span></div>';
		if (!$licenses) echo '<p class="dk2-account-empty">'.esc_html__('No licenses are linked to this account yet. If you completed a purchase and do not see your license, contact support using your order email.','dropkey-v2').'</p>';
		foreach ($licenses as $license) {
			$sites=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$a} WHERE license_id=%d ORDER BY activated_at DESC",$license->id)); $key=$service->reveal_key($license);
			echo '<article class="dk2-account-card"><div class="dk2-account-card-head"><div><p class="dk2-account-product">'.esc_html($license->product_name).'</p><h3>'.esc_html($license->tier_name).'</h3></div><span class="dk2-account-status dk2-status-'.esc_attr(sanitize_html_class($license->status)).'">'.esc_html(ucfirst($license->status)).'</span></div>';
			echo '<div class="dk2-account-key"><span>'.esc_html__('License key','dropkey-v2').'</span>';
			if ($key) echo '<code>'.esc_html($key).'</code><button type="button" class="dk2-account-copy" data-copy="'.esc_attr($key).'">'.esc_html__('Copy key','dropkey-v2').'</button>';
			else echo '<code>'.esc_html($license->key_prefix).'…</code><p class="dk2-account-muted">'.esc_html__('This older key cannot be recovered from its stored hash. Contact the site administrator if you need a replacement.','dropkey-v2').'</p>';
			echo '</div><div class="dk2-account-meta"><span>'.esc_html__('Issued','dropkey-v2').' '.esc_html(mysql2date(get_option('date_format'),$license->created_at)).'</span>';
			if ($license->expires_at) echo '<span>'.esc_html__('Expires','dropkey-v2').' '.esc_html(mysql2date(get_option('date_format'),$license->expires_at)).'</span>';
			$active=count(array_filter($sites,function($site){return 'active'===$site->status;})); echo '<span>'.sprintf(esc_html__('%d active website(s)','dropkey-v2'),$active).'</span></div>';
			if ($sites) { echo '<div class="dk2-account-sites"><h4>'.esc_html__('Activated websites','dropkey-v2').'</h4>'; foreach($sites as $site) { echo '<div class="dk2-account-site"><div><strong>'.esc_html($site->site_url).'</strong><span>'.esc_html(ucfirst($site->status)).'</span></div>'; if ('active'===$site->status) $this->form('deactivate_site',(int)$site->id,__('Deactivate','dropkey-v2')); echo '</div>'; } echo '</div>'; }
			if ('active'===$license->status) $this->form('download',(int)$license->id,__('Generate fresh download link','dropkey-v2'));
			echo '</article>';
		}
		echo '</section>';
	}
	private function render_subscriptions($user_id) {
		global $wpdb; $s=Database::table('subscriptions'); $l=Database::table('licenses'); $o=Database::table('orders'); $p=Database::table('products'); $t=Database::table('tiers');
		$rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT s.*,p.name AS product_name,t.name AS tier_name FROM {$s} s INNER JOIN {$l} l ON l.id=s.license_id LEFT JOIN {$o} o ON o.license_id=l.id INNER JOIN {$p} p ON p.id=l.product_id INNER JOIN {$t} t ON t.id=l.tier_id WHERE (l.customer_user_id=%d OR o.customer_user_id=%d) ORDER BY s.created_at DESC",$user_id,$user_id));
		echo '<section class="dk2-account-section" id="dk2-subscriptions"><div class="dk2-account-section-heading"><h2>'.esc_html__('Subscriptions','dropkey-v2').'</h2><span class="dk2-account-count">'.number_format_i18n(count($rows)).'</span></div>';
		if (!$rows) echo '<p class="dk2-account-empty">'.esc_html__('No subscriptions are linked to this account.','dropkey-v2').'</p>';
		foreach($rows as $row) { echo '<article class="dk2-account-row-card"><div><strong>'.esc_html($row->product_name).' — '.esc_html($row->tier_name).'</strong><span>'.esc_html(strtoupper($row->gateway)).' · '.esc_html(ucfirst(str_replace('_',' ',$row->status))).'</span></div><div class="dk2-account-row-meta">';
			if ($row->current_period_end) echo '<span>'.esc_html__('Paid through','dropkey-v2').' '.esc_html(mysql2date(get_option('date_format'),$row->current_period_end)).'</span>';
			if ((int)$row->cancel_at_period_end) echo '<span class="dk2-account-warning">'.esc_html__('Cancellation scheduled for period end','dropkey-v2').'</span>';
			if ('past_due'===$row->status&&$row->grace_until) echo '<span class="dk2-account-warning">'.esc_html__('Grace period ends','dropkey-v2').' '.esc_html(mysql2date(get_option('date_format'),$row->grace_until)).'</span>';
			echo '</div><p class="dk2-account-muted">'.esc_html__('Subscription cancellation is managed by the site administrator. Payment-method changes are not available here yet.','dropkey-v2').'</p></article>'; }
		echo '</section>';
	}
	private function render_purchases($user_id) {
		global $wpdb; $o=Database::table('orders'); $p=Database::table('products'); $t=Database::table('tiers');
		$rows=$wpdb->get_results($wpdb->prepare("SELECT o.*,p.name AS product_name,t.name AS tier_name FROM {$o} o INNER JOIN {$p} p ON p.id=o.product_id INNER JOIN {$t} t ON t.id=o.tier_id WHERE o.customer_user_id=%d ORDER BY o.created_at DESC,o.id DESC",$user_id));
		echo '<section class="dk2-account-section" id="dk2-purchases"><div class="dk2-account-section-heading"><h2>'.esc_html__('Purchases and downloads','dropkey-v2').'</h2><span class="dk2-account-count">'.number_format_i18n(count($rows)).'</span></div>';
		if (!$rows) echo '<p class="dk2-account-empty">'.esc_html__('No purchases are linked to this account yet. Guest purchases are not claimed by email matching.','dropkey-v2').'</p>';
		foreach($rows as $row) { echo '<article class="dk2-account-row-card"><div><strong>'.esc_html($row->product_name).' — '.esc_html($row->tier_name).'</strong><span>'.esc_html(mysql2date(get_option('date_format'),$row->created_at)).' · '.esc_html(ucfirst($row->status)).'</span></div><div class="dk2-account-row-meta"><strong>'.esc_html(strtoupper($row->currency).' '.number_format((float)$row->amount,2)).'</strong><span>'.esc_html(strtoupper($row->gateway)).'</span></div>';
			if ($row->license_id&&'paid'===$row->status) $this->form('download',(int)$row->license_id,__('Generate fresh download link','dropkey-v2')); echo '</article>'; }
		echo '</section>';
	}
	private function render_profile($user) {
		echo '<section class="dk2-account-section" id="dk2-profile"><div class="dk2-account-section-heading"><h2>'.esc_html__('Profile and password','dropkey-v2').'</h2></div><form class="dk2-account-profile-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dk2_account_action"><input type="hidden" name="account_action" value="profile"><input type="hidden" name="account_url" value="'.esc_url($this->account_url()).'">';
		wp_nonce_field('dk2_customer_account_action','dk2_account_nonce');
		echo '<div class="dk2-account-form-grid"><label>'.esc_html__('Email address','dropkey-v2').'<input type="email" value="'.esc_attr($user->user_email).'" readonly><small>'.esc_html__('Email changes are disabled to protect purchase ownership links.','dropkey-v2').'</small></label><label>'.esc_html__('First name','dropkey-v2').'<input name="first_name" value="'.esc_attr($user->first_name).'"></label><label>'.esc_html__('Last name','dropkey-v2').'<input name="last_name" value="'.esc_attr($user->last_name).'"></label><label>'.esc_html__('Display name','dropkey-v2').'<input name="display_name" required value="'.esc_attr($user->display_name).'"></label></div><h3>'.esc_html__('Change password','dropkey-v2').'</h3><p class="dk2-account-muted">'.esc_html__('Leave password fields blank to keep your current password.','dropkey-v2').'</p><div class="dk2-account-form-grid"><label>'.esc_html__('Current password','dropkey-v2').'<input type="password" name="current_password" autocomplete="current-password"></label><label>'.esc_html__('New password','dropkey-v2').'<input type="password" name="new_password" minlength="12" autocomplete="new-password"><small>'.esc_html__('Use at least 12 characters.','dropkey-v2').'</small></label><label>'.esc_html__('Confirm new password','dropkey-v2').'<input type="password" name="confirm_password" minlength="12" autocomplete="new-password"></label></div><button type="submit">'.esc_html__('Save profile','dropkey-v2').'</button></form></section>';
	}
	public function render() {
		if (!is_user_logged_in()) return '<div class="dk2-account dk2-account-login"><h2>'.esc_html__('Your DropKey account','dropkey-v2').'</h2><p>'.esc_html__('Sign in to view your licenses, activations, subscriptions, and purchases.','dropkey-v2').'</p><a class="dk2-account-button-link" href="'.esc_url(wp_login_url($this->account_url())).'">'.esc_html__('Sign in','dropkey-v2').'</a></div>';
		$user=wp_get_current_user(); $user_id=(int)$user->ID; $licenses=$this->owned_licenses($user_id);
		ob_start(); echo '<div class="dk2-account">'.$this->notice().'<header class="dk2-account-hero"><p class="dk2-account-eyebrow">'.esc_html__('DROPKEY CUSTOMER AREA','dropkey-v2').'</p><h1>'.sprintf(esc_html__('Welcome, %s','dropkey-v2'),esc_html($user->display_name)).'</h1><p>'.esc_html__('Manage your product licenses, active websites, subscriptions, downloads, and account details.','dropkey-v2').'</p><nav aria-label="'.esc_attr__('Account sections','dropkey-v2').'"><a href="#dk2-licenses">'.esc_html__('Licenses','dropkey-v2').'</a><a href="#dk2-subscriptions">'.esc_html__('Subscriptions','dropkey-v2').'</a><a href="#dk2-purchases">'.esc_html__('Purchases','dropkey-v2').'</a><a href="#dk2-profile">'.esc_html__('Profile','dropkey-v2').'</a></nav></header>';
		echo '<div class="dk2-account-summary"><div><span>'.esc_html__('Licenses','dropkey-v2').'</span><strong>'.number_format_i18n(count($licenses)).'</strong></div><div><span>'.esc_html__('Active licenses','dropkey-v2').'</span><strong>'.number_format_i18n(count(array_filter($licenses,function($license){return 'active'===$license->status;}))).'</strong></div></div>';
		$this->render_licenses($user_id,$licenses); $this->render_subscriptions($user_id); $this->render_purchases($user_id); $this->render_profile($user); echo '</div>';
		$html=ob_get_clean();
		$html.='<script>(function(){document.addEventListener("click",function(e){var b=e.target.closest(".dk2-account-copy");if(!b)return;var text=b.getAttribute("data-copy")||"";if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(function(){b.textContent="'.esc_js(__('Copied','dropkey-v2')).'";}).catch(function(){window.prompt("'.esc_js(__('Copy your license key','dropkey-v2')).'",text);});}else{window.prompt("'.esc_js(__('Copy your license key','dropkey-v2')).'",text);}});})();</script>';
		return $html;
	}
	public function handle_action() {
		if (!is_user_logged_in()) wp_die(esc_html__('Please sign in to manage your account.','dropkey-v2'),'',array('response'=>401));
		if (empty($_POST['dk2_account_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dk2_account_nonce'])),'dk2_customer_account_action')) wp_die(esc_html__('Your account form expired. Refresh the page and try again.','dropkey-v2'),'',array('response'=>403));
		$user_id=get_current_user_id(); $action=sanitize_key(wp_unslash($_POST['account_action']??'')); $return=wp_validate_redirect(esc_url_raw(wp_unslash($_POST['account_url']??'')),home_url('/'));
		$fail=function($message) use ($return) { wp_safe_redirect(add_query_arg(array('dk2_account_notice'=>'error','dk2_account_message'=>$message),$return)); exit; };
		global $wpdb;
		if ('deactivate_site'===$action) {
			$id=absint($_POST['record_id']??0); $a=Database::table('activations'); $l=Database::table('licenses'); $o=Database::table('orders');
			$owned=$wpdb->get_row($wpdb->prepare("SELECT a.id,a.license_id,a.site_url FROM {$a} a INNER JOIN {$l} l ON l.id=a.license_id LEFT JOIN {$o} o ON o.license_id=l.id WHERE a.id=%d AND (l.customer_user_id=%d OR o.customer_user_id=%d) LIMIT 1",$id,$user_id,$user_id));
			if (!$owned) $fail(__('That activation was not found in your account.','dropkey-v2'));
			$changed=$wpdb->update($a,array('status'=>'inactive','deactivated_at'=>current_time('mysql',true)),array('id'=>$id,'status'=>'active'));
			if (false===$changed) $fail(__('The activation could not be updated.','dropkey-v2'));
			if ($changed) do_action('dk2_license_deactivated',(int)$owned->license_id,$owned->site_url);
			wp_safe_redirect(add_query_arg('dk2_account_notice','activation_removed',$return)); exit;
		}
		if ('download'===$action) {
			$id=absint($_POST['record_id']??0); $l=Database::table('licenses'); $o=Database::table('orders'); $r=Database::table('releases');
			$license=$wpdb->get_row($wpdb->prepare("SELECT DISTINCT l.* FROM {$l} l LEFT JOIN {$o} o ON o.license_id=l.id WHERE l.id=%d AND (l.customer_user_id=%d OR o.customer_user_id=%d) LIMIT 1",$id,$user_id,$user_id));
			if (!$license) $fail(__('That license was not found in your account.','dropkey-v2'));
			$valid=(new License_Service())->status_error($license); if (is_wp_error($valid)) $fail($valid->get_error_message());
			$release=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$r} WHERE product_id=%d AND status='published' ORDER BY created_at DESC,id DESC LIMIT 1",$license->product_id));
			if (!$release) $fail(__('No published download is currently available.','dropkey-v2'));
			$link=(new Download_Service())->create_token($id,$release); if (is_wp_error($link)) $fail($link->get_error_message());
			set_transient('dk2_account_download_'.$user_id,$link['url'],5*MINUTE_IN_SECONDS);
			wp_safe_redirect(add_query_arg('dk2_account_notice','download_ready',$return)); exit;
		}
		if ('profile'===$action) {
			$current=get_userdata($user_id); $first=sanitize_text_field(wp_unslash($_POST['first_name']??'')); $last=sanitize_text_field(wp_unslash($_POST['last_name']??'')); $display=sanitize_text_field(wp_unslash($_POST['display_name']??''));
			if (''===$display) $fail(__('Enter a display name.','dropkey-v2'));
			$new=(string)wp_unslash($_POST['new_password']??''); $confirm=(string)wp_unslash($_POST['confirm_password']??''); $old=(string)wp_unslash($_POST['current_password']??'');
			$data=array('ID'=>$user_id,'first_name'=>$first,'last_name'=>$last,'display_name'=>$display);
			if (''!==$new||''!==$confirm||''!==$old) {
				if (!wp_check_password($old,$current->user_pass,$user_id)) $fail(__('Enter your current password to change it.','dropkey-v2'));
				if (strlen($new)<12) $fail(__('Your new password must be at least 12 characters.','dropkey-v2'));
				if ($new!==$confirm) $fail(__('The new passwords do not match.','dropkey-v2'));
				$data['user_pass']=$new;
			}
			$result=wp_update_user($data); if (is_wp_error($result)) $fail($result->get_error_message());
			if (isset($data['user_pass'])) { wp_set_current_user($user_id); wp_set_auth_cookie($user_id,true,is_ssl()); }
			wp_safe_redirect(add_query_arg('dk2_account_notice',isset($data['user_pass'])?'password_saved':'profile_saved',$return)); exit;
		}
		$fail(__('That account action is not recognized.','dropkey-v2'));
	}
}
