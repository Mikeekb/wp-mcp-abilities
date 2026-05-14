<?php
/**
 * WooCommerce module — products, product categories, and orders.
 *
 * Loaded unconditionally but no-ops on sites without WooCommerce.
 * All product writes go through the WC CRUD API (wc_get_product, $product->save())
 * so WooCommerce lookup tables stay in sync. Never use wp_insert_post for products.
 */

namespace Seomi\Mcp\Modules;

use Seomi\Mcp\Core;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class WooCommerce implements ModuleInterface {

	public function register( array $mcp_meta ): void {

		if ( ! class_exists( 'WooCommerce' ) ) {
			\seomi_mcp_log( '[wc] WooCommerce not active — skipping module' );
			return;
		}

		$this->register_products( $mcp_meta );
		$this->register_product_categories( $mcp_meta );
		$this->register_orders( $mcp_meta );
	}

	private function register_products( array $mcp_meta ): void {

		wp_register_ability( 'seomi/wc/get-products', [
			'label'        => 'WC: Get Products',
			'description'  => 'List WooCommerce products with optional filters.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ], 'default' => 'any' ],
					'category' => [ 'type' => 'integer', 'description' => 'product_cat term_id' ],
					'search'   => [ 'type' => 'string' ],
					'sku'      => [ 'type' => 'string' ],
					'limit'    => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200 ],
					'offset'   => [ 'type' => 'integer', 'default' => 0 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = [
					'status' => $input['status'] ?? 'any',
					'limit'  => $input['limit'] ?? 20,
					'offset' => $input['offset'] ?? 0,
				];
				if ( ! empty( $input['category'] ) ) {
					$args['category'] = [ (int) $input['category'] ];
				}
				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}
				if ( ! empty( $input['sku'] ) ) {
					$args['sku'] = $input['sku'];
				}

				$products = wc_get_products( $args );

				return array_map( function ( $p ) {
					return [
						'id'             => $p->get_id(),
						'name'           => $p->get_name(),
						'slug'           => $p->get_slug(),
						'sku'            => $p->get_sku(),
						'price'          => $p->get_price(),
						'regular_price'  => $p->get_regular_price(),
						'sale_price'     => $p->get_sale_price(),
						'stock_status'   => $p->get_stock_status(),
						'stock_quantity' => $p->get_stock_quantity(),
						'permalink'      => $p->get_permalink(),
						'categories'     => $p->get_category_ids(),
					];
				}, $products );
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/get-product', [
			'label'        => 'WC: Get Product',
			'description'  => 'Get full data of a single product by ID (incl. ACF meta).',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id' ],
				'properties' => [
					'product_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$product = wc_get_product( (int) $input['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'not_found', 'Product not found' );
				}
				$data = $product->get_data();

				// Add ACF / non-private meta on top of WC data.
				$all_meta  = get_post_meta( $product->get_id() );
				$user_meta = [];
				foreach ( $all_meta as $k => $v ) {
					if ( strpos( $k, '_' ) === 0 ) {
						continue;
					}
					$vals             = array_map( 'maybe_unserialize', $v );
					$user_meta[ $k ] = count( $vals ) === 1 ? $vals[0] : $vals;
				}
				$data['meta'] = $user_meta;

				return $data;
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/create-product', [
			'label'        => 'WC: Create Product',
			'description'  => 'Create a new WooCommerce product (simple or variable).',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'name' ],
				'properties' => [
					'name'              => [ 'type' => 'string' ],
					'type'              => [ 'type' => 'string', 'enum' => [ 'simple', 'variable' ], 'default' => 'simple' ],
					'sku'               => [ 'type' => 'string' ],
					'regular_price'     => [ 'type' => 'string' ],
					'sale_price'        => [ 'type' => 'string' ],
					'description'       => [ 'type' => 'string' ],
					'short_description' => [ 'type' => 'string' ],
					'status'            => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ], 'default' => 'draft' ],
					'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'product_cat term IDs' ],
					'images'            => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Attachment IDs; first = featured' ],
					'meta_input'        => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$type    = $input['type'] ?? 'simple';
				$class   = 'variable' === $type ? \WC_Product_Variable::class : \WC_Product_Simple::class;
				$product = new $class();

				$product->set_name( $input['name'] );
				if ( isset( $input['sku'] ) ) {
					$product->set_sku( $input['sku'] );
				}
				if ( isset( $input['regular_price'] ) ) {
					$product->set_regular_price( $input['regular_price'] );
				}
				if ( isset( $input['sale_price'] ) ) {
					$product->set_sale_price( $input['sale_price'] );
				}
				if ( isset( $input['description'] ) ) {
					$product->set_description( $input['description'] );
				}
				if ( isset( $input['short_description'] ) ) {
					$product->set_short_description( $input['short_description'] );
				}
				if ( isset( $input['status'] ) ) {
					$product->set_status( $input['status'] );
				}
				if ( ! empty( $input['categories'] ) ) {
					$product->set_category_ids( array_map( 'intval', $input['categories'] ) );
				}
				if ( ! empty( $input['images'] ) ) {
					$images = array_map( 'intval', $input['images'] );
					$product->set_image_id( array_shift( $images ) );
					if ( $images ) {
						$product->set_gallery_image_ids( $images );
					}
				}
				if ( ! empty( $input['meta_input'] ) && is_array( $input['meta_input'] ) ) {
					foreach ( $input['meta_input'] as $k => $v ) {
						$product->update_meta_data( (string) $k, $v );
					}
				}

				$id = $product->save();
				if ( ! $id ) {
					return new WP_Error( 'wc_save_failed', 'Could not save product' );
				}
				\seomi_mcp_log( "[wc] create-product id={$id}" );
				return [ 'id' => $id, 'permalink' => $product->get_permalink() ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/update-product', [
			'label'        => 'WC: Update Product',
			'description'  => 'Update an existing WooCommerce product by ID.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id' ],
				'properties' => [
					'product_id'        => [ 'type' => 'integer' ],
					'name'              => [ 'type' => 'string' ],
					'sku'               => [ 'type' => 'string' ],
					'regular_price'     => [ 'type' => 'string' ],
					'sale_price'        => [ 'type' => 'string' ],
					'description'       => [ 'type' => 'string' ],
					'short_description' => [ 'type' => 'string' ],
					'status'            => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ] ],
					'categories'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
					'meta_input'        => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$product = wc_get_product( (int) $input['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'not_found', 'Product not found' );
				}

				if ( isset( $input['name'] ) ) {
					$product->set_name( $input['name'] );
				}
				if ( isset( $input['sku'] ) ) {
					$product->set_sku( $input['sku'] );
				}
				if ( isset( $input['regular_price'] ) ) {
					$product->set_regular_price( $input['regular_price'] );
				}
				if ( isset( $input['sale_price'] ) ) {
					$product->set_sale_price( $input['sale_price'] );
				}
				if ( isset( $input['description'] ) ) {
					$product->set_description( $input['description'] );
				}
				if ( isset( $input['short_description'] ) ) {
					$product->set_short_description( $input['short_description'] );
				}
				if ( isset( $input['status'] ) ) {
					$product->set_status( $input['status'] );
				}
				if ( isset( $input['categories'] ) ) {
					$product->set_category_ids( array_map( 'intval', $input['categories'] ) );
				}
				if ( ! empty( $input['meta_input'] ) && is_array( $input['meta_input'] ) ) {
					foreach ( $input['meta_input'] as $k => $v ) {
						$product->update_meta_data( (string) $k, $v );
					}
				}

				$id = $product->save();
				\seomi_mcp_log( "[wc] update-product id={$id}" );
				return [ 'id' => $id, 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/delete-product', [
			'label'        => 'WC: Delete Product',
			'description'  => 'Delete a product by ID. Set force=true to bypass trash.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id' ],
				'properties' => [
					'product_id' => [ 'type' => 'integer' ],
					'force'      => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$product = wc_get_product( (int) $input['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'not_found', 'Product not found' );
				}
				$ok = $product->delete( (bool) ( $input['force'] ?? false ) );
				if ( ! $ok ) {
					return new WP_Error( 'delete_failed', 'Could not delete product' );
				}
				\seomi_mcp_log( '[wc] delete-product id=' . (int) $input['product_id'] );
				return [ 'deleted' => true, 'id' => (int) $input['product_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/update-product-price', [
			'label'        => 'WC: Update Product Price',
			'description'  => 'Narrow ability to update only price fields of a product.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id', 'regular_price' ],
				'properties' => [
					'product_id'    => [ 'type' => 'integer' ],
					'regular_price' => [ 'type' => 'string' ],
					'sale_price'    => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$product = wc_get_product( (int) $input['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'not_found', 'Product not found' );
				}
				$product->set_regular_price( $input['regular_price'] );
				if ( isset( $input['sale_price'] ) ) {
					$product->set_sale_price( $input['sale_price'] );
				}
				$id = $product->save();
				\seomi_mcp_log( "[wc] update-product-price id={$id}" );
				return [ 'id' => $id, 'price' => $product->get_price(), 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/update-product-stock', [
			'label'        => 'WC: Update Product Stock',
			'description'  => 'Update stock status and optional stock quantity for a product.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'product_id', 'stock_status' ],
				'properties' => [
					'product_id'     => [ 'type' => 'integer' ],
					'stock_status'   => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
					'stock_quantity' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$product = wc_get_product( (int) $input['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'not_found', 'Product not found' );
				}
				$product->set_stock_status( $input['stock_status'] );
				if ( isset( $input['stock_quantity'] ) ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( (int) $input['stock_quantity'] );
				}
				$id = $product->save();
				\seomi_mcp_log( "[wc] update-product-stock id={$id}" );
				return [ 'id' => $id, 'stock_status' => $product->get_stock_status(), 'stock_quantity' => $product->get_stock_quantity(), 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );
	}

	private function register_product_categories( array $mcp_meta ): void {

		wp_register_ability( 'seomi/wc/get-product-categories', [
			'label'        => 'WC: Get Product Categories',
			'description'  => 'Retrieve product_cat taxonomy terms.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
					'parent'     => [ 'type' => 'integer' ],
					'search'     => [ 'type' => 'string' ],
					'number'     => [ 'type' => 'integer', 'default' => 0 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = array_filter( [
					'taxonomy'   => 'product_cat',
					'hide_empty' => $input['hide_empty'] ?? false,
					'parent'     => isset( $input['parent'] ) ? (int) $input['parent'] : null,
					'search'     => $input['search'] ?? null,
					'number'     => $input['number'] ?? 0,
					'orderby'    => 'name',
					'order'      => 'ASC',
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
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/create-product-category', [
			'label'        => 'WC: Create Product Category',
			'description'  => 'Create a new product_cat term.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'name' ],
				'properties' => [
					'name'        => [ 'type' => 'string' ],
					'slug'        => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
					'parent'      => [ 'type' => 'integer', 'default' => 0 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args   = array_filter( [
					'slug'        => $input['slug'] ?? null,
					'description' => $input['description'] ?? '',
					'parent'      => $input['parent'] ?? 0,
				], fn( $v ) => ! is_null( $v ) );
				$result = Core::with_admin_term_kses(
					fn() => wp_insert_term( $input['name'], 'product_cat', $args )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[wc] create-product-category id={$result['term_id']}" );
				return [ 'term_id' => $result['term_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/update-product-category', [
			'label'        => 'WC: Update Product Category',
			'description'  => 'Update a product_cat term by ID.',
			'category'     => 'woocommerce',
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
				$args   = array_filter( [
					'name'        => $input['name'] ?? null,
					'slug'        => $input['slug'] ?? null,
					'description' => $input['description'] ?? null,
					'parent'      => isset( $input['parent'] ) ? (int) $input['parent'] : null,
				], fn( $v ) => ! is_null( $v ) );
				$result = Core::with_admin_term_kses(
					fn() => wp_update_term( (int) $input['term_id'], 'product_cat', $args )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				\seomi_mcp_log( "[wc] update-product-category id={$result['term_id']}" );
				return [ 'term_id' => $result['term_id'], 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/delete-product-category', [
			'label'        => 'WC: Delete Product Category',
			'description'  => 'Delete a product_cat term by ID.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'term_id' ],
				'properties' => [
					'term_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$result = wp_delete_term( (int) $input['term_id'], 'product_cat' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Could not delete product category' );
				}
				\seomi_mcp_log( '[wc] delete-product-category id=' . (int) $input['term_id'] );
				return [ 'deleted' => true, 'term_id' => (int) $input['term_id'] ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );
	}

	private function register_orders( array $mcp_meta ): void {

		wp_register_ability( 'seomi/wc/get-orders', [
			'label'        => 'WC: Get Orders',
			'description'  => 'List WooCommerce orders with optional filters.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'status'      => [ 'type' => 'string', 'description' => 'wc-* status without prefix, e.g. "processing", "completed", or "any"' ],
					'customer_id' => [ 'type' => 'integer' ],
					'limit'       => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200 ],
					'offset'      => [ 'type' => 'integer', 'default' => 0 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = [
					'limit'  => $input['limit'] ?? 20,
					'offset' => $input['offset'] ?? 0,
				];
				if ( ! empty( $input['status'] ) ) {
					$args['status'] = $input['status'];
				}
				if ( ! empty( $input['customer_id'] ) ) {
					$args['customer_id'] = (int) $input['customer_id'];
				}
				$orders = wc_get_orders( $args );
				return array_map( function ( $o ) {
					return [
						'id'           => $o->get_id(),
						'status'       => $o->get_status(),
						'total'        => $o->get_total(),
						'currency'     => $o->get_currency(),
						'customer_id'  => $o->get_customer_id(),
						'date_created' => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
						'items_count'  => $o->get_item_count(),
					];
				}, $orders );
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/get-order', [
			'label'        => 'WC: Get Order',
			'description'  => 'Get full data of a single order by ID (incl. line items).',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'order_id' ],
				'properties' => [
					'order_id' => [ 'type' => 'integer' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$order = wc_get_order( (int) $input['order_id'] );
				if ( ! $order ) {
					return new WP_Error( 'not_found', 'Order not found' );
				}
				$data          = $order->get_data();
				$data['items'] = [];
				foreach ( $order->get_items() as $item_id => $item ) {
					$data['items'][] = [
						'item_id'    => $item_id,
						'name'       => $item->get_name(),
						'product_id' => $item->get_product_id(),
						'quantity'   => $item->get_quantity(),
						'subtotal'   => $item->get_subtotal(),
						'total'      => $item->get_total(),
					];
				}
				return $data;
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );

		wp_register_ability( 'seomi/wc/update-order-status', [
			'label'        => 'WC: Update Order Status',
			'description'  => 'Change the status of an order. Optionally add a customer-visible or private note.',
			'category'     => 'woocommerce',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'order_id', 'status' ],
				'properties' => [
					'order_id' => [ 'type' => 'integer' ],
					'status'   => [ 'type' => 'string', 'description' => 'wc-* status without prefix, e.g. "processing", "completed", "on-hold"' ],
					'note'     => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$order = wc_get_order( (int) $input['order_id'] );
				if ( ! $order ) {
					return new WP_Error( 'not_found', 'Order not found' );
				}
				$order->update_status( $input['status'], $input['note'] ?? '', false );
				\seomi_mcp_log( '[wc] update-order-status id=' . $order->get_id() . ' status=' . $order->get_status() );
				return [ 'id' => $order->get_id(), 'status' => $order->get_status(), 'updated' => true ];
			},
			'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
			'meta'                => $mcp_meta,
		] );
	}
}
