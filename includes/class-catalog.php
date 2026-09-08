<?php
/**
 * Fast product search for large catalogs.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalog queries.
 */
class MPO_Catalog {

	/**
	 * Search products by SKU (preferred) then name.
	 *
	 * @param string $term  Query.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function search( $term, $limit = 20 ) {
		$term  = trim( (string) $term );
		$limit = max( 1, min( 50, (int) $limit ) );

		if ( strlen( $term ) < 2 ) {
			return array();
		}

		$ids = array();

		$exact_sku = wc_get_product_id_by_sku( $term );
		if ( $exact_sku ) {
			$ids[] = (int) $exact_sku;
		}

		global $wpdb;
		$like = $wpdb->esc_like( $term ) . '%';

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) );

		if ( $table_exists ) {
			$sku_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$lookup} WHERE sku LIKE %s ORDER BY sku ASC LIMIT %d",
					$like,
					$limit
				)
			);
			foreach ( (array) $sku_ids as $id ) {
				$ids[] = (int) $id;
			}
		}

		$title_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ('product', 'product_variation')
				AND post_status = 'publish'
				AND post_title LIKE %s
				ORDER BY post_title ASC
				LIMIT %d",
				$like,
				$limit
			)
		);
		foreach ( (array) $title_ids as $id ) {
			$ids[] = (int) $id;
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( count( $ids ) < 5 ) {
			$contains = '%' . $wpdb->esc_like( $term ) . '%';
			$more     = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type IN ('product', 'product_variation')
					AND post_status = 'publish'
					AND post_title LIKE %s
					ORDER BY post_title ASC
					LIMIT %d",
					$contains,
					$limit
				)
			);
			foreach ( (array) $more as $id ) {
				$ids[] = (int) $id;
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
		}

		$ids = array_slice( $ids, 0, $limit );

		$out = array();
		foreach ( $ids as $id ) {
			$serialized = self::serialize_product( $id );
			if ( $serialized ) {
				$out[] = $serialized;
			}
		}

		return $out;
	}

	/**
	 * Product payload including variation attributes when needed.
	 *
	 * @param int $product_id Product.
	 * @return array|null
	 */
	public static function serialize_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent && 'publish' !== $parent->get_status() ) {
				return null;
			}
		} elseif ( 'publish' !== $product->get_status() ) {
			return null;
		}

		$image_id = $product->get_image_id();

		$data = array(
			'id'             => $product->get_id(),
			'parent_id'      => $product->get_parent_id(),
			'type'           => $product->get_type(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'price'          => (float) $product->get_price(),
			'price_html'     => $product->get_price_html(),
			'regular_price'  => (float) $product->get_regular_price(),
			'in_stock'       => $product->is_in_stock(),
			'stock_status'   => $product->get_stock_status(),
			'stock_qty'      => $product->get_stock_quantity(),
			'image'          => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) : wc_placeholder_img_src(),
			'permalink'      => $product->get_permalink(),
			'needs_variation'=> $product->is_type( 'variable' ),
			'is_grouped'     => $product->is_type( 'grouped' ),
			'purchasable'    => $product->is_purchasable() || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ),
		);

		try {
			$data['points'] = class_exists( 'MPO_Loyalty' ) ? MPO_Loyalty::product_points( $product ) : null;
		} catch ( Throwable $e ) {
			$data['points'] = null;
		}

		if ( $product->is_type( 'variable' ) ) {
			$data['attributes'] = self::variation_attributes( $product );
		}

		if ( $product->is_type( 'grouped' ) ) {
			$children = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = self::serialize_product( $child_id );
				if ( $child ) {
					$children[] = $child;
				}
			}
			$data['children'] = $children;
		}

		return $data;
	}

	/**
	 * Attributes for a variation picker.
	 *
	 * @param WC_Product_Variable $product Product.
	 * @return array
	 */
	private static function variation_attributes( $product ) {
		$out = array();
		foreach ( $product->get_variation_attributes() as $attribute => $options ) {
			$label = wc_attribute_label( $attribute );
			$items = array();
			foreach ( (array) $options as $option ) {
				$items[] = array(
					'slug'  => $option,
					'label' => rawurldecode( $option ),
				);
			}
			$out[] = array(
				'key'     => 'attribute_' . sanitize_title( $attribute ),
				'name'    => $attribute,
				'label'   => $label,
				'options' => $items,
			);
		}
		return $out;
	}

	/**
	 * Resolve a variation from posted attributes.
	 *
	 * @param int   $product_id Product.
	 * @param array $attributes Attrs.
	 * @return int
	 */
	public static function find_variation( $product_id, array $attributes ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}

		$data_store = WC_Data_Store::load( 'product' );
		return (int) $data_store->find_matching_product_variation( $product, $attributes );
	}
}
