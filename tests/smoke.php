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

// Authenticate as an admin so permission_callbacks on write-abilities pass.
// In WP-CLI eval the current user is 0 by default, so manage_categories would fail.
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
if ( ! empty( $admins ) ) {
	wp_set_current_user( (int) $admins[0] );
}

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
// 5. Rich HTML (tables) survives create-category and bulk-replace
//    This is the core invariant the mu-plugin exists to protect — outside
//    is_admin() context, wp_filter_kses would otherwise silently strip
//    <table>/<tr>/<td>. with_admin_term_kses() must prevent that.
// ─────────────────────────────────────────────────────────────────────

if ( function_exists( 'wp_get_ability' ) ) {
	$create_cat = wp_get_ability( 'seomi/create-category' );
	$bulk_repl  = wp_get_ability( 'seomi/bulk-replace-in-term-descriptions' );
	$update_cat = wp_get_ability( 'seomi/update-category' );

	if ( $create_cat && $bulk_repl && $update_cat ) {
		$slug           = 'seomi-mcp-smoke-table-' . substr( md5( (string) microtime( true ) ), 0, 8 );
		$marker_initial = 'SEOMI_TABLE_MARKER_' . substr( md5( $slug ), 0, 8 );
		$marker_after   = 'SEOMI_REPLACED_' . substr( md5( $slug ), 0, 8 );
		$html           = "<p>Intro paragraph.</p>\n"
			. "<table><thead><tr><th>Spec</th><th>Value</th></tr></thead>"
			. "<tbody><tr><td>Field A</td><td>{$marker_initial}</td></tr>"
			. "<tr><td>Field B</td><td>42</td></tr></tbody></table>\n"
			. "<p>Outro paragraph.</p>";

		// Idempotency: nuke any leftover term from a previous failed run.
		$leftover = get_term_by( 'slug', $slug, 'category' );
		if ( $leftover ) {
			wp_delete_term( $leftover->term_id, 'category' );
		}

		$created = $create_cat->execute( [
			'name'        => 'SEOMI MCP smoke — table preservation',
			'slug'        => $slug,
			'description' => $html,
		] );

		$term_id = is_array( $created ) && isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
		$assert( 'create-category with HTML table returns term_id', $term_id > 0 );

		if ( $term_id > 0 ) {
			// Read back via raw DB query — what's actually stored.
			$stored = get_term_field( 'description', $term_id, 'category', 'raw' );

			$assert( 'table tag survives create-category', strpos( $stored, '<table>' ) !== false );
			$assert(
				'thead/tbody/td survive create-category',
				strpos( $stored, '<thead>' ) !== false
					&& strpos( $stored, '<tbody>' ) !== false
					&& strpos( $stored, '<td>' ) !== false
					&& strpos( $stored, '</table>' ) !== false
			);
			$assert( 'marker present inside the table cell', strpos( $stored, $marker_initial ) !== false );

			// Update via seomi/update-category — round-trip with HTML edited inline.
			$updated_html = str_replace( '<p>Outro paragraph.</p>', '<p>Outro edited.</p>', $stored );
			$update_cat->execute( [
				'term_id'     => $term_id,
				'description' => $updated_html,
			] );
			$stored2 = get_term_field( 'description', $term_id, 'category', 'raw' );
			$assert( 'table still intact after update-category', strpos( $stored2, '<table>' ) !== false && strpos( $stored2, '</table>' ) !== false );
			$assert( 'inline edit applied (Outro changed)', strpos( $stored2, 'Outro edited.' ) !== false );

			// Bulk-replace inside the cell — table must survive the rewrite.
			$bulk_res = $bulk_repl->execute( [
				'search'   => $marker_initial,
				'replace'  => $marker_after,
				'taxonomy' => 'category',
			] );
			$assert(
				'bulk-replace updated_count >= 1 for our term',
				is_array( $bulk_res ) && (int) ( $bulk_res['updated_count'] ?? 0 ) >= 1
			);

			$stored3 = get_term_field( 'description', $term_id, 'category', 'raw' );
			$assert( 'table still intact after bulk-replace', strpos( $stored3, '<table>' ) !== false && strpos( $stored3, '</table>' ) !== false );
			$assert( 'marker replaced inside cell', strpos( $stored3, $marker_after ) !== false );
			$assert( 'old marker is gone', strpos( $stored3, $marker_initial ) === false );

			// Cleanup.
			wp_delete_term( $term_id, 'category' );
		}
	} else {
		echo "[SKIP] Term CRUD abilities not all registered — cannot run table-preservation check\n";
	}
}

// ─────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────

echo "\n---\n";
echo "Total: {$checks}, Failures: {$failures}\n";
if ( $failures > 0 ) {
	exit( 1 );
}
