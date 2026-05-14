<?php
/**
 * Contract for a SEOMI MCP module.
 *
 * Each module is instantiated once during `wp_abilities_api_init` and
 * receives the shared MCP meta array. It is responsible for calling
 * `wp_register_ability()` for every ability it owns.
 */

namespace Seomi\Mcp\Modules;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	/**
	 * Register all abilities owned by this module.
	 *
	 * @param array $mcp_meta Shared MCP meta (e.g. `[ 'mcp' => [ 'public' => true ] ]`).
	 */
	public function register( array $mcp_meta ): void;
}
