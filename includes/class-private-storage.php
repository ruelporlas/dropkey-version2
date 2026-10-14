<?php
/**
 * Private package storage and upload validation.
 * @package DropKeyV2
 */
namespace DropKey\V2;
defined('ABSPATH') || exit;
final class Private_Storage {
	public static function path() {
		$default = dirname(untrailingslashit(ABSPATH)) . DIRECTORY_SEPARATOR . 'dropkey-v2-private';
		$path = apply_filters('dk2_private_storage_path',$default);
		return is_string($path) ? untrailingslashit($path) : '';
	}
	public static function prepare() {
		$path=self::path();
		if (!$path || !self::absolute($path)) return new \WP_Error('dk2_storage_path_invalid',__('Configure an absolute private storage path outside the public web root.','dropkey-v2'));
		$wp=realpath(ABSPATH); $normalized=trailingslashit(wp_normalize_path($path)); $wp_normalized=$wp ? trailingslashit(wp_normalize_path($wp)) : '';
		if ($wp_normalized && (rtrim($normalized,'/')===rtrim($wp_normalized,'/') || 0===strpos($normalized,$wp_normalized))) return new \WP_Error('dk2_storage_path_public',__('The package directory must be outside the WordPress directory. Configure dk2_private_storage_path.','dropkey-v2'));
		if (!file_exists($path) && !wp_mkdir_p($path)) return new \WP_Error('dk2_storage_create_failed',__('DropKey could not create its private package directory. Check server permissions.','dropkey-v2'));
		if (!is_dir($path)||!is_writable($path)) return new \WP_Error('dk2_storage_not_writable',__('The private package directory is not writable by PHP.','dropkey-v2'));
		if (!file_exists($path.'/index.php')) @file_put_contents($path.'/index.php',"<?php\nhttp_response_code(404); exit;\n");
		if (!file_exists($path.'/.htaccess')) @file_put_contents($path.'/.htaccess',"Deny from all\n");
		return $path;
	}
	public static function save_upload($file) {
		if (!is_array($file)||empty($file['tmp_name'])||!empty($file['error'])||!is_uploaded_file($file['tmp_name'])) return new \WP_Error('dk2_upload_invalid',__('Choose a ZIP package and try again.','dropkey-v2'));
		$check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],array('zip'=>'application/zip'));
		if (empty($check['ext'])||'zip'!==strtolower($check['ext'])) return new \WP_Error('dk2_upload_type_invalid',__('Only ZIP packages are accepted.','dropkey-v2'));
		$sig=file_get_contents($file['tmp_name'],false,null,0,4);
		if (false===$sig||0!==strpos($sig,'PK')) return new \WP_Error('dk2_upload_not_zip',__('The uploaded file does not appear to be a ZIP archive.','dropkey-v2'));
		$path=self::prepare(); if (is_wp_error($path)) return $path;
		$name=wp_unique_filename($path,sanitize_file_name($file['name'])); $dest=trailingslashit($path).$name;
		if (!move_uploaded_file($file['tmp_name'],$dest)) return new \WP_Error('dk2_upload_move_failed',__('DropKey could not move the ZIP into private storage.','dropkey-v2'));
		@chmod($dest,0640);
		return array('path'=>$dest,'size'=>(int)filesize($dest),'sha256'=>hash_file('sha256',$dest));
	}
	private static function absolute($p) { return isset($p[0]) && ('/'===$p[0] || preg_match('/^[A-Za-z]:[\\\\\/]/',$p)); }
}