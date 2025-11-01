<?php
/**
 * Plugin Name: RUSTModsAPI
 * Description: Exposes a public JSON API endpoint that lists all WooCommerce products (mods/plugins) with specific fields for use by the RUST Admin tool.
 * Version: 1.0.0
 * Author: RUSTMods
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
	exit;
}

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
	// Serve from transient cache if available
	$cache_key = 'rustmodsapi_mods_cache';
	$cached    = get_transient($cache_key);
	if (is_array($cached)) {
		return rest_ensure_response($cached);
	}

	$mods = rustmodsapi_generate_mods_data();
	// Cache for a short period; always invalidated on product save
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

		// Custom meta (keep filename/author editable)
		$mod_filename = get_post_meta($product_id, 'mod_filename', true);
		$mod_author   = get_post_meta($product_id, 'mod_author', true);
		$mod_version  = get_post_meta($product_id, 'mod_version', true);

		// Auto-detect version from product title; prefer stored meta if present
		$detected_version = rustmodsapi_detect_version_from_title($title);
		$final_version    = $mod_version ? $mod_version : $detected_version;

		// Fallbacks
		if (!$mod_filename) {
			// Generate filename with version: "ProductName-version.zip" (preserve case)
			$base_name = rustmodsapi_get_base_name_from_title($title, $final_version);
			// Include version in filename if a version was detected or explicitly set (not default 1.0.0)
			if ($detected_version !== '1.0.0' || ($mod_version && $mod_version !== '1.0.0')) {
				$mod_filename = $base_name . '-' . $final_version . '.zip';
			} else {
				$mod_filename = $base_name . '.zip';
			}
		}
		if (!$mod_author) {
			$mod_author = 'RUSTMods';
		}

		$mods[] = [
			'filename' => (string) wp_strip_all_tags($mod_filename),
			'name'     => (string) wp_strip_all_tags($title),
			'last'     => (string) wp_strip_all_tags($final_version),
			'author'   => (string) wp_strip_all_tags($mod_author),
			'url'      => (string) esc_url_raw($permalink),
		];
	}

	return $mods;
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
 * Get base name from product title, removing version and cleaning for filename (preserves case).
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

	// Remove version from title if present
	$base = $title;
	if ($version !== '1.0.0') {
		$base = preg_replace('/\s*\b' . preg_quote($version, '/') . '\b\s*/', '', $base);
	}

	// Clean for filename: replace spaces with dashes, remove special chars, preserve case
	$base = trim($base);
	$base = preg_replace('/[^a-zA-Z0-9\s-]/', '', $base); // Remove special chars except spaces and dashes
	$base = preg_replace('/\s+/', '-', $base); // Replace spaces with dashes
	$base = preg_replace('/-+/', '-', $base); // Collapse multiple dashes
	$base = trim($base, '-'); // Remove leading/trailing dashes

	return $base ? $base : 'product';
}

/**
 * Add custom fields to WooCommerce product admin (General tab).
 */
add_action('woocommerce_product_options_general_product_data', function () {
	// Mod Version (editable; auto-detected from title if empty)
	woocommerce_wp_text_input([
		'id'          => 'mod_version',
		'label'       => __('Mod Version', 'rustmodsapi'),
		'placeholder' => 'e.g. 1.0.0',
		'desc_tip'    => true,
		'description' => __('Semantic version for this mod. Auto-detected from product title if empty.', 'rustmodsapi'),
	]);

	// Mod Filename (editable; auto-computed from title/version if empty)
	woocommerce_wp_text_input([
		'id'          => 'mod_filename',
		'label'       => __('Mod Filename', 'rustmodsapi'),
		'placeholder' => 'e.g. Raid-Protection-2.1.0.zip',
		'desc_tip'    => true,
		'description' => __('Filename exposed via API. Auto-computed from title/version if empty.', 'rustmodsapi'),
	]);

	// Mod Author (editable; defaults to RUSTMods in API if empty)
	woocommerce_wp_text_input([
		'id'          => 'mod_author',
		'label'       => __('Mod Author', 'rustmodsapi'),
		'placeholder' => 'e.g. RUSTMods',
		'desc_tip'    => true,
		'description' => __('Displayed author name for this mod. Defaults to RUSTMods in API if empty.', 'rustmodsapi'),
	]);

	// Divider
	echo '<div style="margin:12px 0;border-top:1px solid #e2e2e2;"></div>';
});

/**
 * Save custom fields on product save.
 *
 * @param int $post_id
 */
add_action('woocommerce_process_product_meta', function ($post_id) {
	// Get the NEW title (after save, from POST or from database)
	$new_title = '';
	if (isset($_POST['post_title'])) {
		$new_title = sanitize_text_field(wp_unslash($_POST['post_title']));
	} else {
		$new_title = get_the_title($post_id);
	}

	// Auto-detect version from new title
	$detected_version = rustmodsapi_detect_version_from_title($new_title);
	$existing_version = get_post_meta($post_id, 'mod_version', true);

	// Handle mod_version: auto-update if title changed and version was detected (unless manually edited)
	$posted_version = isset($_POST['mod_version']) ? trim(wp_unslash($_POST['mod_version'])) : '';
	if ($posted_version !== '') {
		// User manually set version - save it
		update_post_meta($post_id, 'mod_version', sanitize_text_field($posted_version));
		$final_version = sanitize_text_field($posted_version);
	} elseif ($detected_version !== '1.0.0' && $detected_version !== $existing_version) {
		// Auto-update version from title if detected and differs from existing
		update_post_meta($post_id, 'mod_version', $detected_version);
		$final_version = $detected_version;
	} elseif ($existing_version) {
		$final_version = $existing_version;
	} else {
		$final_version = $detected_version;
		update_post_meta($post_id, 'mod_version', $detected_version);
	}

	// Handle mod_filename: auto-update if version changed (unless manually edited)
	$posted_filename = isset($_POST['mod_filename']) ? trim(wp_unslash($_POST['mod_filename'])) : '';
	if ($posted_filename !== '') {
		// User manually set filename - save it
		update_post_meta($post_id, 'mod_filename', sanitize_text_field($posted_filename));
	} else {
		// Auto-compute filename from new title + version
		$base_name = rustmodsapi_get_base_name_from_title($new_title, $final_version);
		$computed_filename = ($final_version !== '1.0.0') ? ($base_name . '-' . $final_version . '.zip') : ($base_name . '.zip');
		update_post_meta($post_id, 'mod_filename', $computed_filename);
	}

	// Handle mod_author: save if posted
	if (isset($_POST['mod_author'])) {
		$value = wp_unslash($_POST['mod_author']);
		update_post_meta($post_id, 'mod_author', sanitize_text_field($value));
	}
}, 20); // Priority 20 to run after title is saved

/**
 * Invalidate and regenerate cache whenever a product is saved/updated.
 * Run after meta fields are saved.
 */
add_action('save_post_product', function ($post_id) {
	// Skip autosaves and revisions
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (wp_is_post_revision($post_id)) {
		return;
	}

	$cache_key = 'rustmodsapi_mods_cache';
	delete_transient($cache_key);
	// Regenerate immediately so API is hot and up to date
	$mods = rustmodsapi_generate_mods_data();
	set_transient($cache_key, $mods, 5 * MINUTE_IN_SECONDS);
}, 99, 1); // Priority 99 to run after all meta saves
