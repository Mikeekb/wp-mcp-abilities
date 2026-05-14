<?php
/**
 * Seomi\Mcp\Core — shared helpers and ability category registration.
 */

namespace Seomi\Mcp;

defined( 'ABSPATH' ) || exit;

class Core {

	/**
	 * Run a callback with admin-like term description sanitization.
	 *
	 * Yoast SEO removes wp_filter_kses from pre_term_description only when is_admin() is true.
	 * Outside admin (REST/MCP/CLI) the default wp_filter_kses silently strips <table>, <tr>, <td>
	 * and other rich HTML from term descriptions. This helper detaches that filter while the
	 * callback runs and restores it afterwards, so wp_insert_term / wp_update_term behave the
	 * same way they do in the admin UI — tables survive, edited_term/created_term hooks still fire.
	 */
	public static function with_admin_term_kses( callable $callback ) {
		$priority = has_filter( 'pre_term_description', 'wp_filter_kses' );
		if ( false !== $priority ) {
			remove_filter( 'pre_term_description', 'wp_filter_kses', $priority );
		}
		try {
			return $callback();
		} finally {
			if ( false !== $priority ) {
				add_filter( 'pre_term_description', 'wp_filter_kses', $priority );
			}
		}
	}

	/**
	 * Register ability categories used by all modules.
	 */
	public static function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category( 'content', [
			'label'       => 'Content',
			'description' => 'Abilities for managing WordPress posts and pages.',
		] );

		wp_register_ability_category( 'taxonomy', [
			'label'       => 'Taxonomy',
			'description' => 'Abilities for managing categories, tags, and other taxonomies.',
		] );

		wp_register_ability_category( 'media', [
			'label'       => 'Media',
			'description' => 'Abilities for managing attachments and featured images.',
		] );

		wp_register_ability_category( 'woocommerce', [
			'label'       => 'WooCommerce',
			'description' => 'Abilities for managing WooCommerce products, categories, and orders.',
		] );
	}
}
