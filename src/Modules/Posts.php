<?php
/**
 * Posts module — abilities for managing WordPress posts.
 */

namespace Seomi\Mcp\Modules;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Posts implements ModuleInterface {

	public function register( array $mcp_meta ): void {

		wp_register_ability( 'seomi/get-posts', [
			'label'        => 'Get Posts',
			'description'  => 'Retrieve a list of posts with optional filtering by status, category, tag, search query or IDs.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash', 'any' ], 'default' => 'any' ],
					'numberposts' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200 ],
					'offset'      => [ 'type' => 'integer', 'default' => 0 ],
					'category'    => [ 'type' => 'integer', 'description' => 'Category ID' ],
					'tag'         => [ 'type' => 'string', 'description' => 'Tag slug' ],
					's'           => [ 'type' => 'string', 'description' => 'Search keyword' ],
					'include'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of post IDs to include' ],
					'orderby'     => [ 'type' => 'string', 'default' => 'date' ],
					'order'       => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'post_type'   => 'post',
					'post_status' => $input['post_status'] ?? 'any',
					'numberposts' => $input['numberposts'] ?? 20,
					'offset'      => $input['offset'] ?? 0,
					'category'    => $input['category'] ?? null,
					'tag'         => $input['tag'] ?? null,
					's'           => $input['s'] ?? null,
					'include'     => $input['include'] ?? null,
					'orderby'     => $input['orderby'] ?? 'date',
					'order'       => $input['order'] ?? 'DESC',
				], fn( $v ) => ! is_null( $v ) );

				$posts = get_posts( $args );

				return array_map( fn( $p ) => [
					'ID'           => $p->ID,
					'post_title'   => $p->post_title,
					'post_status'  => $p->post_status,
					'post_date'    => $p->post_date,
					'post_excerpt' => $p->post_excerpt,
					'post_name'    => $p->post_name,
					'permalink'    => get_permalink( $p->ID ),
					'guid'         => $p->guid,
				], $posts );
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/get-post', [
			'label'        => 'Get Post',
			'description'  => 'Get full content of a single post by ID.',
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
				if ( ! $post ) {
					return new WP_Error( 'not_found', 'Post not found' );
				}
				$thumb_id  = (int) get_post_thumbnail_id( $post->ID );
				$thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
				return [
					'ID'            => $post->ID,
					'post_title'    => $post->post_title,
					'post_content'  => $post->post_content,
					'post_excerpt'  => $post->post_excerpt,
					'post_status'   => $post->post_status,
					'post_date'     => $post->post_date,
					'post_name'     => $post->post_name,
					'post_author'   => (int) $post->post_author,
					'permalink'     => get_permalink( $post->ID ),
					'guid'          => $post->guid,
					'thumbnail_id'  => $thumb_id,
					'thumbnail_url' => $thumb_url,
					'categories'    => wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] ),
					'tags'          => wp_get_post_tags( $post->ID ),
				];
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/get-post-meta', [
			'label'        => 'Get Post Meta',
			'description'  => 'Read post meta (incl. ACF fields) for one or many posts. By default returns all non-underscored keys.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_ids' ],
				'properties' => [
					'post_ids'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Post IDs to read meta for.' ],
					'keys'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Optional whitelist of meta keys. If omitted, returns all non-underscored keys.' ],
					'include_private' => [ 'type' => 'boolean', 'default' => false, 'description' => 'If true, also return keys starting with _ (ignored when keys is provided).' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$ids             = array_values( array_unique( array_map( 'intval', (array) ( $input['post_ids'] ?? [] ) ) ) );
				$keys            = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_map( 'strval', $input['keys'] ) : null;
				$include_private = (bool) ( $input['include_private'] ?? false );

				$result = [];
				foreach ( $ids as $id ) {
					if ( $id <= 0 ) {
						continue;
					}
					$all  = get_post_meta( $id );
					$meta = [];
					foreach ( $all as $k => $v ) {
						if ( $keys === null ) {
							if ( ! $include_private && strpos( $k, '_' ) === 0 ) {
								continue;
							}
						} else {
							if ( ! in_array( $k, $keys, true ) ) {
								continue;
							}
						}
						$vals       = array_map( 'maybe_unserialize', $v );
						$meta[ $k ] = count( $vals ) === 1 ? $vals[0] : $vals;
					}
					$result[ (string) $id ] = $meta;
				}
				return $result;
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/find-posts-by-thumbnail', [
			'label'        => 'Find Posts by Thumbnail File',
			'description'  => 'Find posts whose featured image attached file path matches a substring (against _wp_attached_file). Useful to group posts that share a fallback/category image. Returns permalink and current ACF price.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'file_substring' ],
				'properties' => [
					'file_substring' => [ 'type' => 'string', 'description' => 'Substring to match against _wp_attached_file, e.g. "raspashnye-vorota".' ],
					'post_type'      => [ 'type' => 'string', 'default' => 'post' ],
					'post_status'    => [ 'type' => 'string', 'default' => 'publish' ],
					'category'       => [ 'type' => 'integer', 'description' => 'Optional category ID filter.' ],
					'numberposts'    => [ 'type' => 'integer', 'default' => 500, 'minimum' => 1, 'maximum' => 5000 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				global $wpdb;
				$needle      = '%' . $wpdb->esc_like( (string) $input['file_substring'] ) . '%';
				$post_type   = sanitize_key( $input['post_type'] ?? 'post' );
				$post_status = sanitize_key( $input['post_status'] ?? 'publish' );
				$category    = isset( $input['category'] ) ? (int) $input['category'] : 0;
				$limit       = max( 1, min( 5000, (int) ( $input['numberposts'] ?? 500 ) ) );

				$att_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
					$needle
				) );
				if ( empty( $att_ids ) ) {
					return [];
				}
				$att_ids = array_map( 'intval', $att_ids );

				$placeholders = implode( ',', array_fill( 0, count( $att_ids ), '%d' ) );
				$sql          = "SELECT p.ID, p.post_title, p.post_name, pm.meta_value AS thumbnail_id
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
						WHERE p.post_type = %s
						  AND p.post_status = %s
						  AND pm.meta_value IN ($placeholders)
						ORDER BY p.ID DESC
						LIMIT %d";

				$params = array_merge( [ $post_type, $post_status ], $att_ids, [ $limit ] );
				$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

				$out = [];
				foreach ( $rows as $r ) {
					$pid = (int) $r->ID;
					if ( $category && ! has_category( $category, $pid ) ) {
						continue;
					}
					$thumb_id = (int) $r->thumbnail_id;
					$price    = function_exists( 'get_field' ) ? get_field( 'price', $pid ) : get_post_meta( $pid, 'price', true );
					$out[]    = [
						'ID'            => $pid,
						'post_title'    => $r->post_title,
						'post_name'     => $r->post_name,
						'permalink'     => get_permalink( $pid ),
						'thumbnail_id'  => $thumb_id,
						'thumbnail_url' => wp_get_attachment_url( $thumb_id ),
						'price'         => $price,
						'categories'    => array_map( 'intval', wp_get_post_categories( $pid ) ),
					];
				}
				return $out;
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/create-post', [
			'label'        => 'Create Post',
			'description'  => 'Create a new WordPress post.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_title' ],
				'properties' => [
					'post_title'    => [ 'type' => 'string' ],
					'post_content'  => [ 'type' => 'string', 'default' => '' ],
					'post_excerpt'  => [ 'type' => 'string', 'default' => '' ],
					'post_status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
					'post_name'     => [ 'type' => 'string', 'description' => 'URL slug' ],
					'post_author'   => [ 'type' => 'integer' ],
					'post_category' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Array of category IDs' ],
					'tags_input'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Array of tag names/slugs' ],
					'meta_input'    => [ 'type' => 'object', 'description' => 'Custom fields as key-value pairs' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'post_type'     => 'post',
					'post_title'    => $input['post_title'],
					'post_content'  => $input['post_content'] ?? '',
					'post_excerpt'  => $input['post_excerpt'] ?? '',
					'post_status'   => $input['post_status'] ?? 'draft',
					'post_name'     => $input['post_name'] ?? null,
					'post_author'   => $input['post_author'] ?? null,
					'post_category' => $input['post_category'] ?? null,
					'tags_input'    => $input['tags_input'] ?? null,
					'meta_input'    => $input['meta_input'] ?? null,
				], fn( $v ) => ! is_null( $v ) );

				$id = wp_insert_post( $args, true );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				\seomi_mcp_log( "[posts] create-post id={$id}" );
				return [ 'ID' => $id, 'url' => get_permalink( $id ) ];
			},
			'permission_callback' => fn() => current_user_can( 'publish_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/update-post', [
			'label'        => 'Update Post',
			'description'  => 'Update an existing post by ID.',
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
					'post_category' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
					'tags_input'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'meta_input'    => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = (int) $input['post_id'];
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', 'Post not found' );
				}

				$args = array_filter( [
					'ID'            => $post_id,
					'post_title'    => $input['post_title'] ?? null,
					'post_content'  => $input['post_content'] ?? null,
					'post_excerpt'  => $input['post_excerpt'] ?? null,
					'post_status'   => $input['post_status'] ?? null,
					'post_name'     => $input['post_name'] ?? null,
					'post_category' => $input['post_category'] ?? null,
					'tags_input'    => $input['tags_input'] ?? null,
					'meta_input'    => $input['meta_input'] ?? null,
				], fn( $v ) => ! is_null( $v ) );

				$result = wp_update_post( $args, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[posts] update-post id={$result}" );
				return [ 'ID' => $result, 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/delete-post', [
			'label'        => 'Delete Post',
			'description'  => 'Delete a post by ID. Set force_delete=true to bypass trash.',
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
				$result  = wp_delete_post( $post_id, (bool) ( $input['force_delete'] ?? false ) );
				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Could not delete post' );
				}
				\seomi_mcp_log( "[posts] delete-post id={$post_id}" );
				return [ 'deleted' => true, 'ID' => $post_id ];
			},
			'permission_callback' => fn() => current_user_can( 'delete_posts' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/bulk-replace-in-posts', [
			'label'        => 'Bulk Replace in Posts/Pages',
			'description'  => 'Apply a regex (or plain string) search-and-replace across post_content of posts and/or pages. Returns list of updated items with URLs.',
			'category'     => 'content',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'search', 'replace' ],
				'properties' => [
					'search'      => [ 'type' => 'string', 'description' => 'Search pattern. If use_regex=true, must be a valid PHP regex (e.g. /pattern/s). Otherwise treated as plain string.' ],
					'replace'     => [ 'type' => 'string', 'description' => 'Replacement string. May use $1, $2 back-references when use_regex=true.' ],
					'post_type'   => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'default'     => [ 'post', 'page' ],
						'description' => 'Post types to include, e.g. ["post","page"] or any CPT slug.',
					],
					'post_status' => [ 'type' => 'string', 'default' => 'any', 'description' => 'Post status filter.' ],
					'use_regex'   => [ 'type' => 'boolean', 'default' => false, 'description' => 'Set true to treat search as a PHP regex.' ],
					'dry_run'     => [ 'type' => 'boolean', 'default' => false, 'description' => 'If true, return matches without saving anything.' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				global $wpdb;

				$search      = $input['search'];
				$replace     = $input['replace'];
				$use_regex   = (bool) ( $input['use_regex'] ?? false );
				$dry_run     = (bool) ( $input['dry_run'] ?? false );
				$post_types  = $input['post_type'] ?? [ 'post', 'page' ];
				$post_status = $input['post_status'] ?? 'any';

				if ( ! is_array( $post_types ) || empty( $post_types ) ) {
					return new WP_Error( 'invalid_input', 'post_type must be a non-empty array' );
				}
				$post_types = array_map( 'sanitize_key', $post_types );

				$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
				$status_sql   = $post_status === 'any' ? '' : $wpdb->prepare( 'AND post_status = %s', $post_status );

				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_title, post_content, post_type, post_status
					 FROM {$wpdb->posts}
					 WHERE post_type IN ($placeholders) $status_sql
					   AND post_status NOT IN ('auto-draft','inherit','trash')
					   AND post_content != ''",
					...$post_types
				) );

				$updated = [];
				$errors  = [];

				foreach ( $rows as $r ) {
					if ( $use_regex ) {
						$new_content = preg_replace( $search, $replace, $r->post_content );
						if ( $new_content === null ) {
							$errors[] = [ 'ID' => (int) $r->ID, 'post_title' => $r->post_title, 'error' => 'preg_replace error — check regex syntax' ];
							continue;
						}
					} else {
						$new_content = str_replace( $search, $replace, $r->post_content );
					}

					if ( $new_content === $r->post_content ) {
						continue;
					}

					$url = get_permalink( (int) $r->ID );

					if ( $dry_run ) {
						$updated[] = [ 'ID' => (int) $r->ID, 'post_title' => $r->post_title, 'post_type' => $r->post_type, 'url' => $url, 'dry_run' => true ];
						continue;
					}

					$result = wp_update_post( [
						'ID'           => (int) $r->ID,
						'post_content' => $new_content,
					], true );

					if ( is_wp_error( $result ) ) {
						$errors[] = [ 'ID' => (int) $r->ID, 'post_title' => $r->post_title, 'error' => $result->get_error_message() ];
					} else {
						$updated[] = [ 'ID' => (int) $r->ID, 'post_title' => $r->post_title, 'post_type' => $r->post_type, 'url' => $url ];
					}
				}

				\seomi_mcp_log( '[posts] bulk-replace updated=' . count( $updated ) . ' errors=' . count( $errors ) . ( $dry_run ? ' dry_run' : '' ) );

				return [
					'updated_count' => count( $updated ),
					'updated'       => $updated,
					'errors'        => $errors,
				];
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'meta'                => $mcp_meta,
		] );
	}
}
