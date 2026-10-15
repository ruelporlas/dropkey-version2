<?php
/**
 * DropKey administrative screens and secured form handlers.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Admin {
	public function register_menu() {
		add_menu_page(__('DropKey','dropkey-v2'),__('DropKey','dropkey-v2'),'manage_options','dk2-products',array($this,'products'),'dashicons-admin-network',56);
		add_submenu_page('dk2-products',__('Products','dropkey-v2'),__('Products','dropkey-v2'),'manage_options','dk2-products',array($this,'products'));
		add_submenu_page('dk2-products',__('Tiers','dropkey-v2'),__('Tiers','dropkey-v2'),'manage_options','dk2-tiers',array($this,'tiers'));
		add_submenu_page('dk2-products',__('Billing Options','dropkey-v2'),__('Billing Options','dropkey-v2'),'manage_options','dk2-billing',array($this,'billing'));
		add_submenu_page('dk2-products',__('ZIP Releases','dropkey-v2'),__('ZIP Releases','dropkey-v2'),'manage_options','dk2-releases',array($this,'releases'));
		add_submenu_page('dk2-products',__('Licenses','dropkey-v2'),__('Licenses','dropkey-v2'),'manage_options','dk2-licenses',array($this,'licenses'));
		add_submenu_page('dk2-products',__('Orders','dropkey-v2'),__('Orders','dropkey-v2'),'manage_options','dk2-orders',array($this,'orders'));
		add_submenu_page('dk2-products',__('Payment Settings','dropkey-v2'),__('Payment Settings','dropkey-v2'),'manage_options','dk2-settings',array($this,'settings'));
		add_submenu_page('dk2-products',__('Debug Logs','dropkey-v2'),__('Debug Logs','dropkey-v2'),'manage_options','dk2-logs',array($this,'logs'));
	}
	public function register_handlers() {
		foreach(array('product','tier','billing','release','license','license_status','download_link','activation_status','gateway_settings','email_resend','subscription_cancel','logs_clear','logs_settings') as $action) add_action('admin_post_dk2_'.$action,array($this,'handle'));
	}
	private function guard() {
		if (!current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to manage DropKey.','dropkey-v2'));
		check_admin_referer('dk2_admin_action','dk2_nonce');
	}
	private function redirect($page,$notice,$extra=array()) {
		$args=array_merge(array('page'=>$page,'dk2_notice'=>$notice),$extra);
		wp_safe_redirect(add_query_arg($args,admin_url('admin.php'))); exit;
	}
	private function notice() {
		if (empty($_GET['dk2_notice'])) return;
		$key=sanitize_key(wp_unslash($_GET['dk2_notice']));
		$messages=array('saved'=>__('Changes saved.','dropkey-v2'),'created'=>__('Record created. Copy any license key now; it is only shown once.','dropkey-v2'),'status'=>__('Status updated.','dropkey-v2'),'link'=>__('A protected download link was created.','dropkey-v2'),'logs_cleared'=>__('DropKey log history cleared.','dropkey-v2'));
		if (isset($messages[$key])) echo '<div class="notice notice-success"><p>'.esc_html($messages[$key]).'</p>';
		if ('link'===$key && !empty($_GET['url'])) echo '<p><input class="large-text" readonly value="'.esc_attr(esc_url_raw(wp_unslash($_GET['url']))).'" onclick="this.select()"></p><p>'.esc_html__('This link expires after 48 hours and allows up to 5 downloads. Copy it now.','dropkey-v2').'</p>';
		if (isset($messages[$key])) echo '</div>';
		if ('key'=== $key && !empty($_GET['license_key'])) echo '<div class="notice notice-success"><p>'.esc_html__('License created. Copy this key now; DropKey stores only its hash.','dropkey-v2').'</p><p><code>'.esc_html(wp_unslash($_GET['license_key'])).'</code></p></div>';
		if ('error'===$key && !empty($_GET['message'])) echo '<div class="notice notice-error"><p>'.esc_html(rawurldecode(wp_unslash($_GET['message']))).'</p></div>';
	}
	private function header($title,$intro) {
		echo '<div class="wrap"><h1>'.esc_html($title).'</h1><p>'.esc_html($intro).'</p>'; $this->notice();
	}
	private function footer() { echo '</div>'; }
	private function status_select($value='draft') {
		echo '<select name="status">';
		foreach(array('draft'=>__('Draft','dropkey-v2'),'published'=>__('Published','dropkey-v2'),'archived'=>__('Archived','dropkey-v2')) as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($value,$k,false).'>'.esc_html($v).'</option>';
		echo '</select>';
	}
	private function form_start($action,$id=0,$multipart=false) {
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.($multipart?' enctype="multipart/form-data"':'').'><input type="hidden" name="action" value="dk2_'.esc_attr($action).'">';
		if ($id) echo '<input type="hidden" name="id" value="'.absint($id).'">';
		wp_nonce_field('dk2_admin_action','dk2_nonce');
	}
	private function products_list() {
		$rows=(new Product_Repository())->all(200);
		echo '<h2>'.esc_html__('Existing products','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Name / slug','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th><th>'.esc_html__('Next step','dropkey-v2').'</th><th>'.esc_html__('Edit','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $r) echo '<tr><td>'.(int)$r->id.'</td><td><strong>'.esc_html($r->name).'</strong><br><code>'.esc_html($r->slug).'</code></td><td>'.esc_html($r->status).'</td><td><a href="'.esc_url(admin_url('admin.php?page=dk2-tiers&product_id='.(int)$r->id)).'">'.esc_html__('Manage tiers','dropkey-v2').'</a> · <a href="'.esc_url(admin_url('admin.php?page=dk2-releases&product_id='.(int)$r->id)).'">'.esc_html__('Add ZIP release','dropkey-v2').'</a></td><td><a href="'.esc_url(admin_url('admin.php?page=dk2-products&edit='.(int)$r->id)).'">'.esc_html__('Edit','dropkey-v2').'</a></td></tr>';
		if (!$rows) echo '<tr><td colspan="5">'.esc_html__('No products yet. Create your first product below.','dropkey-v2').'</td></tr>';
		echo '</tbody></table>';
	}

	private function dashboard_summary() {
		global $wpdb;
		$products=Database::table('products'); $tiers=Database::table('tiers'); $licenses=Database::table('licenses'); $orders=Database::table('orders');
		$stats=array(
			__('Published products','dropkey-v2')=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$products} WHERE status='published'"),
			__('Published tiers','dropkey-v2')=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$tiers} WHERE status='published'"),
			__('Active licenses','dropkey-v2')=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$licenses} WHERE status='active'"),
			__('Pending orders','dropkey-v2')=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$orders} WHERE status IN ('pending','processing')")
		);
		echo '<div class="dk2-dashboard-stats">';
		foreach($stats as $label=>$value) echo '<div class="dk2-stat-card"><span>'.esc_html($label).'</span><strong>'.number_format_i18n($value).'</strong></div>';
		echo '</div><nav class="dk2-quick-links" aria-label="'.esc_attr__('DropKey management shortcuts','dropkey-v2').'">';
		foreach(array('dk2-tiers'=>__('Manage tiers','dropkey-v2'),'dk2-billing'=>__('Billing options','dropkey-v2'),'dk2-releases'=>__('ZIP releases','dropkey-v2'),'dk2-licenses'=>__('Licenses and activations','dropkey-v2'),'dk2-orders'=>__('Orders and subscriptions','dropkey-v2'),'dk2-settings'=>__('Payment settings','dropkey-v2')) as $page=>$label) echo '<a href="'.esc_url(admin_url('admin.php?page='.$page)).'">'.esc_html($label).' <span aria-hidden="true">→</span></a>';
		echo '</nav>';
	}

	public function products() {
		if (!current_user_can('manage_options')) return;
		$id=isset($_GET['edit'])?absint($_GET['edit']):0; $r=$id?(new Product_Repository())->get($id):null;
		$this->header(__('Products','dropkey-v2'),__('Create and publish digital products. A published product can contain multiple configurable tiers and versioned ZIP releases.','dropkey-v2'));
		$this->dashboard_summary();
		echo '<h2>'.($r?esc_html__('Edit product','dropkey-v2'):esc_html__('Add a product','dropkey-v2')).'</h2>'; $this->form_start('product',$id);
		echo '<table class="form-table"><tr><th><label for="dk2-name">'.esc_html__('Product name','dropkey-v2').'</label></th><td><input required class="regular-text" id="dk2-name" name="name" value="'.esc_attr($r?$r->name:'').'"></td></tr><tr><th><label for="dk2-slug">'.esc_html__('Product slug','dropkey-v2').'</label></th><td><input class="regular-text" id="dk2-slug" name="slug" value="'.esc_attr($r?$r->slug:'').'"><p class="description">'.esc_html__('Stable URL-safe identifier used by the update API. Leave blank to generate it from the name.','dropkey-v2').'</p></td></tr><tr><th><label for="dk2-description">'.esc_html__('Description','dropkey-v2').'</label></th><td><textarea class="large-text" rows="4" id="dk2-description" name="description">'.esc_textarea($r?$r->description:'').'</textarea></td></tr><tr><th>'.esc_html__('Status','dropkey-v2').'</th><td>'; $this->status_select($r?$r->status:'draft'); echo '</td></tr></table><p><button class="button button-primary">'.esc_html($r?__('Save product','dropkey-v2'):__('Create product','dropkey-v2')).'</button></p></form>';
		$this->products_list(); $this->footer();
	}
	private function product_options($selected=0) {
		$rows=(new Product_Repository())->all(500); echo '<select required name="product_id"><option value="">'.esc_html__('Select product','dropkey-v2').'</option>';
		foreach($rows as $r) echo '<option value="'.(int)$r->id.'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($r->name).' ('.esc_html($r->status).')</option>';
		echo '</select>';
	}
	private function tier_options($selected=0) {
		global $wpdb; $t=Database::table('tiers'); $p=Database::table('products');
		$rows=$wpdb->get_results("SELECT t.*,p.name AS product_name FROM {$t} t INNER JOIN {$p} p ON p.id=t.product_id ORDER BY p.name,t.sort_order,t.name");
		echo '<select required name="tier_id"><option value="">'.esc_html__('Select tier','dropkey-v2').'</option>';
		foreach($rows as $r) echo '<option value="'.(int)$r->id.'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($r->product_name.' — '.$r->name).' ('.esc_html($r->status).')</option>';
		echo '</select>';
	}
	public function tiers() {
		if (!current_user_can('manage_options')) return;
		$id=isset($_GET['edit'])?absint($_GET['edit']):0; $r=$id?(new Tier_Repository())->get($id):null; $pid=$r?(int)$r->product_id:(isset($_GET['product_id'])?absint($_GET['product_id']):0);
		$this->header(__('Tiers','dropkey-v2'),__('Each tier belongs to one product and owns its activation limit. Publish the product and tier before issuing a license.','dropkey-v2'));
		echo '<h2>'.($r?esc_html__('Edit tier','dropkey-v2'):esc_html__('Add a tier','dropkey-v2')).'</h2>'; $this->form_start('tier',$id);
		echo '<table class="form-table"><tr><th>'.esc_html__('Product','dropkey-v2').'</th><td>'; if($r) { echo esc_html((new Product_Repository())->get($r->product_id)->name); echo '<input type="hidden" name="product_id" value="'.(int)$r->product_id.'">'; } else $this->product_options($pid); echo '</td></tr><tr><th><label>'.esc_html__('Tier name','dropkey-v2').'</label></th><td><input required class="regular-text" name="name" value="'.esc_attr($r?$r->name:'').'"></td></tr><tr><th><label>'.esc_html__('Slug','dropkey-v2').'</label></th><td><input class="regular-text" name="slug" value="'.esc_attr($r?$r->slug:'').'"></td></tr><tr><th><label>'.esc_html__('Description','dropkey-v2').'</label></th><td><textarea class="large-text" name="description" rows="3">'.esc_textarea($r?$r->description:'').'</textarea></td></tr><tr><th><label>'.esc_html__('Maximum active websites','dropkey-v2').'</label></th><td><input required type="number" min="1" max="65535" name="activation_limit" value="'.esc_attr($r?$r->activation_limit:1).'"><p class="description">'.esc_html__('This limit applies to each license issued for this tier. Example starting values: Basic 1, Plus 3, Pro 10. These values are editable.','dropkey-v2').'</p></td></tr><tr><th>'.esc_html__('Status','dropkey-v2').'</th><td>'; $this->status_select($r?$r->status:'draft'); echo '</td></tr></table><p><button class="button button-primary">'.esc_html($r?__('Save tier','dropkey-v2'):__('Create tier','dropkey-v2')).'</button></p></form>';
		global $wpdb; $tiers=Database::table('tiers'); $products=Database::table('products'); $rows=$wpdb->get_results("SELECT t.*,p.name AS product_name FROM {$tiers} t INNER JOIN {$products} p ON p.id=t.product_id ORDER BY p.name,t.sort_order,t.id");
		echo '<h2>'.esc_html__('Existing tiers','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Product / tier','dropkey-v2').'</th><th>'.esc_html__('Activations','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th><th>'.esc_html__('Actions','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $x) echo '<tr><td>'.(int)$x->id.'</td><td>'.esc_html($x->product_name).' / <strong>'.esc_html($x->name).'</strong></td><td>'.(int)$x->activation_limit.'</td><td>'.esc_html($x->status).'</td><td><a href="'.esc_url(admin_url('admin.php?page=dk2-tiers&edit='.(int)$x->id)).'">'.esc_html__('Edit','dropkey-v2').'</a> · <a href="'.esc_url(admin_url('admin.php?page=dk2-billing&tier_id='.(int)$x->id)).'">'.esc_html__('Billing options','dropkey-v2').'</a></td></tr>';
		if(!$rows) echo '<tr><td colspan="5">'.esc_html__('No tiers yet.','dropkey-v2').'</td></tr>'; echo '</tbody></table>'; $this->footer();
	}
	public function billing() {
		if (!current_user_can('manage_options')) return;
		$id=isset($_GET['edit'])?absint($_GET['edit']):0; $r=$id?(new Billing_Option_Repository())->get($id):null; $tid=$r?(int)$r->tier_id:(isset($_GET['tier_id'])?absint($_GET['tier_id']):0);
		$this->header(__('Billing Options','dropkey-v2'),__('Configure free, one-time, monthly, or yearly options for each tier. Prices and currency are stored on the billing option. Payment checkout is not part of these milestones.','dropkey-v2'));
		echo '<h2>'.($r?esc_html__('Edit billing option','dropkey-v2'):esc_html__('Add a billing option','dropkey-v2')).'</h2>'; $this->form_start('billing',$id);
		echo '<table class="form-table"><tr><th>'.esc_html__('Tier','dropkey-v2').'</th><td>'; if($r){global $wpdb;$t=Database::table('tiers');$p=Database::table('products');$label=$wpdb->get_var($wpdb->prepare("SELECT CONCAT(p.name,' — ',t.name) FROM {$t} t INNER JOIN {$p} p ON p.id=t.product_id WHERE t.id=%d",$r->tier_id));echo esc_html($label).'<input type="hidden" name="tier_id" value="'.(int)$r->tier_id.'">';}else $this->tier_options($tid); echo '</td></tr><tr><th>'.esc_html__('Billing type','dropkey-v2').'</th><td><select name="billing_type">'; foreach(array('free'=>'Free','one_time'=>'One-time purchase','monthly'=>'Monthly','yearly'=>'Yearly') as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($r?$r->billing_type:'one_time',$k,false).'>'.esc_html($v).'</option>'; echo '</select></td></tr><tr><th><label>'.esc_html__('Price','dropkey-v2').'</label></th><td><input type="number" step="0.01" min="0" name="price" value="'.esc_attr($r?$r->price:'0.00').'"></td></tr><tr><th><label>'.esc_html__('Currency','dropkey-v2').'</label></th><td><input maxlength="3" pattern="[A-Za-z]{3}" name="currency" value="'.esc_attr($r?$r->currency:'USD').'"><p class="description">'.esc_html__('Use a three-letter currency code for paid options. Free options always use zero price and no currency.','dropkey-v2').'</p></td></tr><tr><th>'.esc_html__('PayPal subscription plan','dropkey-v2').'</th><td>'; if($r&&!empty($r->paypal_plan_id)) echo '<p><code>'.esc_html($r->paypal_plan_id).'</code></p><p class="description">'.esc_html__('A PayPal plan is already linked. If you change the billing amount, currency, or interval, DropKey will create a matching replacement plan when PayPal checkout is next used.','dropkey-v2').'</p>'; else echo '<p class="description">'.esc_html__('No manual PayPal plan ID is needed. DropKey will create and save the subscription plan automatically when a customer first checks out through PayPal.','dropkey-v2').'</p>'; echo '</td></tr><tr><th>'.esc_html__('Status','dropkey-v2').'</th><td>'; $this->status_select($r?$r->status:'draft'); echo '</td></tr></table><p><button class="button button-primary">'.esc_html($r?__('Save option','dropkey-v2'):__('Create option','dropkey-v2')).'</button></p></form>';
		global $wpdb; $b=Database::table('billing_options'); $t=Database::table('tiers'); $p=Database::table('products'); $rows=$wpdb->get_results("SELECT b.*,t.name AS tier_name,p.name AS product_name FROM {$b} b INNER JOIN {$t} t ON t.id=b.tier_id INNER JOIN {$p} p ON p.id=t.product_id ORDER BY p.name,t.name,b.id");
		echo '<h2>'.esc_html__('Existing billing options','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Product / tier','dropkey-v2').'</th><th>'.esc_html__('Billing','dropkey-v2').'</th><th>'.esc_html__('Price','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th><th>'.esc_html__('Edit','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $x) echo '<tr><td>'.(int)$x->id.'</td><td>'.esc_html($x->product_name.' / '.$x->tier_name).'</td><td>'.esc_html($x->billing_type).'</td><td>'.('free'===$x->billing_type?esc_html__('Free','dropkey-v2'):esc_html($x->currency.' '.number_format((float)$x->price,2)).' / '.esc_html($x->billing_type)).'</td><td>'.esc_html($x->status).'</td><td><a href="'.esc_url(admin_url('admin.php?page=dk2-billing&edit='.(int)$x->id)).'">'.esc_html__('Edit','dropkey-v2').'</a></td></tr>';
		if(!$rows) echo '<tr><td colspan="6">'.esc_html__('No billing options yet.','dropkey-v2').'</td></tr>'; echo '</tbody></table>'; $this->footer();
	}
	public function releases() {
		if (!current_user_can('manage_options')) return;
		$pid=isset($_GET['product_id'])?absint($_GET['product_id']):0;
		$this->header(__('ZIP Releases','dropkey-v2'),__('Upload versioned ZIP packages to private storage. Publish a release to make it available to the protected download and update API.','dropkey-v2'));
		echo '<div class="notice notice-warning inline"><p>'.esc_html__('Storage safety: configure dk2_private_storage_path to a directory outside the web server document root. The .htaccess safeguard does not protect Nginx by itself.','dropkey-v2').'</p></div>';
		$this->form_start('release',0,true); echo '<table class="form-table"><tr><th>'.esc_html__('Product','dropkey-v2').'</th><td>'; $this->product_options($pid); echo '</td></tr><tr><th><label>'.esc_html__('Version','dropkey-v2').'</label></th><td><input required class="regular-text" name="version" placeholder="1.0.0"><p class="description">'.esc_html__('Use a unique version for each product, for example 1.0.0.','dropkey-v2').'</p></td></tr><tr><th><label>'.esc_html__('ZIP package','dropkey-v2').'</label></th><td><input required type="file" name="package" accept=".zip,application/zip"></td></tr><tr><th><label>'.esc_html__('Release notes','dropkey-v2').'</label></th><td><textarea class="large-text" rows="3" name="release_notes"></textarea></td></tr><tr><th>'.esc_html__('Status','dropkey-v2').'</th><td>'; $this->status_select('draft'); echo '</td></tr></table><p><button class="button button-primary">'.esc_html__('Upload release','dropkey-v2').'</button></p></form>';
		global $wpdb; $r=Database::table('releases'); $p=Database::table('products'); $rows=$wpdb->get_results("SELECT r.*,p.name AS product_name FROM {$r} r INNER JOIN {$p} p ON p.id=r.product_id ORDER BY r.id DESC LIMIT 200");
		echo '<h2>'.esc_html__('Existing releases','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Product','dropkey-v2').'</th><th>'.esc_html__('Version','dropkey-v2').'</th><th>'.esc_html__('Size','dropkey-v2').'</th><th>'.esc_html__('SHA-256','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $x) echo '<tr><td>'.(int)$x->id.'</td><td>'.esc_html($x->product_name).'</td><td>'.esc_html($x->version).'</td><td>'.size_format((int)$x->package_size).'</td><td><code>'.esc_html($x->package_sha256).'</code></td><td>'.esc_html($x->status).'</td></tr>';
		if(!$rows) echo '<tr><td colspan="6">'.esc_html__('No ZIP releases yet.','dropkey-v2').'</td></tr>'; echo '</tbody></table>'; $this->footer();
	}
	public function licenses() {
		if (!current_user_can('manage_options')) return;
		global $wpdb;
		$this->header(__('Licenses','dropkey-v2'),__('Issue test or manually granted licenses for published products and tiers. The full purchase, subscription, and customer-email lifecycle is not implemented yet.','dropkey-v2'));
		if (isset($_GET['reveal_license'])) {
			$reveal_id=absint($_GET['reveal_license']);
			$nonce=isset($_GET['_wpnonce'])?sanitize_text_field(wp_unslash($_GET['_wpnonce'])):'';
			if ($reveal_id&&wp_verify_nonce($nonce,'dk2_reveal_license_'.$reveal_id)) {
				$license_row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('licenses').' WHERE id=%d',$reveal_id));
				$revealed_key=$license_row?(new License_Service())->reveal_key($license_row):'';
				if (''!==$revealed_key) echo '<div class="notice notice-success"><p><strong>'.esc_html__('License key (copy it now):','dropkey-v2').'</strong></p><p><code class="dk2-revealed-key">'.esc_html($revealed_key).'</code></p></div>';
				else echo '<div class="notice notice-error"><p>'.esc_html__('The license key could not be decrypted. Check whether WordPress authentication salts have changed.','dropkey-v2').'</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>'.esc_html__('The key-reveal link expired or was invalid. Reload the licenses page and try again.','dropkey-v2').'</p></div>';
			}
		}
		$this->form_start('license'); echo '<table class="form-table"><tr><th>'.esc_html__('Product','dropkey-v2').'</th><td>'; $this->product_options(); echo '</td></tr><tr><th>'.esc_html__('Tier','dropkey-v2').'</th><td>'; $this->tier_options(); echo '</td></tr><tr><th><label>'.esc_html__('Customer email','dropkey-v2').'</label></th><td><input required type="email" class="regular-text" name="customer_email"></td></tr><tr><th>'.esc_html__('Billing option','dropkey-v2').'</th><td>'; global $wpdb; $opts=Database::table('billing_options'); $tiers=Database::table('tiers'); $products=Database::table('products'); $all=$wpdb->get_results("SELECT b.*,t.name AS tier_name,p.name AS product_name FROM {$opts} b INNER JOIN {$tiers} t ON t.id=b.tier_id INNER JOIN {$products} p ON p.id=t.product_id WHERE b.status='published' ORDER BY p.name,t.name,b.id"); echo '<select name="billing_option_id"><option value="0">'.esc_html__('Not specified','dropkey-v2').'</option>'; foreach($all as $x) echo '<option value="'.(int)$x->id.'">'.esc_html($x->product_name.' — '.$x->tier_name.' — '.$x->billing_type).'</option>'; echo '</select></td></tr><tr><th><label>'.esc_html__('Expiration (optional)','dropkey-v2').'</label></th><td><input type="date" name="expires_at"></td></tr></table><p><button class="button button-primary">'.esc_html__('Issue license','dropkey-v2').'</button></p></form>';
		$lt=Database::table('licenses'); $rows=$wpdb->get_results("SELECT l.*,p.name AS product_name,t.name AS tier_name FROM {$lt} l LEFT JOIN {$products} p ON p.id=l.product_id LEFT JOIN {$tiers} t ON t.id=l.tier_id ORDER BY l.id DESC LIMIT 200");
		echo '<h2>'.esc_html__('Existing licenses','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID / key prefix</th><th>'.esc_html__('Customer','dropkey-v2').'</th><th>'.esc_html__('Product / tier','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th><th>'.esc_html__('Expires','dropkey-v2').'</th><th>'.esc_html__('Actions','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $x) { echo '<tr><td>'.(int)$x->id.'<br><code>'.esc_html($x->key_prefix).'…</code></td><td>'.esc_html($x->customer_email).'</td><td>'.esc_html($x->product_name.' / '.$x->tier_name).'</td><td>'.esc_html($x->status).'</td><td>'.esc_html($x->expires_at?$x->expires_at:__('Never','dropkey-v2')).'</td><td>';
			echo '<p><a class="button button-small" href="'.esc_url(wp_nonce_url(add_query_arg(array('page'=>'dk2-licenses','reveal_license'=>(int)$x->id),admin_url('admin.php')),'dk2_reveal_license_'.(int)$x->id)).'">'.esc_html__('Reveal key','dropkey-v2').'</a></p>';
			foreach(array('active'=>__('Reactivate','dropkey-v2'),'suspended'=>__('Suspend','dropkey-v2'),'revoked'=>__('Revoke','dropkey-v2'),'expired'=>__('Mark expired','dropkey-v2')) as $status=>$label) { $this->form_start('license_status'); echo '<input type="hidden" name="id" value="'.(int)$x->id.'"><input type="hidden" name="status" value="'.esc_attr($status).'"><button class="button button-small">'.esc_html($label).'</button></form> '; }
			$this->form_start('download_link'); echo '<input type="hidden" name="license_id" value="'.(int)$x->id.'"><label>'.esc_html__('Release','dropkey-v2').' <select name="release_id">'; $rels=Database::table('releases'); $available=$wpdb->get_results($wpdb->prepare("SELECT id,version FROM {$rels} WHERE product_id=%d AND status='published' ORDER BY id DESC",$x->product_id)); foreach($available as $rel) echo '<option value="'.(int)$rel->id.'">'.esc_html($rel->version).'</option>'; echo '</select></label> <button class="button button-small">'.esc_html__('Create download link','dropkey-v2').'</button></form></td></tr>';
		}
		if(!$rows) echo '<tr><td colspan="6">'.esc_html__('No licenses yet.','dropkey-v2').'</td></tr>'; echo '</tbody></table>';
		$acts=Database::table('activations'); $active=$wpdb->get_results("SELECT a.*,l.customer_email,l.key_prefix,p.name AS product_name FROM {$acts} a INNER JOIN {$lt} l ON l.id=a.license_id INNER JOIN {$products} p ON p.id=l.product_id WHERE a.status='active' ORDER BY a.id DESC LIMIT 200");
		echo '<h2>'.esc_html__('Active website activations','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Product / license','dropkey-v2').'</th><th>'.esc_html__('Customer','dropkey-v2').'</th><th>'.esc_html__('Site URL','dropkey-v2').'</th><th>'.esc_html__('Activated','dropkey-v2').'</th><th>'.esc_html__('Action','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($active as $act) { echo '<tr><td>'.(int)$act->id.'</td><td>'.esc_html($act->product_name.' / '.$act->key_prefix.'…').'</td><td>'.esc_html($act->customer_email).'</td><td>'.esc_html($act->site_url).'</td><td>'.esc_html($act->activated_at).'</td><td>'; $this->form_start('activation_status'); echo '<input type="hidden" name="id" value="'.(int)$act->id.'"><input type="hidden" name="status" value="inactive"><button class="button button-small">'.esc_html__('Deactivate site','dropkey-v2').'</button></form></td></tr>'; }
		if(!$active) echo '<tr><td colspan="6">'.esc_html__('No active website activations.','dropkey-v2').'</td></tr>'; echo '</tbody></table>'; $this->footer();
	}

	public function settings() {
		if (!current_user_can('manage_options')) return;
		$all=get_option('dk2_payment_settings',array()); $stripe=isset($all['stripe'])?$all['stripe']:array(); $paypal=isset($all['paypal'])?$all['paypal']:array();
		$this->header(__('Payment Settings','dropkey-v2'),__('Configure hosted PayPal and Stripe checkout. Credentials are stored in this WordPress installation and must never be committed to source control. Start in sandbox/test mode.','dropkey-v2'));
		echo '<div class="notice notice-warning inline"><p>'.esc_html__('Do not enable live payments until provider credentials, webhook delivery, refund/dispute handling, and checkout have been tested end to end. DropKey never stores card details.','dropkey-v2').'</p></div>';
		$this->form_start('gateway_settings');
		echo '<h2>'.esc_html__('Stripe','dropkey-v2').'</h2><table class="form-table"><tr><th>'.esc_html__('Enable Stripe','dropkey-v2').'</th><td><label><input type="checkbox" name="stripe_enabled" value="1" '.checked(isset($stripe['enabled'])?$stripe['enabled']:'0','1',false).'> '.esc_html__('Allow customers to pay through Stripe Checkout','dropkey-v2').'</label></td></tr><tr><th><label>'.esc_html__('Secret key','dropkey-v2').'</label></th><td><input type="password" class="regular-text" name="stripe_secret" autocomplete="new-password" placeholder="'.esc_attr(!empty($stripe['secret'])?__('Saved — leave blank to keep current value','dropkey-v2'):__('Paste Stripe secret key','dropkey-v2')).'"><p class="description">'.esc_html__('Use a Stripe test secret key until test checkout and webhooks pass.','dropkey-v2').'</p></td></tr><tr><th><label>'.esc_html__('Webhook signing secret','dropkey-v2').'</label></th><td><input type="password" class="regular-text" name="stripe_webhook_secret" autocomplete="new-password" placeholder="'.esc_attr(!empty($stripe['webhook_secret'])?__('Saved — leave blank to keep current value','dropkey-v2'):__('Paste webhook signing secret','dropkey-v2')).'"><p class="description">'.esc_html__('Webhook endpoint:','dropkey-v2').' <code>'.esc_html(rest_url('dropkey/v1/webhooks/stripe')).'</code></p></td></tr></table>';
		echo '<h2>'.esc_html__('PayPal','dropkey-v2').'</h2><table class="form-table"><tr><th>'.esc_html__('Enable PayPal','dropkey-v2').'</th><td><label><input type="checkbox" name="paypal_enabled" value="1" '.checked(isset($paypal['enabled'])?$paypal['enabled']:'0','1',false).'> '.esc_html__('Allow customers to pay through PayPal','dropkey-v2').'</label></td></tr><tr><th>'.esc_html__('Environment','dropkey-v2').'</th><td><label><input type="checkbox" name="paypal_live_mode" value="1" '.checked(isset($paypal['live_mode'])?$paypal['live_mode']:'0','1',false).'> '.esc_html__('Live mode (leave unchecked for sandbox)','dropkey-v2').'</label></td></tr><tr><th><label>'.esc_html__('Client ID','dropkey-v2').'</label></th><td><input type="text" class="regular-text" name="paypal_client_id" autocomplete="off" placeholder="'.esc_attr(!empty($paypal['client_id'])?__('Saved — leave blank to keep current value','dropkey-v2'):__('Paste PayPal client ID','dropkey-v2')).'"></td></tr><tr><th><label>'.esc_html__('Client secret','dropkey-v2').'</label></th><td><input type="password" class="regular-text" name="paypal_secret" autocomplete="new-password" placeholder="'.esc_attr(!empty($paypal['secret'])?__('Saved — leave blank to keep current value','dropkey-v2'):__('Paste PayPal client secret','dropkey-v2')).'"></td></tr><tr><th><label>'.esc_html__('Webhook ID','dropkey-v2').'</label></th><td><input type="text" class="regular-text" name="paypal_webhook_id" placeholder="'.esc_attr(!empty($paypal['webhook_id'])?__('Saved — leave blank to keep current value','dropkey-v2'):__('Paste webhook ID','dropkey-v2')).'"><p class="description">'.esc_html__('Webhook endpoint:','dropkey-v2').' <code>'.esc_html(rest_url('dropkey/v1/webhooks/paypal')).'</code></p></td></tr></table><p><button class="button button-primary">'.esc_html__('Save payment settings','dropkey-v2').'</button></p></form>';
		$this->footer();
	}
	public function orders() {
		if (!current_user_can('manage_options')) return;
		global $wpdb; $orders=Database::table('orders'); $products=Database::table('products'); $tiers=Database::table('tiers'); $emails=Database::table('email_queue');
		$this->header(__('Orders','dropkey-v2'),__('Review checkout orders and transactional email delivery. Payment-provider events are processed idempotently; a browser return alone does not grant paid access.','dropkey-v2'));
		$rows=$wpdb->get_results("SELECT o.*,p.name AS product_name,t.name AS tier_name FROM {$orders} o LEFT JOIN {$products} p ON p.id=o.product_id LEFT JOIN {$tiers} t ON t.id=o.tier_id ORDER BY o.id DESC LIMIT 200");
		echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('Order','dropkey-v2').'</th><th>'.esc_html__('Customer','dropkey-v2').'</th><th>'.esc_html__('Product / tier','dropkey-v2').'</th><th>'.esc_html__('Amount','dropkey-v2').'</th><th>'.esc_html__('Gateway','dropkey-v2').'</th><th>'.esc_html__('Order status','dropkey-v2').'</th><th>'.esc_html__('Email delivery','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $row) {
			$mail=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$emails} WHERE order_id=%d ORDER BY id DESC LIMIT 1",$row->id));
			echo '<tr><td><code>'.esc_html(substr($row->public_id,0,8)).'…</code><br><small>'.esc_html($row->created_at).'</small></td><td>'.esc_html($row->customer_email).'</td><td>'.esc_html($row->product_name.' / '.$row->tier_name).'</td><td>'.esc_html($row->currency.' '.number_format((float)$row->amount,2)).'</td><td>'.esc_html($row->gateway).'</td><td><span class="dk2-status dk2-status-'.esc_attr(sanitize_html_class($row->status)).'">'.esc_html($row->status).'</span></td><td>';
			if (!$mail) echo esc_html__('Not queued','dropkey-v2');
			else { echo '<span class="dk2-status dk2-status-'.esc_attr(sanitize_html_class($mail->status)).'">'.esc_html($mail->status).'</span>'; if ('failed'===$mail->status) { $this->form_start('email_resend'); echo '<input type="hidden" name="email_id" value="'.(int)$mail->id.'"><button class="button button-small">'.esc_html__('Retry email','dropkey-v2').'</button></form>'; } }
			echo '</td></tr>';
		}
		if (!$rows) echo '<tr><td colspan="7">'.esc_html__('No orders yet.','dropkey-v2').'</td></tr>';
		echo '</tbody></table>';

		$subs=Database::table('subscriptions');
		$subscription_rows=$wpdb->get_results("SELECT s.*,o.customer_email,p.name AS product_name,t.name AS tier_name FROM {$subs} s INNER JOIN {$orders} o ON o.id=s.order_id LEFT JOIN {$products} p ON p.id=o.product_id LEFT JOIN {$tiers} t ON t.id=o.tier_id ORDER BY s.id DESC LIMIT 200");
		echo '<h2>'.esc_html__('Subscriptions','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Customer / product','dropkey-v2').'</th><th>'.esc_html__('Provider','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th><th>'.esc_html__('Paid through','dropkey-v2').'</th><th>'.esc_html__('Cancellation','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($subscription_rows as $sub) {
			echo '<tr><td>'.(int)$sub->id.'</td><td>'.esc_html($sub->customer_email).'<br>'.esc_html($sub->product_name.' / '.$sub->tier_name).'</td><td>'.esc_html($sub->gateway).'</td><td><span class="dk2-status dk2-status-'.esc_attr(sanitize_html_class($sub->status)).'">'.esc_html($sub->status).'</span></td><td>'.esc_html($sub->current_period_end?$sub->current_period_end:__('Not reported','dropkey-v2')).'</td><td>';
			if (in_array($sub->status,array('active','past_due'),true)&&!empty($sub->current_period_end)&&empty($sub->cancel_at_period_end)) { $this->form_start('subscription_cancel'); echo '<input type="hidden" name="subscription_id" value="'.(int)$sub->id.'"><button class="button button-small">'.esc_html__('Cancel at period end','dropkey-v2').'</button></form>'; }
			elseif (!empty($sub->cancel_at_period_end)) echo esc_html__('Scheduled','dropkey-v2');
			else echo '—';
			echo '</td></tr>';
		}
		if (!$subscription_rows) echo '<tr><td colspan="6">'.esc_html__('No subscriptions yet.','dropkey-v2').'</td></tr>';
		echo '</tbody></table>';
		$this->footer();
	}

	public function logs() {
		if (!current_user_can('manage_options')) return;
		global $wpdb;
		$table=Database::table('logs');
		$level=isset($_GET['level'])?sanitize_key(wp_unslash($_GET['level'])):'';
		$levels=array('debug','info','warning','error','critical');
		if (!in_array($level,$levels,true)) $level='';
		$page=max(1,isset($_GET['log_page'])?absint($_GET['log_page']):1);
		$per_page=100; $offset=($page-1)*$per_page;
		$where=''; $params=array();
		if (''!==$level) { $where=' WHERE level=%s'; $params[]=$level; }
		$count_sql="SELECT COUNT(*) FROM {$table}{$where}";
		$total=(int)(''!==$where?$wpdb->get_var($wpdb->prepare($count_sql,$params)):$wpdb->get_var($count_sql));
		$list_sql="SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[]=$per_page; $params[]=$offset;
		$rows=$wpdb->get_results($wpdb->prepare($list_sql,$params));
		$this->header(__('Debug Logs','dropkey-v2'),__('Review DropKey activity, warnings, errors, and payment diagnostics. Sensitive values and request payloads are intentionally excluded from structured logs.','dropkey-v2'));
		echo '<div class="notice notice-info inline"><p>'.esc_html__('Logs are stored in the WordPress database for up to 30 days. This screen shows the newest 100 entries per page. PHP-level diagnostics may also appear in your hosting PHP error log.','dropkey-v2').'</p></div>';
		echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin:16px 0"><input type="hidden" name="page" value="dk2-logs"><label for="dk2-log-level">'.esc_html__('Level','dropkey-v2').' </label><select id="dk2-log-level" name="level"><option value="">'.esc_html__('All levels','dropkey-v2').'</option>';
		foreach($levels as $item) echo '<option value="'.esc_attr($item).'" '.selected($level,$item,false).'>'.esc_html(ucfirst($item)).'</option>';
		echo '</select> <button class="button">'.esc_html__('Filter','dropkey-v2').'</button></form>';
		$this->form_start('logs_settings');
		echo '<p><label><input type="checkbox" name="logging_enabled" value="1" '.checked(get_option('dk2_logging_enabled','1'),'1',false).'> '.esc_html__('Enable structured DropKey logging','dropkey-v2').'</label> <button class="button button-primary">'.esc_html__('Save logging setting','dropkey-v2').'</button></p></form>';
		$this->form_start('logs_clear');
		echo '<p><button class="button button-secondary" onclick="return confirm(\''.esc_js(__('Delete all DropKey debug log entries? This cannot be undone.','dropkey-v2')).'\')">'.esc_html__('Clear all logs','dropkey-v2').'</button></p></form>';
		echo '<p>'.sprintf(esc_html__('%1$s log entries · page %2$d of %3$d','dropkey-v2'),number_format_i18n($total),$page,max(1,(int)ceil($total/$per_page))).'</p>';
		echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('Time (UTC)','dropkey-v2').'</th><th>'.esc_html__('Level','dropkey-v2').'</th><th>'.esc_html__('Source / event','dropkey-v2').'</th><th>'.esc_html__('Message / context','dropkey-v2').'</th><th>'.esc_html__('Request ID','dropkey-v2').'</th><th>'.esc_html__('User ID','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $row) {
			$context=json_decode($row->context,true); $context_text=is_array($context)?wp_json_encode($context,JSON_PRETTY_PRINT):'{}';
			echo '<tr><td>'.esc_html($row->created_at).'</td><td><strong>'.esc_html(strtoupper($row->level)).'</strong></td><td><code>'.esc_html($row->source).'</code><br><code>'.esc_html($row->event).'</code></td><td>'.esc_html($row->message);
			if ($context_text&&'{}'!==$context_text) echo '<details><summary>'.esc_html__('Details','dropkey-v2').'</summary><pre style="white-space:pre-wrap;max-width:640px">'.esc_html($context_text).'</pre></details>';
			echo '</td><td><code>'.esc_html($row->request_id).'</code></td><td>'.(null===$row->user_id?'—':(int)$row->user_id).'</td></tr>';
		}
		if (!$rows) echo '<tr><td colspan="6">'.esc_html__('No log entries match this filter.','dropkey-v2').'</td></tr>';
		echo '</tbody></table>';
		if ($total>$per_page) {
			$base=add_query_arg(array('page'=>'dk2-logs','level'=>$level,'log_page'=>'%#%'),admin_url('admin.php'));
			echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>str_replace('%25%23%25', '%#%', esc_url($base)),'format'=>'','current'=>$page,'total'=>(int)ceil($total/$per_page),'prev_text'=>__('‹ Previous','dropkey-v2'),'next_text'=>__('Next ›','dropkey-v2')))).'</div></div>';
		}
		$this->footer();
	}

	public function handle() {
		$this->guard(); global $wpdb; $action=isset($_POST['action'])?sanitize_key(wp_unslash($_POST['action'])):'';
		$id=isset($_POST['id'])?absint($_POST['id']):0; $page='dk2-products';
		Logger::info('admin','admin_action_started','Admin action started',array('action'=>$action,'record_id'=>$id));
		if ('dk2_logs_settings'===$action) {
			update_option('dk2_logging_enabled',isset($_POST['logging_enabled'])?'1':'0',false);
			if (isset($_POST['logging_enabled'])) Logger::info('admin','logging_enabled','Structured logging enabled');
			wp_safe_redirect(add_query_arg(array('page'=>'dk2-logs','dk2_notice'=>'saved'),admin_url('admin.php'))); exit;
		} elseif ('dk2_logs_clear'===$action) {
			$wpdb->query('DELETE FROM '.Database::table('logs'));
			Logger::info('admin','logs_cleared','Administrator cleared the DropKey log history');
			wp_safe_redirect(add_query_arg(array('page'=>'dk2-logs','dk2_notice'=>'logs_cleared'),admin_url('admin.php'))); exit;
		} elseif ('dk2_subscription_cancel'===$action) {
			$page='dk2-orders'; $result=(new Commerce_Service())->request_cancel_subscription(absint($_POST['subscription_id']??0)); if (!is_wp_error($result)) { Logger::info('admin','admin_action_completed','Admin action completed',array('action'=>$action,'record_id'=>$id)); $this->redirect($page,'saved'); }
		} elseif ('dk2_gateway_settings'===$action) {
			$page='dk2-settings'; $all=get_option('dk2_payment_settings',array()); $all=is_array($all)?$all:array();
			$stripe=isset($all['stripe'])?$all['stripe']:array(); $paypal=isset($all['paypal'])?$all['paypal']:array();
			$new_stripe=array('enabled'=>isset($_POST['stripe_enabled'])?'1':'0','secret'=>sanitize_text_field(wp_unslash($_POST['stripe_secret']??'')),'webhook_secret'=>sanitize_text_field(wp_unslash($_POST['stripe_webhook_secret']??'')));
			$new_paypal=array('enabled'=>isset($_POST['paypal_enabled'])?'1':'0','live_mode'=>isset($_POST['paypal_live_mode'])?'1':'0','client_id'=>sanitize_text_field(wp_unslash($_POST['paypal_client_id']??'')),'secret'=>sanitize_text_field(wp_unslash($_POST['paypal_secret']??'')),'webhook_id'=>sanitize_text_field(wp_unslash($_POST['paypal_webhook_id']??'')));
			foreach(array('secret','webhook_secret') as $key) if (''===$new_stripe[$key]&&!empty($stripe[$key])) $new_stripe[$key]=$stripe[$key];
			foreach(array('client_id','secret','webhook_id') as $key) if (''===$new_paypal[$key]&&!empty($paypal[$key])) $new_paypal[$key]=$paypal[$key];
			if ('1'===$new_stripe['enabled']&&(''===$new_stripe['secret']||''===$new_stripe['webhook_secret'])) $result=new \WP_Error('dk2_stripe_settings_required',__('Stripe requires both a secret key and webhook signing secret before it can be enabled.','dropkey-v2'));
			elseif ('1'===$new_paypal['enabled']&&(''===$new_paypal['client_id']||''===$new_paypal['secret']||''===$new_paypal['webhook_id'])) $result=new \WP_Error('dk2_paypal_settings_required',__('PayPal requires a client ID, client secret, and webhook ID before it can be enabled.','dropkey-v2'));
			else { $all['stripe']=$new_stripe; $all['paypal']=$new_paypal; update_option('dk2_payment_settings',$all,false); Logger::info('admin','gateway_settings_saved','Payment gateway settings saved',array('stripe_enabled'=>$new_stripe['enabled'],'paypal_enabled'=>$new_paypal['enabled'],'paypal_live_mode'=>$new_paypal['live_mode'])); $this->redirect($page,'saved'); }
		} elseif ('dk2_email_resend'===$action) {
			$page='dk2-orders'; $result=(new Email_Service())->resend(absint($_POST['email_id']??0)); if (!is_wp_error($result)) $this->redirect($page,'saved');
		} elseif ('dk2_product'===$action) {
			$data=array('name'=>isset($_POST['name'])?wp_unslash($_POST['name']):'','slug'=>isset($_POST['slug'])?wp_unslash($_POST['slug']):'','description'=>isset($_POST['description'])?wp_unslash($_POST['description']):'','status'=>isset($_POST['status'])?sanitize_key($_POST['status']):'draft');
			$repo=new Product_Repository(); $result=$id?$repo->update($id,$data):$repo->create($data); $page='dk2-products';
		} elseif ('dk2_tier'===$action) {
			$data=array('product_id'=>absint($_POST['product_id']??0),'name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'slug'=>sanitize_title(wp_unslash($_POST['slug']??'')),'description'=>wp_kses_post(wp_unslash($_POST['description']??'')),'activation_limit'=>absint($_POST['activation_limit']??0),'status'=>sanitize_key($_POST['status']??'draft'));
			$repo=new Tier_Repository(); $result=$id?$repo->update($id,$data):$repo->create($data); $page='dk2-tiers';
		} elseif ('dk2_billing'===$action) {
			$data=array('tier_id'=>absint($_POST['tier_id']??0),'billing_type'=>sanitize_key($_POST['billing_type']??''),'price'=>sanitize_text_field(wp_unslash($_POST['price']??'')),'currency'=>sanitize_text_field(wp_unslash($_POST['currency']??'')),'status'=>sanitize_key($_POST['status']??'draft'));
			$repo=new Billing_Option_Repository(); $result=$id?$repo->update($id,$data):$repo->create($data); $page='dk2-billing';
		} elseif ('dk2_release'===$action) {
			$page='dk2-releases'; $pid=absint($_POST['product_id']??0); $version=sanitize_text_field(wp_unslash($_POST['version']??'')); $status=sanitize_key($_POST['status']??'draft'); $notes=wp_kses_post(wp_unslash($_POST['release_notes']??''));
			$existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Database::table('products').' WHERE id=%d AND status=%s',$pid,'published'));
			if (!$existing||!preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,49}$/',$version)||!in_array($status,array('draft','published','archived'),true)) $result=new \WP_Error('dk2_release_data_invalid',__('Choose a published product and a valid unique release version.','dropkey-v2'));
			elseif ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Database::table('releases').' WHERE product_id=%d AND version=%s',$pid,$version))) $result=new \WP_Error('dk2_release_version_exists',__('That version already exists for this product.','dropkey-v2'));
			else { $package=Private_Storage::save_upload($_FILES['package']??array()); if(is_wp_error($package)) $result=$package; else { $ok=$wpdb->insert(Database::table('releases'),array('product_id'=>$pid,'version'=>$version,'package_path'=>$package['path'],'package_sha256'=>$package['sha256'],'package_size'=>$package['size'],'release_notes'=>$notes,'status'=>$status,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true))); if(!$ok){@unlink($package['path']);$result=new \WP_Error('dk2_release_save_failed',__('Release metadata could not be saved.','dropkey-v2'));}else{do_action('dk2_release_created',(int)$wpdb->insert_id,$pid,$version);$result=(int)$wpdb->insert_id;} } }
		} elseif ('dk2_license'===$action) {
			$page='dk2-licenses'; $result=(new License_Service())->issue(absint($_POST['product_id']??0),absint($_POST['tier_id']??0),sanitize_email(wp_unslash($_POST['customer_email']??'')),absint($_POST['billing_option_id']??0),sanitize_text_field(wp_unslash($_POST['expires_at']??'')));
			if (!is_wp_error($result)) { Logger::info('admin','license_issued','License issued by administrator',array('license_id'=>isset($result['id'])?(int)$result['id']:0)); $this->redirect($page,'key',array('license_key'=>$result['license_key'])); }
		} elseif ('dk2_license_status'===$action) {
			$page='dk2-licenses'; $result=(new License_Service())->set_status($id,sanitize_key($_POST['status']??''));
		} elseif ('dk2_download_link'===$action) {
			$page='dk2-licenses'; $result=(new Download_Service())->create_token(absint($_POST['license_id']??0),absint($_POST['release_id']??0));
			if (!is_wp_error($result)) { Logger::info('admin','download_link_created','Protected download link created',array('license_id'=>absint($_POST['license_id']??0),'release_id'=>absint($_POST['release_id']??0))); $this->redirect($page,'link',array('url'=>$result['url'])); }
		} elseif ('dk2_activation_status'===$action) {
			$page='dk2-licenses'; $activation_id=absint($_POST['id']??0); $status=sanitize_key($_POST['status']??'');
			if ('inactive'!==$status) $result=new \WP_Error('dk2_activation_status_invalid',__('Only deactivation is supported from this screen.','dropkey-v2'));
			else { $result=$wpdb->update(Database::table('activations'),array('status'=>'inactive','deactivated_at'=>current_time('mysql',true)),array('id'=>$activation_id,'status'=>'active')); if(false!==$result) $result=true; else $result=new \WP_Error('dk2_activation_update_failed',__('The activation could not be deactivated.','dropkey-v2')); }
		} else { wp_die(esc_html__('Unknown DropKey action.','dropkey-v2')); }
		if (is_wp_error($result)) { Logger::error('admin','admin_action_failed','Admin action failed',array('action'=>$action,'record_id'=>$id,'error_code'=>$result->get_error_code())); $this->redirect($page,'error',array('message'=>rawurlencode($result->get_error_message()))); }
		Logger::info('admin','admin_action_completed','Admin action completed',array('action'=>$action,'record_id'=>$id));
		$this->redirect($page,$id?'saved':'created');
	}
}