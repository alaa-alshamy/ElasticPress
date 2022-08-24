<?php
/**
 * Meta facet type
 *
 * @since 4.3.0
 * @package elasticpress
 */

namespace ElasticPress\Feature\Facets\Types\Meta;

use \ElasticPress\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Meta facet type class
 */
class FacetType extends \ElasticPress\Feature\Facets\FacetType {

	const TRANSIENT_PREFIX = 'ep_facet_meta_';

	/**
	 * Block instance.
	 *
	 * @var Block
	 */
	public $block;

	/**
	 * Setup hooks and filters for feature
	 */
	public function setup() {
		add_filter( 'ep_facet_agg_filters', [ $this, 'agg_filters' ], 10, 3 );
		add_action( 'pre_get_posts', [ $this, 'facet_query' ] );
		add_filter( 'ep_facet_wp_query_aggs_facet', [ $this, 'set_wp_query_aggs' ] );

		add_action( 'ep_delete_post', [ $this, 'invalidate_meta_values_cache' ] );
		add_action( 'ep_after_index_post', [ $this, 'invalidate_meta_values_cache' ] );
		add_action( 'ep_after_bulk_index', [ $this, 'invalidate_meta_values_cache_after_bulk' ], 10, 2 );

		$this->block = new Block();
		$this->block->setup();
	}

	/**
	 * If we are doing `or` (any) matches, we need to remove filters from aggs.
	 *
	 * By default, all filters applied to the main query are also applied to the aggregations.
	 *
	 * @param array $query_args Query arguments
	 * @return array
	 */
	public function agg_filters( $query_args ) {
		// Not a facetable query
		if ( empty( $query_args['ep_facet'] ) ) {
			return $query_args;
		}

		if ( ! class_exists( '\WP_Widget_Block' ) ) {
			return $query_args;
		}

		// Without a meta_query, there is nothing to do here.
		if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
			return $query_args;
		}

		/**
		 * If the aggregations need to match ALL the criteria applied to the main query,
		 * all the filters applied to the main query should be applied to aggregations as well.
		 */
		$feature  = Features::factory()->get_registered_feature( 'facets' );
		$settings = wp_parse_args(
			$feature->get_settings(),
			array(
				'match_type' => 'all',
			)
		);
		if ( 'all' === $settings['match_type'] ) {
			return $query_args;
		}

		/**
		 * If we got to this point, let's remove from the aggregation filters all
		 * meta fields used in facets.
		 */
		$facets_meta_fields = $this->get_facets_meta_fields();

		foreach ( $query_args['meta_query'] as $i => $meta_query_clause ) {
			if ( is_array( $meta_query_clause )
				&& ! empty( $meta_query_clause['key'] )
				&& in_array( $meta_query_clause['key'], $facets_meta_fields, true ) ) {
				unset( $query_args['meta_query'][ $i ] );
			}
		}

