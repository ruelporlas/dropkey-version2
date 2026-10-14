<?php
/**
 * License issuance and site activation service.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class License_Service {
	public function issue($product_id,$tier_id,$email,$billing_option_id=0,$expires_at='',$customer_user_id=0) {
		global $wpdb;
		$product_id=absint($product_id); $tier_id=absint($tier_id); $email=sanitize_email($email);
		$p=Database::table('products'); $t=Database::table('tiers'); $b=Database::table('billing_options');
		$product=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p} WHERE id=%d",$product_id));
		$tier=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND product_id=%d",$tier_id,$product_id));
		if (!$product||'published'!==$product->status||!$tier||'published'!==$tier->status) return new \WP_Error('dk2_license_product_tier_invalid',__('Choose a published tier belonging to a published product.','dropkey-v2'));
		if (!is_email($email)) return new \WP_Error('dk2_license_email_invalid',__('Enter a valid customer email address.','dropkey-v2'));
		$billing_option_id=absint($billing_option_id);
		if ($billing_option_id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$b} WHERE id=%d AND tier_id=%d AND status='published'",$billing_option_id,$tier_id))) return new \WP_Error('dk2_license_billing_invalid',__('Choose a published billing option belonging to this tier.','dropkey-v2'));
		$key='DK2-'.strtoupper(wp_generate_password(8,false,false)).'-'.strtoupper(wp_generate_password(8,false,false));
		$encrypted=$this->encrypt_key($key); if (is_wp_error($encrypted)) return $encrypted;
		$now=current_time('mysql',true); $expiry=null;
		if (''!==$expires_at) { $stamp=strtotime($expires_at.' UTC'); if (!$stamp) return new \WP_Error('dk2_license_expiry_invalid',__('Enter a valid expiration date.','dropkey-v2')); $expiry=gmdate('Y-m-d H:i:s',$stamp); }
		$ok=$wpdb->insert(Database::table('licenses'),array('product_id'=>$product_id,'tier_id'=>$tier_id,'billing_option_id'=>$billing_option_id?:null,'customer_email'=>$email,'customer_user_id'=>absint($customer_user_id)?:null,'license_hash'=>hash('sha256',$key),'key_prefix'=>substr($key,0,12),'key_encrypted'=>$encrypted,'status'=>'active','expires_at'=>$expiry,'created_at'=>$now,'updated_at'=>$now));
		if (!$ok) return new \WP_Error('dk2_license_insert_failed',__('The license could not be created.','dropkey-v2'));
		$id=(int)$wpdb->insert_id; do_action('dk2_license_issued',$id,$product_id,$tier_id,$email);
		return array('id'=>$id,'license_key'=>$key);
	}
	private function encrypt_key($key) {
		if (!function_exists('openssl_encrypt')) return new \WP_Error('dk2_license_key_encryption_unavailable',__('Secure license-key storage is unavailable.','dropkey-v2'));
		try { $iv=random_bytes(12); } catch (\Exception $e) { return new \WP_Error('dk2_license_key_random_failed',__('A secure license key could not be generated.','dropkey-v2')); }
		$secret=hash('sha256',wp_salt('auth'),true); $tag='';
		$cipher=openssl_encrypt((string)$key,'aes-256-gcm',$secret,OPENSSL_RAW_DATA,$iv,$tag,'dropkey-v2-license-key',16);
		if (false===$cipher||''===$tag) return new \WP_Error('dk2_license_key_encryption_failed',__('The license key could not be stored securely.','dropkey-v2'));
		return base64_encode($iv.$tag.$cipher);
	}
	public function reveal_key($license) {
		if (empty($license->key_encrypted)||!function_exists('openssl_decrypt')) return '';
		$raw=base64_decode((string)$license->key_encrypted,true);
		if (false===$raw||strlen($raw)<29) return '';
		$iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28);
		$key=openssl_decrypt($cipher,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag,'dropkey-v2-license-key');
		return is_string($key)?$key:'';
	}
	public function find_by_key($key) {
		global $wpdb; $key=trim((string)$key); if (''===$key) return null;
		$table=Database::table('licenses'); $hash=hash('sha256',$key);
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE license_hash=%s LIMIT 1",$hash));
		return $row && hash_equals($row->license_hash,$hash) ? $row : null;
	}
	public function status_error($license) {
		if (!$license) return new \WP_Error('dk2_license_not_found',__('The license key was not recognized.','dropkey-v2'),array('status'=>404));
		if ('active'!==$license->status) return new \WP_Error('dk2_license_inactive',__('This license is not active.','dropkey-v2'),array('status'=>403));
		if ($license->expires_at && strtotime($license->expires_at.' UTC')<time()) return new \WP_Error('dk2_license_expired',__('This license has expired.','dropkey-v2'),array('status'=>403));
		return true;
	}
	public function normalize_site_url($url) {
		$url=esc_url_raw(trim((string)$url),array('http','https')); $parts=wp_parse_url($url);
		if (!is_array($parts)||empty($parts['scheme'])||empty($parts['host'])||!in_array(strtolower($parts['scheme']),array('http','https'),true)) return new \WP_Error('dk2_site_url_invalid',__('Enter a valid website URL including http:// or https://.','dropkey-v2'),array('status'=>400));
		return untrailingslashit($url);
	}
	public function activate($key,$site_url) {
		global $wpdb; $license=$this->find_by_key($key); $valid=$this->status_error($license); if (is_wp_error($valid)) return $valid;
		$url=$this->normalize_site_url($site_url); if (is_wp_error($url)) return $url; $table=Database::table('activations');
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE license_id=%d AND site_url=%s LIMIT 1",$license->id,$url));
		$now=current_time('mysql',true);
		if ($row&&'active'===$row->status) { $wpdb->update($table,array('last_seen_at'=>$now),array('id'=>$row->id)); return array('license'=>$license,'already_active'=>true); }
		$limit=(int)$wpdb->get_var($wpdb->prepare('SELECT activation_limit FROM '.Database::table('tiers').' WHERE id=%d',$license->tier_id));
		$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE license_id=%d AND status='active'",$license->id));
		if ($count>=max(1,$limit)) return new \WP_Error('dk2_activation_limit_reached',__('This license has reached its website activation limit.','dropkey-v2'),array('status'=>409,'activation_limit'=>$limit));
		if ($row) { $ok=$wpdb->update($table,array('status'=>'active','activated_at'=>$now,'deactivated_at'=>null,'last_seen_at'=>$now),array('id'=>$row->id)); $id=(int)$row->id; }
		else { $ok=$wpdb->insert($table,array('license_id'=>$license->id,'site_url'=>$url,'status'=>'active','activated_at'=>$now,'last_seen_at'=>$now)); $id=(int)$wpdb->insert_id; }
		if (false===$ok||(!$row&&!$id)) return new \WP_Error('dk2_activation_save_failed',__('The website activation could not be saved.','dropkey-v2'),array('status'=>500));
		do_action('dk2_license_activated',(int)$license->id,$url);
		return array('license'=>$license,'activation_id'=>$id,'already_active'=>false);
	}
	public function deactivate($key,$site_url) {
		global $wpdb; $license=$this->find_by_key($key); $valid=$this->status_error($license); if (is_wp_error($valid)) return $valid;
		$url=$this->normalize_site_url($site_url); if (is_wp_error($url)) return $url; $table=Database::table('activations');
		$n=$wpdb->update($table,array('status'=>'inactive','deactivated_at'=>current_time('mysql',true)),array('license_id'=>$license->id,'site_url'=>$url,'status'=>'active'));
		if (false===$n) return new \WP_Error('dk2_activation_update_failed',__('The activation could not be updated.','dropkey-v2'),array('status'=>500));
		do_action('dk2_license_deactivated',(int)$license->id,$url);
		return array('license'=>$license,'deactivated'=>$n>0);
	}
	public function validate($key,$site_url='') {
		global $wpdb; $license=$this->find_by_key($key); $valid=$this->status_error($license); if (is_wp_error($valid)) return $valid;
		$activation=null;
		if (''!==trim((string)$site_url)) {
			$url=$this->normalize_site_url($site_url); if (is_wp_error($url)) return $url;
			$a=Database::table('activations'); $activation=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$a} WHERE license_id=%d AND site_url=%s AND status='active' LIMIT 1",$license->id,$url));
			if (!$activation) return new \WP_Error('dk2_site_not_activated',__('This website is not activated for the license.','dropkey-v2'),array('status'=>403));
			$wpdb->update($a,array('last_seen_at'=>current_time('mysql',true)),array('id'=>$activation->id));
		}
		return array('license'=>$license,'activation'=>$activation,'valid'=>true);
	}
	public function set_status($id,$status) {
		global $wpdb; if (!in_array($status,array('active','suspended','revoked','expired'),true)) return new \WP_Error('dk2_license_status_invalid',__('Choose a valid license status.','dropkey-v2'));
		$n=$wpdb->update(Database::table('licenses'),array('status'=>$status,'updated_at'=>current_time('mysql',true)),array('id'=>absint($id)));
		if (false===$n) return new \WP_Error('dk2_license_status_update_failed',__('The license status could not be updated.','dropkey-v2'));
		do_action('dk2_license_status_changed',absint($id),$status); return true;
	}
}