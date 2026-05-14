<?php
/**
 * Media module — attachments and featured image abilities.
 */

namespace Seomi\Mcp\Modules;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Media implements ModuleInterface {

	public function register( array $mcp_meta ): void {

		wp_register_ability( 'seomi/get-attachment', [
			'label'        => 'Get Attachment',
			'description'  => 'Get metadata of a single attachment by ID.',
			'category'     => 'media',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'attachment_id' ],
				'properties' => [
					'attachment_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$id  = (int) $input['attachment_id'];
				$att = get_post( $id );
				if ( ! $att || 'attachment' !== $att->post_type ) {
					return new WP_Error( 'not_found', 'Attachment not found' );
				}
				return [
					'id'          => $att->ID,
					'url'         => wp_get_attachment_url( $att->ID ),
					'file'        => get_post_meta( $att->ID, '_wp_attached_file', true ),
					'mime_type'   => $att->post_mime_type,
					'alt'         => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
					'caption'     => $att->post_excerpt,
					'title'       => $att->post_title,
					'description' => $att->post_content,
				];
			},
			'permission_callback' => fn() => current_user_can( 'upload_files' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/find-attachments-by-file', [
			'label'        => 'Find Attachments by File Substring',
			'description'  => 'Find attachments whose _wp_attached_file LIKE-matches a substring. Useful to locate images by filename fragment.',
			'category'     => 'media',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'file_substring' ],
				'properties' => [
					'file_substring' => [ 'type' => 'string' ],
					'limit'          => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 1000 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				global $wpdb;
				$needle = '%' . $wpdb->esc_like( (string) $input['file_substring'] ) . '%';
				$limit  = max( 1, min( 1000, (int) ( $input['limit'] ?? 100 ) ) );

				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
					 ORDER BY post_id DESC LIMIT %d",
					$needle,
					$limit
				) );

				$out = [];
				foreach ( $rows as $r ) {
					$id    = (int) $r->post_id;
					$out[] = [
						'id'   => $id,
						'file' => $r->meta_value,
						'url'  => wp_get_attachment_url( $id ),
					];
				}
				return $out;
			},
			'permission_callback' => fn() => current_user_can( 'upload_files' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/set-post-thumbnail', [
			'label'        => 'Set Post Thumbnail',
			'description'  => 'Set the featured image (thumbnail) for a post by attachment ID.',
			'category'     => 'media',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'post_id', 'attachment_id' ],
				'properties' => [
					'post_id'       => [ 'type' => 'integer' ],
					'attachment_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = (int) $input['post_id'];
				$att_id  = (int) $input['attachment_id'];

				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', 'Post not found' );
				}
				$att = get_post( $att_id );
				if ( ! $att || 'attachment' !== $att->post_type ) {
					return new WP_Error( 'not_found', 'Attachment not found' );
				}

				$ok = set_post_thumbnail( $post_id, $att_id );
				if ( ! $ok ) {
					return new WP_Error( 'set_failed', 'Could not set post thumbnail' );
				}
				\seomi_mcp_log( "[media] set-post-thumbnail post={$post_id} att={$att_id}" );
				return [ 'post_id' => $post_id, 'attachment_id' => $att_id, 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'edit_posts' ) && current_user_can( 'upload_files' ),
			'meta'                => $mcp_meta,
		] );
	}
}
