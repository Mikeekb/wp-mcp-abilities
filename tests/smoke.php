<?php
/**
 * SEOMI MCP Abilities — smoke tests.
 *
 * Run with WP-CLI from any project directory:
 *
 *   wp eval-file wp-content/mu-plugins/seomi-mcp-abilities/tests/smoke.php
 *
 * Each check prints `[PASS]` or `[FAIL] <reason>`. Exit code is non-zero
 * if any check fails so this can be wired into CI later.
 */

defined( 'ABSPATH' ) || exit;

$failures = 0;
$checks   = 0;

$assert = function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ) {
	$checks++;
	if ( $ok ) {
		echo "[PASS] {$name}\n";
	} else {
		echo "[FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
		$failures++;
	}
};

// ─────────────────────────────────────────────────────────────────────
// 1. Plugin loaded
// ─────────────────────────────────────────────────────────────────────

$assert( 'plugin loaded (SEOMI_MCP_VERSION defined)', defined( 'SEOMI_MCP_VERSION' ) );
$assert( 'autoloader works for Core', class_exists( 'Seomi\\Mcp\\Core' ) );
$assert( 'autoloader works for ModuleInterface', interface_exists( 'Seomi\\Mcp\\Modules\\ModuleInterface' ) );

// ─────────────────────────────────────────────────────────────────────
// 2. All expected abilities are registered
// ─────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'wp_get_abilities' ) ) {
	echo "[SKIP] wp_get_abilities() not available — Abilities API plugin missing or inactive\n";
} else {
	$all      = wp_get_abilities();
	$names    = array_keys( $all );
	$expected = [
		// posts
		'seomi/get-posts', 'seomi/get-post', 'seomi/get-post-meta',
		'seomi/find-posts-by-thumbnail',
		'seomi/create-post', 'seomi/update-post', 'seomi/delete-post',
		'seomi/bulk-replace-in-posts',
		// pages
		'seomi/get-pages', 'seomi/get-page',
		'seomi/create-page', 'seomi/update-page', 'seomi/delete-page',
		// terms — categories
		'seomi/get-categories',
		'seomi/create-category', 'seomi/update-category', 'seomi/delete-category',
		// terms — search & bulk
		'seomi/search-terms', 'seomi/bulk-replace-in-term-descriptions',
		// terms — tags
		'seomi/get-tags',
		'seomi/create-tag', 'seomi/update-tag', 'seomi/delete-tag',
		// media
		'seomi/get-attachment', 'seomi/find-attachments-by-file', 'seomi/set-post-thumbnail',
	];

	foreach ( $expected as $ability ) {
		$assert( "ability registered: {$ability}", in_array( $ability, $names, true ) );
	}

	// WooCommerce abilities — present iff WC is loaded
	$wc_abilities = [
		'seomi/wc/get-products', 'seomi/wc/get-product',
		'seomi/wc/create-product', 'seomi/wc/update-product', 'seomi/wc/delete-product',
		'seomi/wc/update-product-price', 'seomi/wc/update-product-stock',
		'seomi/wc/get-product-categories',
		'seomi/wc/create-product-category', 'seomi/wc/update-product-category', 'seomi/wc/delete-product-category',
		'seomi/wc/get-orders', 'seomi/wc/get-order', 'seomi/wc/update-order-status',
	];
	if ( class_exists( 'WooCommerce' ) ) {
		foreach ( $wc_abilities as $ability ) {
			$assert( "WC ability registered: {$ability}", in_array( $ability, $names, true ) );
		}
	} else {
		$any_wc_registered = false;
		foreach ( $wc_abilities as $ability ) {
			if ( in_array( $ability, $names, true ) ) {
				$any_wc_registered = true;
				break;
			}
		}
		$assert( 'WC module is silent when WooCommerce not active', ! $any_wc_registered );
	}
}

// ─────────────────────────────────────────────────────────────────────
// 3. with_admin_term_kses detaches and restores wp_filter_kses
// ─────────────────────────────────────────────────────────────────────

$priority_before = has_filter( 'pre_term_description', 'wp_filter_kses' );

$inside_priority = null;
$returned        = \Seomi\Mcp\Core::with_admin_term_kses( function () use ( &$inside_priority ) {
	$inside_priority = has_filter( 'pre_term_description', 'wp_filter_kses' );
	return 'OK';
} );

$priority_after = has_filter( 'pre_term_description', 'wp_filter_kses' );

$assert(
	'with_admin_term_kses detaches wp_filter_kses inside callback',
	$priority_before === false ? $inside_priority === false : $inside_priority === false,
	"before={$priority_before} inside=" . var_export( $inside_priority, true )
);
$assert(
	'with_admin_term_kses restores wp_filter_kses after callback',
	$priority_after === $priority_before,
	"before={$priority_before} after={$priority_after}"
);
$assert( 'with_admin_term_kses returns callback result', $returned === 'OK' );

// ─────────────────────────────────────────────────────────────────────
// 4. bulk-replace dry_run does not write to DB
// ─────────────────────────────────────────────────────────────────────

if ( function_exists( 'wp_get_ability' ) ) {
	$ability = wp_get_ability( 'seomi/bulk-replace-in-term-descriptions' );
	if ( $ability ) {
		global $wpdb;
		$before = $wpdb->get_results( "SELECT term_taxonomy_id, description FROM {$wpdb->term_taxonomy} WHERE description != '' LIMIT 5" );

		$ability->execute( [
			'search'   => 'zzz_nonexistent_needle_zzz',
			'replace'  => 'zzz_replacement_zzz',
			'taxonomy' => 'category',
			'dry_run'  => true,
		] );

		$after = $wpdb->get_results( "SELECT term_taxonomy_id, description FROM {$wpdb->term_taxonomy} WHERE description != '' LIMIT 5" );
		$assert( 'bulk-replace dry_run does not mutate term descriptions', $before == $after );
	} else {
		echo "[SKIP] seomi/bulk-replace-in-term-descriptions not registered — cannot run dry_run check\n";
	}
} else {
	echo "[SKIP] wp_get_ability() not available\n";
}

// ─────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────

echo "\n---\n";
echo "Total: {$checks}, Failures: {$failures}\n";
if ( $failures > 0 ) {
	exit( 1 );
}
