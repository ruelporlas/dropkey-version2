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
	}
	public function register_handlers() {
		foreach(array('product','tier','billing','release','license','license_status','download_link','activation_status') as $action) add_action('admin_post_dk2_'.$action,array($this,'handle'));
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
		$messages=array('saved'=>__('Changes saved.','dropkey-v2'),'created'=>__('Record created. Copy any license key now; it is only shown once.','dropkey-v2'),'status'=>__('Status updated.','dropkey-v2'),'link'=>__('A protected download link was created.','dropkey-v2'));
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
	public function products() {
		if (!current_user_can('manage_options')) return;
		$id=isset($_GET['edit'])?absint($_GET['edit']):0; $r=$id?(new Product_Repository())->get($id):null;
		$this->header(__('Products','dropkey-v2'),__('Create and publish digital products. A published product can contain multiple configurable tiers and versioned ZIP releases.','dropkey-v2'));
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
		echo '<table class="form-table"><tr><th>'.esc_html__('Tier','dropkey-v2').'</th><td>'; if($r){global $wpdb;$t=Database::table('tiers');$p=Database::table('products');$label=$wpdb->get_var($wpdb->prepare("SELECT CONCAT(p.name,' — ',t.name) FROM {$t} t INNER JOIN {$p} p ON p.id=t.product_id WHERE t.id=%d",$r->tier_id));echo esc_html($label).'<input type="hidden" name="tier_id" value="'.(int)$r->tier_id.'">';}else $this->tier_options($tid); echo '</td></tr><tr><th>'.esc_html__('Billing type','dropkey-v2').'</th><td><select name="billing_type">'; foreach(array('free'=>'Free','one_time'=>'One-time purchase','monthly'=>'Monthly','yearly'=>'Yearly') as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($r?$r->billing_type:'one_time',$k,false).'>'.esc_html($v).'</option>'; echo '</select></td></tr><tr><th><label>'.esc_html__('Price','dropkey-v2').'</label></th><td><input type="number" step="0.01" min="0" name="price" value="'.esc_attr($r?$r->price:'0.00').'"></td></tr><tr><th><label>'.esc_html__('Currency','dropkey-v2').'</label></th><td><input maxlength="3" pattern="[A-Za-z]{3}" name="currency" value="'.esc_attr($r?$r->currency:'USD').'"><p class="description">'.esc_html__('Use a three-letter currency code for paid options. Free options always use zero price and no currency.','dropkey-v2').'</p></td></tr><tr><th>'.esc_html__('Status','dropkey-v2').'</th><td>'; $this->status_select($r?$r->status:'draft'); echo '</td></tr></table><p><button class="button button-primary">'.esc_html($r?__('Save option','dropkey-v2'):__('Create option','dropkey-v2')).'</button></p></form>';
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
		$this->header(__('Licenses','dropkey-v2'),__('Issue test or manually granted licenses for published products and tiers. The full purchase, subscription, and customer-email lifecycle is not implemented yet.','dropkey-v2'));
		$this->form_start('license'); echo '<table class="form-table"><tr><th>'.esc_html__('Product','dropkey-v2').'</th><td>'; $this->product_options(); echo '</td></tr><tr><th>'.esc_html__('Tier','dropkey-v2').'</th><td>'; $this->tier_options(); echo '</td></tr><tr><th><label>'.esc_html__('Customer email','dropkey-v2').'</label></th><td><input required type="email" class="regular-text" name="customer_email"></td></tr><tr><th>'.esc_html__('Billing option','dropkey-v2').'</th><td>'; global $wpdb; $opts=Database::table('billing_options'); $tiers=Database::table('tiers'); $products=Database::table('products'); $all=$wpdb->get_results("SELECT b.*,t.name AS tier_name,p.name AS product_name FROM {$opts} b INNER JOIN {$tiers} t ON t.id=b.tier_id INNER JOIN {$products} p ON p.id=t.product_id WHERE b.status='published' ORDER BY p.name,t.name,b.id"); echo '<select name="billing_option_id"><option value="0">'.esc_html__('Not specified','dropkey-v2').'</option>'; foreach($all as $x) echo '<option value="'.(int)$x->id.'">'.esc_html($x->product_name.' — '.$x->tier_name.' — '.$x->billing_type).'</option>'; echo '</select></td></tr><tr><th><label>'.esc_html__('Expiration (optional)','dropkey-v2').'</label></th><td><input type="date" name="expires_at"></td></tr></table><p><button class="button button-primary">'.esc_html__('Issue license','dropkey-v2').'</button></p></form>';
		$lt=Database::table('licenses'); $rows=$wpdb->get_results("SELECT l.*,p.name AS product_name,t.name AS tier_name FROM {$lt} l LEFT JOIN {$products} p ON p.id=l.product_id LEFT JOIN {$tiers} t ON t.id=l.tier_id ORDER BY l.id DESC LIMIT 200");
		echo '<h2>'.esc_html__('Existing licenses','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID / key prefix</th><th>'.esc_html__('Customer','dropkey-v2').'</th><th>'.esc_html__('Product / tier','dropkey-v2').'</th><th>'.esc_html__('Status','dropkey-v2').'</th><th>'.esc_html__('Expires','dropkey-v2').'</th><th>'.esc_html__('Actions','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($rows as $x) { echo '<tr><td>'.(int)$x->id.'<br><code>'.esc_html($x->key_prefix).'…</code></td><td>'.esc_html($x->customer_email).'</td><td>'.esc_html($x->product_name.' / '.$x->tier_name).'</td><td>'.esc_html($x->status).'</td><td>'.esc_html($x->expires_at?$x->expires_at:__('Never','dropkey-v2')).'</td><td>';
			foreach(array('active'=>__('Reactivate','dropkey-v2'),'suspended'=>__('Suspend','dropkey-v2'),'revoked'=>__('Revoke','dropkey-v2'),'expired'=>__('Mark expired','dropkey-v2')) as $status=>$label) { $this->form_start('license_status'); echo '<input type="hidden" name="id" value="'.(int)$x->id.'"><input type="hidden" name="status" value="'.esc_attr($status).'"><button class="button button-small">'.esc_html($label).'</button></form> '; }
			$this->form_start('download_link'); echo '<input type="hidden" name="license_id" value="'.(int)$x->id.'"><label>'.esc_html__('Release','dropkey-v2').' <select name="release_id">'; $rels=Database::table('releases'); $available=$wpdb->get_results($wpdb->prepare("SELECT id,version FROM {$rels} WHERE product_id=%d AND status='published' ORDER BY id DESC",$x->product_id)); foreach($available as $rel) echo '<option value="'.(int)$rel->id.'">'.esc_html($rel->version).'</option>'; echo '</select></label> <button class="button button-small">'.esc_html__('Create download link','dropkey-v2').'</button></form></td></tr>';
		}
		if(!$rows) echo '<tr><td colspan="6">'.esc_html__('No licenses yet.','dropkey-v2').'</td></tr>'; echo '</tbody></table>';
		$acts=Database::table('activations'); $active=$wpdb->get_results("SELECT a.*,l.customer_email,l.key_prefix,p.name AS product_name FROM {$acts} a INNER JOIN {$lt} l ON l.id=a.license_id INNER JOIN {$products} p ON p.id=l.product_id WHERE a.status='active' ORDER BY a.id DESC LIMIT 200");
		echo '<h2>'.esc_html__('Active website activations','dropkey-v2').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>'.esc_html__('Product / license','dropkey-v2').'</th><th>'.esc_html__('Customer','dropkey-v2').'</th><th>'.esc_html__('Site URL','dropkey-v2').'</th><th>'.esc_html__('Activated','dropkey-v2').'</th><th>'.esc_html__('Action','dropkey-v2').'</th></tr></thead><tbody>';
		foreach($active as $act) { echo '<tr><td>'.(int)$act->id.'</td><td>'.esc_html($act->product_name.' / '.$act->key_prefix.'…').'</td><td>'.esc_html($act->customer_email).'</td><td>'.esc_html($act->site_url).'</td><td>'.esc_html($act->activated_at).'</td><td>'; $this->form_start('activation_status'); echo '<input type="hidden" name="id" value="'.(int)$act->id.'"><input type="hidden" name="status" value="inactive"><button class="button button-small">'.esc_html__('Deactivate site','dropkey-v2').'</button></form></td></tr>'; }
		if(!$active) echo '<tr><td colspan="6">'.esc_html__('No active website activations.','dropkey-v2').'</td></tr>'; echo '</tbody></table>'; $this->footer();
	}
	public function handle() {
		$this->guard(); global $wpdb; $action=isset($_POST['action'])?sanitize_key(wp_unslash($_POST['action'])):'';
		$id=isset($_POST['id'])?absint($_POST['id']):0; $page='dk2-products';
		if ('dk2_product'===$action) {
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
			if (!is_wp_error($result)) $this->redirect($page,'key',array('license_key'=>$result['license_key']));
		} elseif ('dk2_license_status'===$action) {
			$page='dk2-licenses'; $result=(new License_Service())->set_status($id,sanitize_key($_POST['status']??''));
		} elseif ('dk2_download_link'===$action) {
			$page='dk2-licenses'; $result=(new Download_Service())->create_token(absint($_POST['license_id']??0),absint($_POST['release_id']??0));
			if (!is_wp_error($result)) $this->redirect($page,'link',array('url'=>$result['url']));
		} elseif ('dk2_activation_status'===$action) {
			$page='dk2-licenses'; $activation_id=absint($_POST['id']??0); $status=sanitize_key($_POST['status']??'');
			if ('inactive'!==$status) $result=new \\WP_Error('dk2_activation_status_invalid',__('Only deactivation is supported from this screen.','dropkey-v2'));
			else { $result=$wpdb->update(Database::table('activations'),array('status'=>'inactive','deactivated_at'=>current_time('mysql',true)),array('id'=>$activation_id,'status'=>'active')); if(false!==$result) $result=true; else $result=new \\WP_Error('dk2_activation_update_failed',__('The activation could not be deactivated.','dropkey-v2')); }
		} else { wp_die(esc_html__('Unknown DropKey action.','dropkey-v2')); }
		if (is_wp_error($result)) $this->redirect($page,'error',array('message'=>rawurlencode($result->get_error_message())));
		$this->redirect($page,$id?'saved':'created');
	}
}