<?php
/**
 * Expiring protected download links.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Download_Service {
	public function create_token($license_id,$release_id) {
		global $wpdb; $license_id=absint($license_id); $release_id=absint($release_id);
		$l=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('licenses').' WHERE id=%d',$license_id));
		$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Database::table('releases')." WHERE id=%d AND status='published'",$release_id));
		if (!$l||!$r||(int)$l->product_id!==(int)$r->product_id) return new \WP_Error('dk2_download_eligibility_failed',__('Choose a published release for the license’s product.','dropkey-v2'));
		$valid=(new License_Service())->status_error($l); if (is_wp_error($valid)) return $valid;
		try { $token=bin2hex(random_bytes(32)); } catch (\Exception $e) { return new \WP_Error('dk2_token_random_failed',__('A secure download token could not be generated.','dropkey-v2')); }
		$expiry=gmdate('Y-m-d H:i:s',time()+48*HOUR_IN_SECONDS);
		$ok=$wpdb->insert(Database::table('download_tokens'),array('license_id'=>$license_id,'release_id'=>$release_id,'token_hash'=>hash('sha256',$token),'expires_at'=>$expiry,'max_downloads'=>5,'download_count'=>0,'created_at'=>current_time('mysql',true)));
		if (!$ok) return new \WP_Error('dk2_download_token_failed',__('The download link could not be created.','dropkey-v2'));
		do_action('dk2_download_link_created',$license_id,$release_id,$expiry);
		return array('token'=>$token,'expires_at'=>$expiry,'url'=>rest_url('dropkey/v1/downloads/'.rawurlencode($token)));
	}
	public function deliver($token) {
		global $wpdb; $hash=hash('sha256',(string)$token); $table=Database::table('download_tokens');
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_hash=%s LIMIT 1",$hash));
		if (!$row||!hash_equals($row->token_hash,$hash)) return new \WP_Error('dk2_download_link_invalid',__('This download link is invalid.','dropkey-v2'),array('status'=>404));
		if (strtotime($row->expires_at.' UTC')<time()) return new \WP_Error('dk2_download_link_expired',__('This download link has expired.','dropkey-v2'),array('status'=>410));
		if ((int)$row->download_count >= (int)$row->max_downloads) return new \WP_Error('dk2_download_limit_reached',__('This download link has reached its download limit.','dropkey-v2'),array('status'=>429));
		$l=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('licenses').' WHERE id=%d',$row->license_id));
		$valid=(new License_Service())->status_error($l); if (is_wp_error($valid)) return $valid;
		$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Database::table('releases')." WHERE id=%d AND status='published'",$row->release_id));
		if (!$r||(int)$r->product_id!==(int)$l->product_id||!is_file($r->package_path)||!is_readable($r->package_path)) return new \WP_Error('dk2_package_unavailable',__('The package is not currently available.','dropkey-v2'),array('status'=>404));
		$actual=hash_file('sha256',$r->package_path);
		if (!is_string($actual)||!hash_equals($r->package_sha256,$actual)) return new \WP_Error('dk2_package_integrity_failed',__('The package failed its integrity check. Contact the administrator.','dropkey-v2'),array('status'=>500));
		$updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET download_count=download_count+1 WHERE id=%d AND download_count<max_downloads",$row->id));
		if (1!==(int)$updated) return new \WP_Error('dk2_download_limit_reached',__('This download link has reached its download limit.','dropkey-v2'),array('status'=>429));
		do_action('dk2_package_downloaded',(int)$l->id,(int)$r->id);
		return array('path'=>$r->package_path,'filename'=>'dropkey-'.sanitize_file_name($r->version).'.zip','size'=>(int)$r->package_size);
	}
}