		return $query_args;
	}

	/**
	 * Get the facet filter name.
	 *
	 * @return string The filter name.
	 */
	public function get_filter_name() : string {
		/**
		 * Filter the facet filter name that's added to the URL
		 *
		 * @hook ep_facet_meta_filter_name
		 * @since 4.3.0
		 * @param   {string} Facet filter name
		 * @return  {string} New facet filter name
		 */
		return apply_filters( 'ep_facet_meta_filter_name', 'ep_meta_filter_' );
	}

	/**
	 * Get the facet filter type.
	 *
	 * @return string The filter name.
	 */
	public function get_filter_type() : string {
		/**
		 * Filter the facet filter type. Used by the Facet feature to organize filters.
		 *
		 * @hook ep_facet_filter_type
		 * @since 4.3.0
		 * @param   {string} Facet filter type
		 * @return  {string} New facet filter type
		 */
		return apply_filters( 'ep_facet_meta_filter_type', 'meta' );
	}

	/**
	 * Add meta fields to facets aggs
	 *
	 * @param array $facet_aggs Facet Aggs array.
	 * @since 4.3.0
	 * @return array
	 */
	public function set_wp_query_aggs( $facet_aggs ) {
		$facets_meta_fields = $this->get_facets_meta_fields();

		foreach ( $facets_meta_fields as $meta_field ) {
			/**
			 * Retrieve aggregations based on a custom field. This field must exist on the mapping.
			 * Values available out-of-the-box are:
			 * - raw (default)
			 * - long
			 * - double
			 * - boolean
			 * - date
			 * - datetime
			 * - time
			 *
			 * `meta.<field>.value` is *not* available, as that throws a `Fielddata is disabled on text fields by default` error.
			 *
			 * @since 4.3.0
			 * @hook ep_facet_meta_use_field
			 * @param {string} $es_field   The Elasticsearch field to use for this meta field
			 * @param {string} $meta_field The meta field key
			 * @return {string} The chosen ES field
			 */
			$facet_field = apply_filters( 'ep_facet_meta_use_field', 'raw', $meta_field );

			$facet_aggs[ $this->get_filter_name() . $meta_field ] = array(
				'terms' => array(
					/**
					 * Filter the number of different values (and their count) for the meta field returned by Elasticsearch.
					 *
					 * @since 4.3.0
					 * @hook ep_facet_meta_size
					 * @param {int}    $size  The number of different values. Default: 10000
					 * @param {string} $field The meta field
					 * @return {string} The new number of different values
					 */
					'size'  => apply_filters( 'ep_facet_meta_size', 10000, $meta_field ),
					'field' => 'meta.' . $meta_field . '.' . $facet_field,
				),
			);
		}

		return $facet_aggs;
	}

	/**
	 * Apply the facet selection to the main query.
	 *
	 * @param WP_Query $query WP Query
	 */
	public function facet_query( $query ) {
		$feature = Features::factory()->get_registered_feature( 'facets' );

		if ( ! $feature->is_facetable( $query ) ) {
			return;
		}

		$selected_filters = $feature->get_selected();

		if ( empty( $selected_filters ) || empty( $selected_filters[ $this->get_filter_type() ] ) ) {
			return;
		}

		$settings = wp_parse_args(
			$feature->get_settings(),
			array(
				'match_type' => 'all',
			)
		);

		$meta_query = (array) $query->get( 'meta_query', [] );

		$meta_fields = $selected_filters[ $this->get_filter_type() ];
		foreach ( $meta_fields as $meta_field => $values ) {
			$meta_query[] = [
				'key'      => $meta_field,
				'value'    => array_keys( $values['terms'] ),
				'compare'  => 'IN',
				'operator' => ( 'any' === $settings['match_type'] ) ? 'or' : 'and',
			];
		}

		if ( ! empty( $selected_filters[ $this->get_filter_type() ] ) && 'any' === $settings['match_type'] ) {
			$meta_query['relation'] = 'or';
		}

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Get all fields selected in all Facet blocks
	 *
	 * @return array
	 */
	public function get_facets_meta_fields() {
		$facets_meta_fields = [];

		$widget_block_instances = ( new \WP_Widget_Block() )->get_settings();
		foreach ( $widget_block_instances as $instance ) {
			if ( ! isset( $instance['content'] ) ) {
				continue;
			}

			if ( false === strpos( $instance['content'], 'elasticpress/facet-meta' ) ) {
				continue;
			}

			if ( ! preg_match_all( '/"facet":"(.*?)"/', $instance['content'], $matches ) ) {
				continue;
			}

			$facets_meta_fields = array_merge( $facets_meta_fields, $matches[1] );
		}

		/**
		 * Filter meta fields to be used in aggregations.
		 *
		 * @since 4.3.0
		 * @hook ep_facet_meta_fields
		 * @param {string} $facets_meta_fields Array of meta field keys
		 * @return {string} The array of meta field keys
		 */
		return apply_filters( 'ep_facet_meta_fields', $facets_meta_fields );
	}

	/**
	 * Get all values for the a given meta field.
	 *
	 * @param string $meta_key The meta field.
	 * @return array
	 */
	public function get_meta_values( string $meta_key ) : array {
		/**
		 * Short-circuits the process of getting distinct meta values.
		 *
		 * Returning a non-null value will effectively short-circuit the function.
		 *
		 * @since 4.3.0
		 * @hook ep_facet_meta_custom_meta_values
		 * @param {null}   $meta_values Distinct meta values array
		 * @param {array}  $meta_key    Key of the field.
		 * @return {null|array} Distinct meta values array or `null` to keep default behavior.
		 */
		$custom_meta_values = apply_filters( 'ep_facet_meta_custom_meta_values', null, $meta_key );
		if ( null !== $custom_meta_values ) {
			return $custom_meta_values;
		}

		$meta_values = get_transient( self::TRANSIENT_PREFIX . $meta_key );
		if ( ! $meta_values ) {
			$meta_values = \ElasticPress\Indexables::factory()->get( 'post' )->get_all_distinct_values( "meta.{$meta_key}.raw", 100 );

			/**
			 * Max length of each value in the facet.
			 *
			 * To set it to only display 3 characters of each value when the meta_key is `my_key`:
			 * ```
			 * add_filter(
			 *     'ep_facet_meta_value_max_strlen',
			 *     function( $length, $meta_key ) {
			 *         if ( 'my_key' !== $meta_key ) {
			 *             return $length;
			 *         }
			 *         return 3;
			 *     },
			 *     10,
			 *     3
			 * );
			 * ```
			 *
			 * Please note that this value is cached. After adding that code to your codebase you will need
			 * to clear WordPress's cache or save a post.
			 *
			 * @since 4.3.0
			 * @hook ep_facet_meta_value_max_strlen
			 * @param {int}    $length   Length of each value. Defaults to 100.
			 * @param {string} $meta_key Key of the field.
			 * @return {int} New length.
			 */
			$max_value_length = apply_filters( 'ep_facet_meta_value_max_strlen', 100, $meta_key );

			$meta_values = array_map(
				function ( $value ) use ( $max_value_length ) {
					return substr( $value, 0, $max_value_length );
				},
				$meta_values
			);
			set_transient( self::TRANSIENT_PREFIX . $meta_key, $meta_values );
		}

		return $meta_values;
	}

	/**
	 * Flush cached values of all facet fields.
	 */
	public function invalidate_meta_values_cache() {
		$fields = $this->get_facets_meta_fields();
		foreach ( $fields as $field ) {
			delete_transient( self::TRANSIENT_PREFIX . $field );
		}
	}

	/**
	 * Check if it is bulk indexing posts and, if so, flush facet fields cache.
	 *
	 * @param array  $object_ids     Object IDs being indexed
	 * @param string $indexable_slug Indexable slug
	 */
	public function invalidate_meta_values_cache_after_bulk( $object_ids, $indexable_slug ) {
		if ( 'post' !== $indexable_slug ) {
			return;
		}

		$this->invalidate_meta_values_cache();
	}
}
