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
	// Query all published WooCommerce products
	$args = [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	];

	$product_ids = get_posts($args);
	$mods        = [];

	foreach ($product_ids as $product_id) {
		$post           = get_post($product_id);
		$slug           = is_object($post) ? $post->post_name : '';
		$title          = function_exists('wc_get_product') ? (wc_get_product($product_id) ? wc_get_product($product_id)->get_name() : get_the_title($product_id)) : get_the_title($product_id);
		$permalink      = get_permalink($product_id);

		// Read custom meta
		$mod_filename   = get_post_meta($product_id, 'mod_filename', true);
		$mod_version    = get_post_meta($product_id, 'mod_version', true);
		$mod_author     = get_post_meta($product_id, 'mod_author', true);

		// Fallbacks
		if (!$mod_filename) {
			$mod_filename = ($slug ? $slug : sanitize_title($title)) . '.zip';
		}
		if (!$mod_version) {
			$mod_version = '1.0.0';
		}
		if (!$mod_author) {
			$mod_author = 'RUSTMods';
		}

		$mods[] = [
			'filename'     => (string) wp_strip_all_tags($mod_filename),
			'name'         => (string) wp_strip_all_tags($title),
			'last_version' => (string) wp_strip_all_tags($mod_version),
			'author'       => (string) wp_strip_all_tags($mod_author),
			'url'          => (string) esc_url_raw($permalink),
		];
	}

	return rest_ensure_response($mods);
}

/**
 * Add custom fields to WooCommerce product admin (General tab).
 */
add_action('woocommerce_product_options_general_product_data', function () {
	// Mod Version
	woocommerce_wp_text_input([
		'id'          => 'mod_version',
		'label'       => __('Mod Version', 'rustmodsapi'),
		'placeholder' => 'e.g. 1.0.0',
		'desc_tip'    => true,
		'description' => __('Version of the mod/plugin used by external tools and API.', 'rustmodsapi'),
	]);

	// Mod Filename
	woocommerce_wp_text_input([
		'id'          => 'mod_filename',
		'label'       => __('Mod Filename', 'rustmodsapi'),
		'placeholder' => 'e.g. raid-protection.zip',
		'desc_tip'    => true,
		'description' => __('Download filename used by external tools. Defaults to product-slug.zip', 'rustmodsapi'),
	]);

	// Mod Author
	woocommerce_wp_text_input([
		'id'          => 'mod_author',
		'label'       => __('Mod Author', 'rustmodsapi'),
		'placeholder' => 'e.g. RUSTMods',
		'desc_tip'    => true,
		'description' => __('Displayed author name for this mod. Defaults to RUSTMods.', 'rustmodsapi'),
	]);
});

/**
 * Save custom fields on product save.
 *
 * @param int $post_id
 */
add_action('woocommerce_process_product_meta', function ($post_id) {
	$fields = [
		'mod_version',
		'mod_filename',
		'mod_author',
	];

	foreach ($fields as $field) {
		if (isset($_POST[$field])) {
			$value = wp_unslash($_POST[$field]);
			update_post_meta($post_id, $field, sanitize_text_field($value));
		}
	}
});
