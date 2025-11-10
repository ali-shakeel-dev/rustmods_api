<?php
/**
 * Plugin Name: RUSTModsAPI
 * Description: Exposes a public JSON API endpoint that lists all WooCommerce products (mods/plugins) with specific fields.
 * Version: 1.0.0
 * Author: RUSTMods
 * Author URI: https://rustmods.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Check if ZipArchive is available and show admin notice if not.
 */
add_action('admin_notices', function () {
	if (!class_exists('ZipArchive')) {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>RUSTModsAPI:</strong> PHP ZipArchive extension is not enabled. ';
		echo 'ZIP file extraction for .cs filenames will not work. ';
		echo 'Please enable the <code>php_zip</code> extension in your php.ini file.';
		echo '</p></div>';
	}
});

/**
 * Register REST route: /wp-json/rustmodsapi/v1/mods
 */
add_action('rest_api_init', function () {
	register_rest_route(
		'rustmodsapi/v1',
		'/mods',
		[
			'methods'             => 'GET',
			'callback'            => 'rustmodsapi_get_mods',
			'permission_callback' => '__return_true', // Public endpoint
		]
	);
});

/**
 * Callback: Build the mods list from WooCommerce products.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|array
 */
function rustmodsapi_get_mods($request)
{
	$cache_key = 'rustmodsapi_mods_cache';
	$cached    = get_transient($cache_key);
	if (is_array($cached)) {
		return rest_ensure_response($cached);
	}

	$mods = rustmodsapi_generate_mods_data();
	set_transient($cache_key, $mods, 5 * MINUTE_IN_SECONDS);

	return rest_ensure_response($mods);
}

/**
 * Generate fresh mods data from WooCommerce products.
 *
 * @return array
 */
function rustmodsapi_generate_mods_data()
{
	$args = [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	];

	$product_ids = get_posts($args);
	$mods        = [];

	foreach ($product_ids as $product_id) {
		$post      = get_post($product_id);
		$slug      = is_object($post) ? $post->post_name : '';
		$title     = function_exists('wc_get_product') ? (wc_get_product($product_id) ? wc_get_product($product_id)->get_name() : get_the_title($product_id)) : get_the_title($product_id);
		$permalink = get_permalink($product_id);

		$mod_filename = get_post_meta($product_id, 'mod_filename', true);
		$mod_author   = get_post_meta($product_id, 'mod_author', true);
		$mod_version  = get_post_meta($product_id, 'mod_version', true);

		$detected_version = rustmodsapi_detect_version_from_title($title);
		$final_version    = $mod_version ? $mod_version : $detected_version;

		if (!$mod_filename) {
			$real_filename = rustmodsapi_get_real_cs_filename($product_id);
			if ($real_filename) {
				$mod_filename = $real_filename;
			} else {
				$mod_filename = rustmodsapi_generate_cs_filename($title, $final_version);
			}
		} else {
			$mod_filename = rustmodsapi_clean_cs_filename($mod_filename);
		}
		if (!$mod_author) {
			$mod_author = 'RUSTMods';
		}

		$clean_name = $title;
		if ($final_version !== '1.0.0') {
			$clean_name = preg_replace('/\s*\b' . preg_quote($final_version, '/') . '\b\s*/', '', $clean_name);
		}
		$clean_name = trim($clean_name);

		$mods[] = [
			'filename' => (string) wp_strip_all_tags($mod_filename),
			'name'     => (string) wp_strip_all_tags($clean_name),
			'last'     => (string) wp_strip_all_tags($final_version),
			'author'   => (string) wp_strip_all_tags($mod_author),
			'url'      => (string) esc_url_raw($permalink),
		];
	}

	return $mods;
}

/**
 * Extract the real .cs filename from the product's downloadable ZIP file.
 *
 * @param int $product_id
 * @return string|false
 */
