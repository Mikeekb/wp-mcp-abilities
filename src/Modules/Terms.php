<?php
/**
 * Terms module — categories, tags, term search, and bulk description replace.
 *
 * All write operations on term descriptions go through Core::with_admin_term_kses()
 * so rich HTML (tables) survives outside the admin context (REST/MCP/CLI).
 */

namespace Seomi\Mcp\Modules;

use Seomi\Mcp\Core;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Terms implements ModuleInterface {

	public function register( array $mcp_meta ): void {

		// ─────────────────────────────────────────────────────────────────
		// CATEGORIES
		// ─────────────────────────────────────────────────────────────────

		wp_register_ability( 'seomi/get-categories', [
			'label'        => 'Get Categories',
			'description'  => 'Retrieve all categories with optional filtering.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
					'parent'     => [ 'type' => 'integer', 'description' => 'Parent category ID' ],
					'search'     => [ 'type' => 'string' ],
					'number'     => [ 'type' => 'integer', 'default' => 0, 'description' => '0 = all' ],
					'orderby'    => [ 'type' => 'string', 'default' => 'name' ],
					'order'      => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'ASC' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'taxonomy'   => 'category',
					'hide_empty' => $input['hide_empty'] ?? false,
					'parent'     => isset( $input['parent'] ) ? (int) $input['parent'] : null,
					'search'     => $input['search'] ?? null,
					'number'     => $input['number'] ?? 0,
					'orderby'    => $input['orderby'] ?? 'name',
					'order'      => $input['order'] ?? 'ASC',
				], fn( $v ) => ! is_null( $v ) );

				$terms = get_terms( $args );
				if ( is_wp_error( $terms ) ) {
					return $terms;
				}

				return array_map( fn( $t ) => [
					'term_id'     => $t->term_id,
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'parent'      => $t->parent,
					'count'       => $t->count,
				], $terms );
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/create-category', [
			'label'        => 'Create Category',
			'description'  => 'Create a new category.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'name' ],
				'properties' => [
					'name'        => [ 'type' => 'string' ],
					'slug'        => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string', 'default' => '' ],
					'parent'      => [ 'type' => 'integer', 'default' => 0 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'slug'        => $input['slug'] ?? null,
					'description' => $input['description'] ?? '',
					'parent'      => $input['parent'] ?? 0,
				], fn( $v ) => ! is_null( $v ) );

				$result = Core::with_admin_term_kses(
					fn() => wp_insert_term( $input['name'], 'category', $args )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[terms] create-category id={$result['term_id']}" );
				return [ 'term_id' => $result['term_id'], 'term_taxonomy_id' => $result['term_taxonomy_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/update-category', [
			'label'        => 'Update Category',
			'description'  => 'Update an existing category by ID.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'term_id' ],
				'properties' => [
					'term_id'     => [ 'type' => 'integer' ],
					'name'        => [ 'type' => 'string' ],
					'slug'        => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
					'parent'      => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$term_id = (int) $input['term_id'];
				$args    = array_filter( [
					'name'        => $input['name'] ?? null,
					'slug'        => $input['slug'] ?? null,
					'description' => $input['description'] ?? null,
					'parent'      => isset( $input['parent'] ) ? (int) $input['parent'] : null,
				], fn( $v ) => ! is_null( $v ) );

				$result = Core::with_admin_term_kses(
					fn() => wp_update_term( $term_id, 'category', $args )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[terms] update-category id={$result['term_id']}" );
				return [ 'term_id' => $result['term_id'], 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/delete-category', [
			'label'        => 'Delete Category',
			'description'  => 'Delete a category by ID.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'term_id' ],
				'properties' => [
					'term_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$result = wp_delete_term( (int) $input['term_id'], 'category' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Could not delete category' );
				}
				\seomi_mcp_log( '[terms] delete-category id=' . (int) $input['term_id'] );
				return [ 'deleted' => true, 'term_id' => (int) $input['term_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		// ─────────────────────────────────────────────────────────────────
		// TERM SEARCH & BULK REPLACE
		// ─────────────────────────────────────────────────────────────────

		wp_register_ability( 'seomi/search-terms', [
			'label'        => 'Search Terms by Description',
			'description'  => 'Find terms (any taxonomy) whose description contains a given substring. Returns full description and frontend URL.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'description_contains' ],
				'properties' => [
					'description_contains' => [ 'type' => 'string', 'description' => 'Substring to search for inside term descriptions (SQL LIKE)' ],
					'taxonomy'             => [ 'type' => 'string', 'default' => 'category', 'description' => 'Taxonomy slug, e.g. category, post_tag, or any custom taxonomy' ],
					'number'               => [ 'type' => 'integer', 'default' => 200, 'description' => '0 = all' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				global $wpdb;

				$needle   = '%' . $wpdb->esc_like( $input['description_contains'] ) . '%';
				$taxonomy = sanitize_key( $input['taxonomy'] ?? 'category' );
				$number   = (int) ( $input['number'] ?? 200 );
				$limit    = $number > 0 ? 'LIMIT ' . $number : '';

				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT t.term_id, t.name, t.slug, tt.description
					 FROM {$wpdb->terms} t
					 JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					 WHERE tt.taxonomy = %s AND tt.description LIKE %s
					 ORDER BY t.name $limit",
					$taxonomy,
					$needle
				) );

				return array_map( function ( $r ) use ( $taxonomy ) {
					return [
						'term_id'     => (int) $r->term_id,
						'name'        => $r->name,
						'slug'        => $r->slug,
						'description' => $r->description,
						'url'         => get_term_link( (int) $r->term_id, $taxonomy ),
					];
				}, $rows );
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/bulk-replace-in-term-descriptions', [
			'label'        => 'Bulk Replace in Term Descriptions',
			'description'  => 'Apply a regex (or plain string) search-and-replace across all term descriptions in a taxonomy. Returns list of updated terms with URLs.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'search', 'replace' ],
				'properties' => [
					'search'    => [ 'type' => 'string', 'description' => 'Search pattern. If use_regex=true, must be a valid PHP regex (e.g. /pattern/s). Otherwise treated as plain string.' ],
					'replace'   => [ 'type' => 'string', 'description' => 'Replacement string. May use $1, $2 back-references when use_regex=true.' ],
					'taxonomy'  => [ 'type' => 'string', 'default' => 'category', 'description' => 'Taxonomy slug to operate on.' ],
					'use_regex' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Set true to treat search as a PHP regex.' ],
					'dry_run'   => [ 'type' => 'boolean', 'default' => false, 'description' => 'If true, return matches without saving anything.' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				global $wpdb;

				$taxonomy  = sanitize_key( $input['taxonomy'] ?? 'category' );
				$search    = $input['search'];
				$replace   = $input['replace'];
				$use_regex = (bool) ( $input['use_regex'] ?? false );
				$dry_run   = (bool) ( $input['dry_run'] ?? false );

				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT t.term_id, t.name, tt.term_taxonomy_id, tt.description
					 FROM {$wpdb->terms} t
					 JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					 WHERE tt.taxonomy = %s AND tt.description != ''",
					$taxonomy
				) );

				$updated = [];
				$errors  = [];

				foreach ( $rows as $r ) {
					if ( $use_regex ) {
						$new_desc = preg_replace( $search, $replace, $r->description );
						if ( $new_desc === null ) {
							$errors[] = [ 'term_id' => (int) $r->term_id, 'name' => $r->name, 'error' => 'preg_replace error — check regex syntax' ];
							continue;
						}
					} else {
						$new_desc = str_replace( $search, $replace, $r->description );
					}

					if ( $new_desc === $r->description ) {
						continue;
					}

					$url = get_term_link( (int) $r->term_id, $taxonomy );

					if ( $dry_run ) {
						$updated[] = [ 'term_id' => (int) $r->term_id, 'name' => $r->name, 'url' => $url, 'dry_run' => true ];
						continue;
					}

					$result = Core::with_admin_term_kses( fn() => wp_update_term(
						(int) $r->term_id,
						$taxonomy,
						[ 'description' => $new_desc ]
					) );

					if ( is_wp_error( $result ) ) {
						$errors[] = [ 'term_id' => (int) $r->term_id, 'name' => $r->name, 'error' => $result->get_error_message() ];
					} else {
						$updated[] = [ 'term_id' => (int) $r->term_id, 'name' => $r->name, 'url' => $url ];
					}
				}

				\seomi_mcp_log( '[terms] bulk-replace taxonomy=' . $taxonomy . ' updated=' . count( $updated ) . ' errors=' . count( $errors ) . ( $dry_run ? ' dry_run' : '' ) );

				return [
					'updated_count' => count( $updated ),
					'updated'       => $updated,
					'errors'        => $errors,
				];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		// ─────────────────────────────────────────────────────────────────
		// TAGS
		// ─────────────────────────────────────────────────────────────────

		wp_register_ability( 'seomi/get-tags', [
			'label'        => 'Get Tags',
			'description'  => 'Retrieve all post tags.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
					'search'     => [ 'type' => 'string' ],
					'number'     => [ 'type' => 'integer', 'default' => 0 ],
					'orderby'    => [ 'type' => 'string', 'default' => 'name' ],
					'order'      => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'ASC' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'taxonomy'   => 'post_tag',
					'hide_empty' => $input['hide_empty'] ?? false,
					'search'     => $input['search'] ?? null,
					'number'     => $input['number'] ?? 0,
					'orderby'    => $input['orderby'] ?? 'name',
					'order'      => $input['order'] ?? 'ASC',
				], fn( $v ) => ! is_null( $v ) );

				$terms = get_terms( $args );
				if ( is_wp_error( $terms ) ) {
					return $terms;
				}

				return array_map( fn( $t ) => [
					'term_id'     => $t->term_id,
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'count'       => $t->count,
				], $terms );
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/create-tag', [
			'label'        => 'Create Tag',
			'description'  => 'Create a new post tag.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'name' ],
				'properties' => [
					'name'        => [ 'type' => 'string' ],
					'slug'        => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string', 'default' => '' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'slug'        => $input['slug'] ?? null,
					'description' => $input['description'] ?? '',
				], fn( $v ) => ! is_null( $v ) );

				$result = Core::with_admin_term_kses(
					fn() => wp_insert_term( $input['name'], 'post_tag', $args )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[terms] create-tag id={$result['term_id']}" );
				return [ 'term_id' => $result['term_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/update-tag', [
			'label'        => 'Update Tag',
			'description'  => 'Update an existing tag by ID.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'term_id' ],
				'properties' => [
					'term_id'     => [ 'type' => 'integer' ],
					'name'        => [ 'type' => 'string' ],
					'slug'        => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$term_id = (int) $input['term_id'];
				$args    = array_filter( [
					'name'        => $input['name'] ?? null,
					'slug'        => $input['slug'] ?? null,
					'description' => $input['description'] ?? null,
				], fn( $v ) => ! is_null( $v ) );

				$result = Core::with_admin_term_kses(
					fn() => wp_update_term( $term_id, 'post_tag', $args )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[terms] update-tag id={$result['term_id']}" );
				return [ 'term_id' => $result['term_id'], 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/delete-tag', [
			'label'        => 'Delete Tag',
			'description'  => 'Delete a tag by ID.',
			'category'     => 'taxonomy',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'term_id' ],
				'properties' => [
					'term_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$result = wp_delete_term( (int) $input['term_id'], 'post_tag' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Could not delete tag' );
				}
				\seomi_mcp_log( '[terms] delete-tag id=' . (int) $input['term_id'] );
				return [ 'deleted' => true, 'term_id' => (int) $input['term_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_categories' ),
			'meta'                => $mcp_meta,
		] );
	}
}
