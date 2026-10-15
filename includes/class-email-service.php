<?php
/**
 * Encrypted transactional email queue with bounded retries.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Email_Service {
	public function register_hooks() {
		add_action('dk2_process_email_queue',array($this,'process_queue'));
	}
	private function encrypt($plain) {
		if (!function_exists('openssl_encrypt')) return new \WP_Error('dk2_email_encryption_unavailable',__('Email retry storage requires OpenSSL.','dropkey-v2'));
		$strong=false; $iv=openssl_random_pseudo_bytes(12,$strong);
		if (false===$iv||!$strong) return new \WP_Error('dk2_email_encryption_failed',__('The email could not be securely queued.','dropkey-v2'));
		$tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag);
		return false===$cipher||16!==strlen($tag)?new \WP_Error('dk2_email_encryption_failed',__('The email could not be securely queued.','dropkey-v2')):base64_encode($iv.$tag.$cipher);
	}
	private function decrypt($encoded) {
		if (!function_exists('openssl_decrypt')) return false;
		$data=base64_decode($encoded,true); if (false===$data||strlen($data)<29) return false;
		return openssl_decrypt(substr($data,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16));
	}
	public function enqueue($order_id,$recipient,$subject,$body) {
		global $wpdb;
		$encrypted=$this->encrypt($body); if (is_wp_error($encrypted)) return $encrypted;
		$ok=$wpdb->insert(Database::table('email_queue'),array(
			'order_id'=>absint($order_id),'recipient'=>sanitize_email($recipient),'subject'=>sanitize_text_field($subject),
			'encrypted_body'=>$encrypted,'status'=>'pending','attempts'=>0,'next_attempt_at'=>current_time('mysql',true),
			'last_error'=>'','created_at'=>current_time('mysql',true)
		));
		if (!$ok) { Logger::error('email','queue_failed','Transactional email could not be queued',array('order_id'=>absint($order_id))); return new \WP_Error('dk2_email_queue_failed',__('The transactional email could not be queued.','dropkey-v2')); }
		if (!wp_next_scheduled('dk2_process_email_queue')) wp_schedule_single_event(time()+10,'dk2_process_email_queue');
		do_action('dk2_email_queued',(int)$wpdb->insert_id,absint($order_id));
		return (int)$wpdb->insert_id;
	}
	public function resend($id) {
		global $wpdb; $id=absint($id); $table=Database::table('email_queue');
		$row=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d",$id));
		if (!$row) return new \WP_Error('dk2_email_not_found',__('The queued email was not found.','dropkey-v2'));
		$updated=$wpdb->update($table,array('status'=>'pending','attempts'=>0,'next_attempt_at'=>current_time('mysql',true),'last_error'=>''),array('id'=>$id));
		if (false===$updated) return new \WP_Error('dk2_email_retry_failed',__('The email could not be queued for retry.','dropkey-v2'));
		if (!wp_next_scheduled('dk2_process_email_queue')) wp_schedule_single_event(time()+10,'dk2_process_email_queue');
		return true;
	}
	public function process_queue() {
		global $wpdb; $table=Database::table('email_queue'); $now=current_time('mysql',true);
		$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status IN ('pending','failed') AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=%s) ORDER BY id ASC LIMIT 10",$now));
		foreach($rows as $row) {
			$body=$this->decrypt($row->encrypted_body);
			$sent=false;
			if (is_string($body)) $sent=wp_mail($row->recipient,$row->subject,$body,array('Content-Type: text/plain; charset=UTF-8'));
			$attempts=(int)$row->attempts+1;
			if ($sent) {
				Logger::info('email','sent','Transactional email sent',array('email_id'=>(int)$row->id,'order_id'=>(int)$row->order_id,'attempt'=>$attempts));
				$wpdb->update($table,array('status'=>'sent','attempts'=>$attempts,'last_error'=>'','sent_at'=>$now,'next_attempt_at'=>null),array('id'=>$row->id));
				do_action('dk2_email_sent',(int)$row->id,(int)$row->order_id);
			} else {
				Logger::error('email','send_failed','Transactional email delivery failed',array('email_id'=>(int)$row->id,'order_id'=>(int)$row->order_id,'attempt'=>$attempts));
				$delay=min(86400,300*(2**max(0,$attempts-1)));
				$status=$attempts>=5?'failed':'pending';
				$wpdb->update($table,array('status'=>$status,'attempts'=>$attempts,'last_error'=>'wp_mail failed or queued content could not be decrypted','next_attempt_at'=>$attempts>=5?null:gmdate('Y-m-d H:i:s',time()+$delay)),array('id'=>$row->id));
				do_action('dk2_email_send_failed',(int)$row->id,(int)$row->order_id,$attempts);
			}
		}
		$remaining=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending' AND attempts<5");
		if ($remaining&&!wp_next_scheduled('dk2_process_email_queue')) wp_schedule_single_event(time()+60,'dk2_process_email_queue');
	}
}