function rustmodsapi_extract_cs_filename_from_zip($product_id)
{
	if (!class_exists('WC_Product')) {
		return false;
	}

	$product = wc_get_product($product_id);
	if (!$product) {
		return false;
	}

	$downloads = $product->get_downloads();
	if (empty($downloads)) {
		return false;
	}

	$download = reset($downloads);
	$file_url = $download->get_file();

	if (empty($file_url)) {
		return false;
	}

	$is_temp = false;
	$file_path = false;

	$upload_dir = wp_upload_dir();
	
	$file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
	
	if (!file_exists($file_path)) {
		$site_url = site_url();
		$file_path = str_replace($site_url, ABSPATH, $file_url);
	}
	
	if (!file_exists($file_path)) {
		if (strpos($file_url, '/wp-content/uploads/') !== false) {
			$relative_path = substr($file_url, strpos($file_url, '/wp-content/uploads/'));
			$file_path = ABSPATH . ltrim($relative_path, '/');
		}
	}
	
	if (!file_exists($file_path) && method_exists($download, 'get_file_path')) {
		$file_path = $download->get_file_path();
	}
	
	if (!file_exists($file_path) && filter_var($file_url, FILTER_VALIDATE_URL)) {
		$temp_file = download_url($file_url);
		if (!is_wp_error($temp_file)) {
			$file_path = $temp_file;
			$is_temp = true;
		}
	}

	if (!$file_path || !file_exists($file_path) || !is_readable($file_path)) {
		if ($is_temp && $file_path) {
			@unlink($file_path);
		}
		return false;
	}

	// Open ZIP archive
	if (!class_exists('ZipArchive')) {
		if ($is_temp) {
			@unlink($file_path);
		}
		return false;
	}

	$zip = new ZipArchive();
	$result = $zip->open($file_path);

	if ($result !== true) {
		if ($is_temp) {
			@unlink($file_path);
		}
		return false;
	}

	$cs_filename = false;

	for ($i = 0; $i < $zip->numFiles; $i++) {
		$stat = $zip->statIndex($i);
		if ($stat === false) {
			continue;
		}

		$filename = $stat['name'];
		if (preg_match('/\.cs$/i', $filename)) {
			$cs_filename = basename($filename);
			break;
		}
	}

	$zip->close();

	if ($is_temp) {
		@unlink($file_path);
	}

	if ($cs_filename) {
		$cs_filename = rustmodsapi_clean_cs_filename($cs_filename);
	}

	return $cs_filename;
}

/**
 *
 * @param string $filename
 * @return string
 */
function rustmodsapi_clean_cs_filename($filename)
{
	if (!is_string($filename) || empty($filename)) {
		return $filename;
	}

	if (strpos($filename, '-') === false && strpos($filename, '_') === false) {
		return $filename;
	}

	$has_extension = preg_match('/\.cs$/i', $filename);
	$base_name = preg_replace('/\.cs$/i', '', $filename);

	$base_name = str_replace(['-', '_'], ' ', $base_name);

	$base_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $base_name);

	$base_name = trim($base_name);
	$base_name = preg_replace('/\s+/', ' ', $base_name);

	$words = explode(' ', $base_name);
	$pascalCase = '';
	foreach ($words as $word) {
		if (!empty($word)) {
			$pascalCase .= ucfirst(strtolower($word));
		}
	}

	if (empty($pascalCase)) {
		$pascalCase = 'Product';
	}

	return $pascalCase . ($has_extension ? '.cs' : '');
}

/**
 * Get or detect the real .cs filename from ZIP, with caching.
 *
 * @param int $product_id
 * @return string|false
 */
