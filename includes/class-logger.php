<?php
/**
 * Structured, privacy-conscious activity and diagnostic logging.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Logger {
	private static $request_id='';
	private static function enabled() {
		return '0' !== (string) get_option('dk2_logging_enabled','1');
	}
	private static function request_id() {
		if (''===self::$request_id) self::$request_id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():uniqid('dk2-',true);
		return self::$request_id;
	}
	private static function safe_context(array $context) {
		$safe=array(); $sensitive='/(?:authorization|access_token|refresh_token|client_secret|secret|password|api_key|webhook_secret|signature|cookie|email|license_key|key_encrypted|request_body|response_body|payload|(?:^|_)url$)/i';
		foreach($context as $key=>$value) {
			if (count($safe)>=30) break;
			$key=sanitize_key((string)$key); if (''===$key) continue;
			if (preg_match($sensitive,$key)) { $safe[$key]='[redacted]'; continue; }
			if (is_bool($value)||is_int($value)||is_float($value)||null===$value) $safe[$key]=$value;
			elseif (is_scalar($value)) $safe[$key]=substr(sanitize_text_field((string)$value),0,250);
			elseif (is_array($value)) {
				$nested=array();
				foreach(array_slice($value,0,10,true) as $nested_key=>$nested_value) {
					$nested_key=sanitize_key((string)$nested_key);
					if (preg_match($sensitive,$nested_key)) { $nested[$nested_key]='[redacted]'; continue; }
					if (is_scalar($nested_value)||null===$nested_value) $nested[$nested_key]=is_string($nested_value)?substr(sanitize_text_field($nested_value),0,150):$nested_value;
				}
				$safe[$key]=$nested;
			}
		}
		return $safe;
	}
	public static function log($level,$source,$event,$message='',$context=array()) {
		if (!self::enabled()) return false;
		global $wpdb;
		$levels=array('debug','info','warning','error','critical');
		$level=in_array($level,$levels,true)?$level:'info';
		$context=is_array($context)?self::safe_context($context):array();
		$encoded=wp_json_encode($context);
		if (!is_string($encoded)) $encoded='{}';
		if (strlen($encoded)>4000) $encoded=wp_json_encode(array('context_truncated'=>true));
		$table=Database::table('logs');
		$ok=$wpdb->insert($table,array(
			'level'=>$level,
			'source'=>substr(sanitize_key($source),0,50),
			'event'=>substr(sanitize_key($event),0,100),
			'message'=>substr(sanitize_text_field((string)$message),0,500),
			'context'=>$encoded,
			'user_id'=>get_current_user_id()?:null,
			'request_id'=>self::request_id(),
			'created_at'=>current_time('mysql',true)
		),array('%s','%s','%s','%s','%s','%d','%s','%s'));
		if (false===$ok) {
			// Avoid recursive logging when the log table itself cannot be written.
			error_log('[DropKey V2] Diagnostic log write failed.');
			return false;
		}
		if (!get_transient('dk2_logs_pruned')) {
			$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s",gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS)));
			set_transient('dk2_logs_pruned',1,DAY_IN_SECONDS);
		}
		return true;
	}
	public static function debug($source,$event,$message='',$context=array()) { return self::log('debug',$source,$event,$message,$context); }
	public static function info($source,$event,$message='',$context=array()) { return self::log('info',$source,$event,$message,$context); }
	public static function warning($source,$event,$message='',$context=array()) { return self::log('warning',$source,$event,$message,$context); }
	public static function error($source,$event,$message='',$context=array()) { return self::log('error',$source,$event,$message,$context); }
}
