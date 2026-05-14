<?php
/**
 * Pages module — abilities for managing WordPress pages.
 */

namespace Seomi\Mcp\Modules;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Pages implements ModuleInterface {

	public function register( array $mcp_meta ): void {

		wp_register_ability( 'seomi/get-pages', [
			'label'        => 'Get Pages',
			'description'  => 'Retrieve a list of pages with optional filtering.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash', 'any' ], 'default' => 'any' ],
					'numberposts' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
					'offset'      => [ 'type' => 'integer', 'default' => 0 ],
					'parent'      => [ 'type' => 'integer', 'description' => 'Parent page ID (0 = top level)' ],
					's'           => [ 'type' => 'string', 'description' => 'Search keyword' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'post_type'   => 'page',
					'post_status' => $input['post_status'] ?? 'any',
					'numberposts' => $input['numberposts'] ?? 50,
					'offset'      => $input['offset'] ?? 0,
					'post_parent' => isset( $input['parent'] ) ? (int) $input['parent'] : null,
					's'           => $input['s'] ?? null,
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				], fn( $v ) => ! is_null( $v ) );

				$pages = get_posts( $args );

				return array_map( fn( $p ) => [
					'ID'          => $p->ID,
					'post_title'  => $p->post_title,
					'post_status' => $p->post_status,
					'post_name'   => $p->post_name,
					'post_parent' => $p->post_parent,
					'menu_order'  => $p->menu_order,
					'guid'        => $p->guid,
				], $pages );
			},
			'permission_callback' => fn() => current_user_can( 'edit_pages' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/get-page', [
			'label'        => 'Get Page',
			'description'  => 'Get full content of a single page by ID.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$post = get_post( (int) $input['post_id'] );
				if ( ! $post || 'page' !== $post->post_type ) {
					return new WP_Error( 'not_found', 'Page not found' );
				}
				return [
					'ID'           => $post->ID,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_status'  => $post->post_status,
					'post_date'    => $post->post_date,
					'post_name'    => $post->post_name,
					'post_parent'  => $post->post_parent,
					'menu_order'   => $post->menu_order,
					'guid'         => $post->guid,
				];
			},
			'permission_callback' => fn() => current_user_can( 'edit_pages' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/create-page', [
			'label'        => 'Create Page',
			'description'  => 'Create a new WordPress page.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_title' ],
				'properties' => [
					'post_title'    => [ 'type' => 'string' ],
					'post_content'  => [ 'type' => 'string', 'default' => '' ],
					'post_excerpt'  => [ 'type' => 'string', 'default' => '' ],
					'post_status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
					'post_name'     => [ 'type' => 'string' ],
					'post_parent'   => [ 'type' => 'integer', 'default' => 0 ],
					'menu_order'    => [ 'type' => 'integer', 'default' => 0 ],
					'page_template' => [ 'type' => 'string' ],
					'meta_input'    => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'post_type'     => 'page',
					'post_title'    => $input['post_title'],
					'post_content'  => $input['post_content'] ?? '',
					'post_excerpt'  => $input['post_excerpt'] ?? '',
					'post_status'   => $input['post_status'] ?? 'draft',
					'post_name'     => $input['post_name'] ?? null,
					'post_parent'   => $input['post_parent'] ?? null,
					'menu_order'    => $input['menu_order'] ?? null,
					'page_template' => $input['page_template'] ?? null,
					'meta_input'    => $input['meta_input'] ?? null,
				], fn( $v ) => ! is_null( $v ) );

				$id = wp_insert_post( $args, true );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				\seomi_mcp_log( "[pages] create-page id={$id}" );
				return [ 'ID' => $id, 'url' => get_permalink( $id ) ];
			},
			'permission_callback' => fn() => current_user_can( 'publish_pages' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/update-page', [
			'label'        => 'Update Page',
			'description'  => 'Update an existing page by ID.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id'       => [ 'type' => 'integer' ],
					'post_title'    => [ 'type' => 'string' ],
					'post_content'  => [ 'type' => 'string' ],
					'post_excerpt'  => [ 'type' => 'string' ],
					'post_status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash' ] ],
					'post_name'     => [ 'type' => 'string' ],
					'post_parent'   => [ 'type' => 'integer' ],
					'menu_order'    => [ 'type' => 'integer' ],
					'page_template' => [ 'type' => 'string' ],
					'meta_input'    => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = (int) $input['post_id'];
				$post    = get_post( $post_id );
				if ( ! $post || 'page' !== $post->post_type ) {
					return new WP_Error( 'not_found', 'Page not found' );
				}

				$args = array_filter( [
					'ID'            => $post_id,
					'post_title'    => $input['post_title'] ?? null,
					'post_content'  => $input['post_content'] ?? null,
					'post_excerpt'  => $input['post_excerpt'] ?? null,
					'post_status'   => $input['post_status'] ?? null,
					'post_name'     => $input['post_name'] ?? null,
					'post_parent'   => $input['post_parent'] ?? null,
					'menu_order'    => $input['menu_order'] ?? null,
					'page_template' => $input['page_template'] ?? null,
					'meta_input'    => $input['meta_input'] ?? null,
				], fn( $v ) => ! is_null( $v ) );

				$result = wp_update_post( $args, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[pages] update-page id={$result}" );
				return [ 'ID' => $result, 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'edit_pages' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/delete-page', [
			'label'        => 'Delete Page',
			'description'  => 'Delete a page by ID. Set force_delete=true to bypass trash.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id'      => [ 'type' => 'integer' ],
					'force_delete' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = (int) $input['post_id'];
				$post    = get_post( $post_id );
				if ( ! $post || 'page' !== $post->post_type ) {
					return new WP_Error( 'not_found', 'Page not found' );
				}
				$result = wp_delete_post( $post_id, (bool) ( $input['force_delete'] ?? false ) );
				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Could not delete page' );
				}
				\seomi_mcp_log( "[pages] delete-page id={$post_id}" );
				return [ 'deleted' => true, 'ID' => $post_id ];
			},
			'permission_callback' => fn() => current_user_can( 'delete_pages' ),
			'meta'                => $mcp_meta,
		] );
	}
}
