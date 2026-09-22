<?php
/**
 * Plugin Name:       SEOMI MCP Abilities
 * Description:       Modular MCP abilities for WordPress content, taxonomy, media, and WooCommerce — for AI agents via the MCP Adapter plugin.
 * Version:           1.1.0
 * Author:            SEOMI
 * Requires at least: 6.4
 * Requires PHP:      8.0
 *
 * Loads modules registered under the `seomi_mcp_modules` filter and exposes
 * abilities under the `seomi/*` namespace.
 *
 * Mu-plugins are not auto-loaded from subdirectories, so this file is
 * included by a tiny loader at `wp-content/mu-plugins/mcp-abilities.php`.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SEOMI_MCP_VERSION' ) ) {
	return;
}

define( 'SEOMI_MCP_VERSION', '1.1.0' );
define( 'SEOMI_MCP_PATH', __DIR__ );

spl_autoload_register( function ( $class ) {
	$prefix = 'Seomi\\Mcp\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$path     = SEOMI_MCP_PATH . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );

/**
 * Verbose logger — writes to error_log when WP_DEBUG is on.
 */
function seomi_mcp_log( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[seomi-mcp] ' . $message );
	}
}

add_action( 'wp_abilities_api_categories_init', [ \Seomi\Mcp\Core::class, 'register_categories' ] );

add_action( 'wp_abilities_api_init', function () {

	if ( ! function_exists( 'wp_register_ability' ) ) {
		seomi_mcp_log( 'wp_register_ability() not available — MCP Adapter plugin missing or inactive; skipping.' );
		return;
	}

	$mcp_meta = [ 'mcp' => [ 'public' => true ] ];

	$module_map = [
		'posts'       => \Seomi\Mcp\Modules\Posts::class,
		'pages'       => \Seomi\Mcp\Modules\Pages::class,
		'terms'       => \Seomi\Mcp\Modules\Terms::class,
		'media'       => \Seomi\Mcp\Modules\Media::class,
		'woocommerce' => \Seomi\Mcp\Modules\WooCommerce::class,
	];

	$enabled = apply_filters( 'seomi_mcp_modules', array_keys( $module_map ) );
	// Boot banner: opt-in only. It fires on every request, so on a busy site with
	// WP_DEBUG on it dominates debug.log while telling nothing new once the
	// integration is known to work. It is worth having the first time an MCP setup
	// misbehaves, hence a flag rather than a deletion.
	//
	// A constant, not a filter: mu-plugins load before themes and regular plugins,
	// so nothing could have registered a callback by this point -- apply_filters()
	// here would only ever see the default. A constant can be set in wp-config.php,
	// which is loaded first. Define SEOMI_MCP_VERBOSE_BOOT as true to re-enable.
	if ( defined( 'SEOMI_MCP_VERBOSE_BOOT' ) && SEOMI_MCP_VERBOSE_BOOT ) {
		seomi_mcp_log( 'booted v' . SEOMI_MCP_VERSION . ', modules: ' . implode( ',', $enabled ) );
	}

	foreach ( $enabled as $key ) {
		if ( ! isset( $module_map[ $key ] ) ) {
			seomi_mcp_log( "unknown module key: {$key}" );
			continue;
		}
		$class = $module_map[ $key ];
		if ( ! class_exists( $class ) ) {
			seomi_mcp_log( "module class not found: {$class}" );
			continue;
		}
		$module = new $class();
		if ( ! $module instanceof \Seomi\Mcp\Modules\ModuleInterface ) {
			seomi_mcp_log( "module {$class} does not implement ModuleInterface" );
			continue;
		}
		try {
			$module->register( $mcp_meta );
		} catch ( \Throwable $e ) {
			seomi_mcp_log( "module {$key} registration failed: " . $e->getMessage() );
		}
	}
} );