function rustmodsapi_get_real_cs_filename($product_id)
{
	$rescan_flag = get_transient('rustmodsapi_rescan_' . $product_id);
	if ($rescan_flag) {
		delete_post_meta($product_id, '_rustmods_real_filename');
		delete_post_meta($product_id, '_rustmods_cached_file_url');
		delete_transient('rustmodsapi_rescan_' . $product_id);
	}
	
	$product = wc_get_product($product_id);
	$current_file_url = '';
	if ($product) {
		$downloads = $product->get_downloads();
		if (!empty($downloads)) {
			$download = reset($downloads);
			$current_file_url = $download->get_file();
		}
	}
	
	$cached = get_post_meta($product_id, '_rustmods_real_filename', true);
	$cached_file_url = get_post_meta($product_id, '_rustmods_cached_file_url', true);
	
	if ($cached && $current_file_url === $cached_file_url && !empty($current_file_url) && !empty($cached_file_url)) {
		return $cached;
	}

	$real_filename = rustmodsapi_extract_cs_filename_from_zip($product_id);
	if ($real_filename) {
		update_post_meta($product_id, '_rustmods_real_filename', $real_filename);
		if (!empty($current_file_url)) {
			update_post_meta($product_id, '_rustmods_cached_file_url', $current_file_url);
		}
		return $real_filename;
	}

	return false;
}

/**
 * Detect semantic version from a product title.
 *
 * @param string $title
 * @return string
 */
function rustmodsapi_detect_version_from_title($title)
{
	$default = '1.0.0';
	if (!is_string($title) || $title === '') {
		return $default;
	}
	if (preg_match('/\b\d+(?:\.\d+){1,2}\b/', $title, $m)) {
		return $m[0];
	}
	return $default;
}

/**
 * @param string $title
 * @param string $version
 * @return string
 */
function rustmodsapi_generate_cs_filename($title, $version)
{
	if (!is_string($title) || $title === '') {
		return 'Product.cs';
	}

	$base = $title;
	if ($version !== '1.0.0') {
		$base = preg_replace('/\s*\b' . preg_quote($version, '/') . '\b\s*/', '', $base);
	}

	$base = preg_replace('/[^a-zA-Z0-9\s]/', '', $base);
	
	$base = trim($base);
	$base = preg_replace('/\s+/', ' ', $base);
	
	$words = explode(' ', $base);
	$pascalCase = '';
	foreach ($words as $word) {
		if (!empty($word)) {
			$pascalCase .= ucfirst(strtolower($word));
		}
	}

	if (empty($pascalCase)) {
		$pascalCase = 'Product';
	}

	return $pascalCase . '.cs';
}

/**
 * @deprecated
 *
 * @param string $title
 * @param string $version
 * @return string
 */
function rustmodsapi_get_base_name_from_title($title, $version)
{
	if (!is_string($title) || $title === '') {
		return 'product';
	}

	$base = $title;
	if ($version !== '1.0.0') {
		$base = preg_replace('/\s*\b' . preg_quote($version, '/') . '\b\s*/', '', $base);
	}

	$base = trim($base);
	$base = preg_replace('/[^a-zA-Z0-9\s-]/', '', $base);
	$base = preg_replace('/\s+/', '-', $base);
	$base = preg_replace('/-+/', '-', $base);
	$base = trim($base, '-');

	return $base ? $base : 'product';
}

/**
 * Add custom fields to WooCommerce product admin (General tab).
 */
add_action('woocommerce_product_options_general_product_data', function () {
	$the_post_id  = get_the_ID();
	$current_title = $the_post_id ? get_the_title($the_post_id) : '';
	
	$detected_version = rustmodsapi_detect_version_from_title($current_title);
	
	// Try to get real filename from ZIP first, then fallback to generated
	$real_filename = $the_post_id ? rustmodsapi_get_real_cs_filename($the_post_id) : false;
	$placeholder_filename = $real_filename ? $real_filename : rustmodsapi_generate_cs_filename($current_title, $detected_version);
	
	$placeholder_version = $detected_version !== '1.0.0' ? $detected_version : '1.0.0';
	$placeholder_author = 'RUSTMods';

	woocommerce_wp_text_input([
		'id'          => 'mod_version',
		'label'       => __('Mod Version', 'rustmodsapi'),
		'placeholder' => $placeholder_version,
		'desc_tip'    => true,
		'description' => __('Semantic version for this mod. Auto-detected from product title if empty.', 'rustmodsapi'),
	]);

	woocommerce_wp_text_input([
		'id'          => 'mod_filename',
		'label'       => __('Mod Filename', 'rustmodsapi'),
		'placeholder' => $placeholder_filename,
		'desc_tip'    => true,
		'description' => __('C# filename exposed via API. Auto-detected from ZIP file, or computed from title if empty.', 'rustmodsapi'),
	]);

	woocommerce_wp_text_input([
		'id'          => 'mod_author',
		'label'       => __('Mod Author', 'rustmodsapi'),
		'placeholder' => $placeholder_author,
		'desc_tip'    => true,
		'description' => __('Displayed author name for this mod. Defaults to RUSTMods in API if empty.', 'rustmodsapi'),
	]);

	echo '<div style="margin:12px 0;border-top:1px solid #e2e2e2;"></div>';
});

/**
 * Save custom fields on product save.
 * Only save fields that admin explicitly fills in - no auto-updates.
 *
 * @param int $post_id
 */
add_action('woocommerce_process_product_meta', function ($post_id) {
	if (isset($_POST['mod_version'])) {
		$posted_version = trim(wp_unslash($_POST['mod_version']));
		if ($posted_version !== '') {
			update_post_meta($post_id, 'mod_version', sanitize_text_field($posted_version));
		} else {
			delete_post_meta($post_id, 'mod_version');
		}
	}

	if (isset($_POST['mod_filename'])) {
		$posted_filename = trim(wp_unslash($_POST['mod_filename']));
		if ($posted_filename !== '') {
			update_post_meta($post_id, 'mod_filename', sanitize_text_field($posted_filename));
		} else {
			delete_post_meta($post_id, 'mod_filename');
		}
	}

	if (isset($_POST['mod_author'])) {
		$posted_author = trim(wp_unslash($_POST['mod_author']));
		if ($posted_author !== '') {
			update_post_meta($post_id, 'mod_author', sanitize_text_field($posted_author));
		} else {
			delete_post_meta($post_id, 'mod_author');
		}
	}
}, 20);

/**
 * Re-scan ZIP file and update cached .cs filename when product is saved/updated.
 * Always clears cache on save to catch file replacements (even if URL stays same).
 */
add_action('woocommerce_process_product_meta', function ($post_id) {
	delete_post_meta($post_id, '_rustmods_real_filename');
	delete_post_meta($post_id, '_rustmods_cached_file_url');
	
	set_transient('rustmodsapi_rescan_' . $post_id, true, 60);
	
	$product = wc_get_product($post_id);
	if ($product) {
		$downloads = $product->get_downloads();
		if (!empty($downloads)) {

			$new_filename = rustmodsapi_get_real_cs_filename($post_id);
			if ($new_filename) {
				$download = reset($downloads);
				$current_file_url = $download->get_file();
				update_post_meta($post_id, '_rustmods_cached_file_url', $current_file_url);
			}
		}
	}
}, 15);

/**
 * Clear cache when product downloads are updated via AJAX (WooCommerce file uploader).
 */
add_action('woocommerce_product_file_download_paths', function ($downloads, $product_id) {
	delete_post_meta($product_id, '_rustmods_real_filename');
	delete_post_meta($product_id, '_rustmods_cached_file_url');
	
	if (!empty($downloads)) {
		set_transient('rustmodsapi_rescan_' . $product_id, true, 60);
	}
}, 10, 2);

/**
 * Clear cache when downloadable files are updated via AJAX (when clicking upload button on existing file).
 * This hook fires when the product meta is saved after AJAX file updates.
 */
add_action('woocommerce_before_product_object_save', function ($product) {
	if ($product && $product->get_id()) {
		// Force clear cache - file might have been replaced with same URL
		delete_post_meta($product->get_id(), '_rustmods_real_filename');
		delete_post_meta($product->get_id(), '_rustmods_cached_file_url');
		set_transient('rustmodsapi_rescan_' . $product->get_id(), true, 60);
	}
}, 10, 1);

/**
 * Also clear cache after product object is saved (catches AJAX updates).
 */
add_action('woocommerce_after_product_object_save', function ($product) {
	if ($product && $product->get_id()) {
		delete_post_meta($product->get_id(), '_rustmods_real_filename');
		delete_post_meta($product->get_id(), '_rustmods_cached_file_url');
		set_transient('rustmodsapi_rescan_' . $product->get_id(), true, 60);
	}
}, 10, 1);

/**
 * Invalidate and regenerate cache whenever a product is saved/updated.
 * Run after meta fields are saved.
 */
add_action('save_post_product', function ($post_id) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (wp_is_post_revision($post_id)) {
		return;
	}

	delete_post_meta($post_id, '_rustmods_real_filename');
	delete_post_meta($post_id, '_rustmods_cached_file_url');
	
	$cache_key = 'rustmodsapi_mods_cache';
	delete_transient($cache_key);
}, 99, 1);

/**
 * Regenerate API cache after product is fully updated (including downloads).
 * This hook fires after all product data including downloads are saved.
 */
add_action('woocommerce_update_product', function ($product_id) {
	rustmodsapi_refresh_product_filename_and_cache($product_id);
}, 20, 1);

/**
 * Also handle product creation.
 */
add_action('woocommerce_new_product', function ($product_id) {
	rustmodsapi_refresh_product_filename_and_cache($product_id);
}, 20, 1);

/**
 * Track products that need cache refresh.
 */
$GLOBALS['rustmodsapi_products_to_refresh'] = [];

/**
 * Helper function to refresh filename and regenerate API cache.
 * Uses shutdown hook to ensure product data is fully saved.
 */
function rustmodsapi_refresh_product_filename_and_cache($product_id) {
	if (!isset($GLOBALS['rustmodsapi_products_to_refresh'])) {
		$GLOBALS['rustmodsapi_products_to_refresh'] = [];
	}
	$GLOBALS['rustmodsapi_products_to_refresh'][$product_id] = true;
	
	static $shutdown_registered = false;
	if (!$shutdown_registered) {
		add_action('shutdown', 'rustmodsapi_process_product_refresh', 999);
		$shutdown_registered = true;
	}
}

/**
 * Process all products that need refresh at shutdown.
 */
function rustmodsapi_process_product_refresh() {
	if (empty($GLOBALS['rustmodsapi_products_to_refresh'])) {
		return;
	}
	
	$needs_cache_regeneration = false;
	
	foreach (array_keys($GLOBALS['rustmodsapi_products_to_refresh']) as $product_id) {
		clean_post_cache($product_id);
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients($product_id);
		}
		
		$product = wc_get_product($product_id);
		if ($product) {
			$downloads = $product->get_downloads();
			if (!empty($downloads)) {
				$new_filename = rustmodsapi_extract_cs_filename_from_zip($product_id);
				if ($new_filename) {
					$download = reset($downloads);
					$current_file_url = $download->get_file();
					update_post_meta($product_id, '_rustmods_real_filename', $new_filename);
					update_post_meta($product_id, '_rustmods_cached_file_url', $current_file_url);
					$needs_cache_regeneration = true;
				}
			}
		}
	}
	
	if ($needs_cache_regeneration) {
		$cache_key = 'rustmodsapi_mods_cache';
		delete_transient($cache_key);
		$mods = rustmodsapi_generate_mods_data();
		set_transient($cache_key, $mods, 5 * MINUTE_IN_SECONDS);
	}
	
	$GLOBALS['rustmodsapi_products_to_refresh'] = [];
}
