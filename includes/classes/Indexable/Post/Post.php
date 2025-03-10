<?php
/**
 * User indexable
 *
 * @since  3.0
 * @package  elasticpress
 */

namespace ElasticPress\Indexable\Post;

use ElasticPress\Indexable as Indexable;
use ElasticPress\Elasticsearch as Elasticsearch;
use \WP_Query as WP_Query;
use \WP_User as WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	// @codeCoverageIgnoreStart
	exit; // Exit if accessed directly.
	// @codeCoverageIgnoreEnd
}

/**
 * Post indexable class
 */
class Post extends Indexable {

	/**
	 * Indexable slug used for identification
	 *
	 * @var   string
	 * @since 3.0
	 */
	public $slug = 'post';

	/**
	 * Flag to indicate if the indexable has support for
	 * `id_range` pagination method during a sync.
	 *
	 * @var boolean
	 * @since 4.1.0
	 */
	public $support_indexing_advanced_pagination = true;

	/**
	 * Create indexable and initialize dependencies
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->labels = [
			'plural'   => esc_html__( 'Posts', 'elasticpress' ),
			'singular' => esc_html__( 'Post', 'elasticpress' ),
		];

		$this->sync_manager      = new SyncManager( $this->slug );
		$this->query_integration = new QueryIntegration( $this->slug );
	}

	/**
	 * Query database for posts
	 *
	 * @param  array $args Query DB args
	 * @since  3.0
	 * @return array
	 */
	public function query_db( $args ) {
		$defaults = [
			'posts_per_page'                  => $this->get_bulk_items_per_page(),
			'post_type'                       => $this->get_indexable_post_types(),
			'post_status'                     => $this->get_indexable_post_status(),
			'offset'                          => 0,
			'ignore_sticky_posts'             => true,
			'orderby'                         => 'ID',
			'order'                           => 'desc',
			'no_found_rows'                   => false,
			'ep_indexing_advanced_pagination' => true,
			'has_password'                    => false,
		];

		if ( isset( $args['per_page'] ) ) {
			$args['posts_per_page'] = $args['per_page'];
		}

		if ( isset( $args['include'] ) ) {
			$args['post__in'] = $args['include'];
		}

		if ( isset( $args['exclude'] ) ) {
			$args['post__not_in'] = $args['exclude'];
		}

		/**
		 * Filter arguments used to query posts from database
		 *
		 * @hook ep_post_query_db_args
		 * @param  {array} $args Database arguments
		 * @return  {array} New arguments
		 */
		$args = apply_filters( 'ep_index_posts_args', apply_filters( 'ep_post_query_db_args', wp_parse_args( $args, $defaults ) ) );

		if ( isset( $args['post__in'] ) || 0 < $args['offset'] ) {
			// Disable advanced pagination. Not useful if only indexing specific IDs.
			$args['ep_indexing_advanced_pagination'] = false;
		}

		// Enforce the following query args during advanced pagination to ensure things work correctly.
		if ( $args['ep_indexing_advanced_pagination'] ) {
			$args = array_merge(
				$args,
				[
					'suppress_filters' => false,
					'orderby'          => 'ID',
					'order'            => 'DESC',
					'paged'            => 1,
					'offset'           => 0,
					'no_found_rows'    => true,
				]
			);
			add_filter( 'posts_where', array( $this, 'bulk_indexing_filter_posts_where' ), 9999, 2 );

			$query         = new WP_Query( $args );
			$total_objects = $this->get_total_objects_for_query( $args );

			remove_filter( 'posts_where', array( $this, 'bulk_indexing_filter_posts_where' ), 9999, 2 );
		} else {
			$query         = new WP_Query( $args );
			$total_objects = $query->found_posts;
		}

		return [
			'objects'       => $query->posts,
			'total_objects' => $total_objects,
		];
	}

		/**
		 * Manipulate the WHERE clause of the bulk indexing query to paginate by ID in order to avoid performance issues with SQL offset.
		 *
		 * @param string   $where The current $where clause.
		 * @param WP_Query $query WP_Query object.
		 * @return string WHERE clause with our pagination added if needed.
		 */
	public function bulk_indexing_filter_posts_where( $where, $query ) {
		$using_advanced_pagination = $query->get( 'ep_indexing_advanced_pagination', false );

		if ( $using_advanced_pagination ) {
			$requested_upper_limit_id      = $query->get( 'ep_indexing_upper_limit_object_id', PHP_INT_MAX );
			$requested_lower_limit_post_id = $query->get( 'ep_indexing_lower_limit_object_id', 0 );
			$last_processed_id             = $query->get( 'ep_indexing_last_processed_object_id', null );

			// On the first loopthrough we begin with the requested upper limit ID. Afterwards, use the last processed ID to paginate.
			$upper_limit_range_post_id = $requested_upper_limit_id;
			if ( is_numeric( $last_processed_id ) ) {
				$upper_limit_range_post_id = $last_processed_id - 1;
			}

			// Sanitize. Abort if unexpected data at this point.
			if ( ! is_numeric( $upper_limit_range_post_id ) || ! is_numeric( $requested_lower_limit_post_id ) ) {
				return $where;
			}

			$range = [
				'upper_limit' => "{$GLOBALS['wpdb']->posts}.ID <= {$upper_limit_range_post_id}",
				'lower_limit' => "{$GLOBALS['wpdb']->posts}.ID >= {$requested_lower_limit_post_id}",
			];

			// Skip the end range if it's unnecessary.
			$skip_ending_range = 0 === $requested_lower_limit_post_id;
			$where             = $skip_ending_range ? "AND {$range['upper_limit']} {$where}" : "AND {$range['upper_limit']} AND {$range['lower_limit']} {$where}";
		}

		return $where;
	}

	/**
	 * Get SQL_CALC_FOUND_ROWS for a specific query based on it's args.
	 *
	 * @param array $query_args The query args.
	 * @return int The query result's found_posts.
	 */
	protected function get_total_objects_for_query( $query_args ) {
		static $object_counts = [];

		// Reset the pagination-related args for optimal caching.
		$normalized_query_args = array_merge(
			$query_args,
			[
				'offset'                               => 0,
				'paged'                                => 1,
				'posts_per_page'                       => 1,
				'no_found_rows'                        => false,
				'ep_indexing_last_processed_object_id' => null,
			]
		);

		$cache_key = md5( get_current_blog_id() . wp_json_encode( $normalized_query_args ) );

		if ( ! isset( $object_counts[ $cache_key ] ) ) {
			$object_counts[ $cache_key ] = ( new WP_Query( $normalized_query_args ) )->found_posts;
		}

		if ( 0 === $object_counts[ $cache_key ] ) {
			// Do a DB count to make sure the query didn't just die and return 0.
			$db_post_count = $this->get_total_objects_for_query_from_db( $normalized_query_args );

			if ( $db_post_count !== $object_counts[ $cache_key ] ) {
				$object_counts[ $cache_key ] = $db_post_count;
			}
		}

		return $object_counts[ $cache_key ];
	}

	/**
	 * Get total posts from DB for a specific query based on it's args.
	 *
	 * @param array $query_args The query args.
	 * @since 4.0.0
	 * @return int The total posts.
	 */
	protected function get_total_objects_for_query_from_db( $query_args ) {
		$post_count = 0;

		if ( ! isset( $query_args['post_type'] ) || isset( $query_args['ep_indexing_upper_limit_object_id'] )
		|| isset( $query_args['ep_indexing_lower_limit_object_id'] ) ) {
			return $post_count;
		}

		foreach ( $query_args['post_type'] as $post_type ) {
			$post_counts_by_post_status = wp_count_posts( $post_type );
			foreach ( $post_counts_by_post_status as $post_status => $post_status_count ) {
				if ( ! in_array( $post_status, $query_args['post_status'], true ) ) {
					continue;
				}
				$post_count += $post_status_count;
			}
		}

		return $post_count;
	}

	/**
	 * Returns indexable post types for the current site
	 *
	 * @since 0.9
	 * @return mixed|void
	 */
	public function get_indexable_post_types() {
		$post_types = get_post_types( array( 'public' => true ) );

		/**
		 * Remove attachments by default
		 *
		 * @since  3.0
		 */
		unset( $post_types['attachment'] );

		/**
		 * Filter indexable post types
		 *
		 * @hook ep_indexable_post_types
		 * @param  {array} $post_types Indexable post types
		 * @return  {array} New post types
		 */
		return apply_filters( 'ep_indexable_post_types', $post_types );
	}

	/**
	 * Return indexable post_status for the current site
	 *
	 * @since 1.3
	 * @return array
	 */
	public function get_indexable_post_status() {
		/**
		 * Filter indexable post statuses
		 *
		 * @hook ep_indexable_post_status
		 * @param  {array} $post_statuses Indexable post statuses
		 * @return  {array} New post statuses
		 */
		return apply_filters( 'ep_indexable_post_status', array( 'publish' ) );
	}

	/**
	 * Determine required mapping file
	 *
	 * @since 3.6.2
	 * @return string
	 */
	public function get_mapping_name() {
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();

		if ( empty( $es_version ) ) {
			/**
			 * Filter fallback Elasticsearch version
			 *
			 * @hook ep_fallback_elasticsearch_version
			 * @param {string} $version Fall back Elasticsearch version
			 * @return  {string} New version
			 */
			$es_version = apply_filters( 'ep_fallback_elasticsearch_version', '2.0' );
		}

		$mapping_file = '5-2.php';

		if ( ! $es_version || version_compare( $es_version, '5.0' ) < 0 ) {
			$mapping_file = 'pre-5-0.php';
		} elseif ( version_compare( $es_version, '5.0', '>=' ) && version_compare( $es_version, '5.2', '<' ) ) {
			$mapping_file = '5-0.php';
		} elseif ( version_compare( $es_version, '5.2', '>=' ) && version_compare( $es_version, '7.0', '<' ) ) {
			$mapping_file = '5-2.php';
		} elseif ( version_compare( $es_version, '7.0', '>=' ) ) {
			$mapping_file = '7-0.php';
		}

		return apply_filters( 'ep_post_mapping_version', $mapping_file );
	}

	/**
	 * Generate the mapping array
	 *
	 * @since 4.1.0
	 * @return array
	 */
	public function generate_mapping() {
		$mapping_file = $this->get_mapping_name();

		/**
		 * Filter post indexable mapping file
		 *
		 * @hook ep_post_mapping_file
		 * @param {string} $file Path to file
		 * @return  {string} New file path
		 */
		$mapping = require apply_filters( 'ep_post_mapping_file', __DIR__ . '/../../../mappings/post/' . $mapping_file );

		/**
		 * Filter post indexable mapping
		 *
		 * @hook ep_post_mapping
		 * @param {array} $mapping Mapping
		 * @return  {array} New mapping
		 */
		$mapping = apply_filters( 'ep_post_mapping', $mapping );

		delete_transient( 'ep_post_mapping_version' );

		return $mapping;
	}

	/**
	 * Determine version of mapping currently on the post index.
	 *
	 * @since 3.6.2
	 * @return string|WP_Error|false $version
	 */
	public function determine_mapping_version() {
		$version = get_transient( 'ep_post_mapping_version' );

		if ( empty( $version ) ) {
			$index   = $this->get_index_name();
			$mapping = Elasticsearch::factory()->get_mapping( $index );

			if ( empty( $mapping ) ) {
				return new \WP_Error( 'ep_failed_mapping_version', esc_html__( 'Error while fetching the mapping version.', 'elasticpress' ) );
			}

			if ( ! isset( $mapping[ $index ] ) ) {
				return false;
			}

			$version = $this->determine_mapping_version_based_on_existing( $mapping, $index );

			set_transient(
				'ep_post_mapping_version',
				$version,
				/**
				 * Filter the post mapping version cache expiration.
				 *
				 * @hook ep_post_mapping_version_cache_expiration
				 * @since 3.6.5
				 * @param  {int} $version Time in seconds for the transient expiration
				 * @return {int} New time
				 */
				apply_filters( 'ep_post_mapping_version_cache_expiration', DAY_IN_SECONDS )
			);
		}

		/**
		 * Filter the mapping version for posts.
		 *
		 * @hook ep_post_mapping_version_determined
		 * @since 3.6.2
		 * @param {string} $version Determined version string
		 * @return  {string} New version string
		 */
		return apply_filters( 'ep_post_mapping_version_determined', $version );
	}

	/**
	 * Prepare a post for syncing
	 *
	 * @param int $post_id Post ID.
	 * @since 0.9.1
	 * @return bool|array
	 */
	public function prepare_document( $post_id ) {
		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return false;
		}

		$user = get_userdata( $post->post_author );

		if ( $user instanceof WP_User ) {
			$user_data = array(
				'raw'          => $user->user_login,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'id'           => $user->ID,
			);
		} else {
			$user_data = array(
				'raw'          => '',
				'login'        => '',
				'display_name' => '',
				'id'           => '',
			);
		}

		$post_date         = $post->post_date;
		$post_date_gmt     = $post->post_date_gmt;
		$post_modified     = $post->post_modified;
		$post_modified_gmt = $post->post_modified_gmt;
		$comment_count     = absint( $post->comment_count );
		$comment_status    = $post->comment_status;
		$ping_status       = $post->ping_status;
		$menu_order        = absint( $post->menu_order );

		/**
		 * Filter to ignore invalid dates
		 *
		 * @hook ep_ignore_invalid_dates
		 * @param  {bool} $ignore True to ignore
		 * @param {int} $post_id Post ID
		 * @param  {WP_Post} $post Post object
		 * @return  {bool} New ignore value
		 */
		if ( apply_filters( 'ep_ignore_invalid_dates', true, $post_id, $post ) ) {
			if ( ! strtotime( $post_date ) || '0000-00-00 00:00:00' === $post_date ) {
				$post_date = null;
			}

			if ( ! strtotime( $post_date_gmt ) || '0000-00-00 00:00:00' === $post_date_gmt ) {
				$post_date_gmt = null;
			}

			if ( ! strtotime( $post_modified ) || '0000-00-00 00:00:00' === $post_modified ) {
				$post_modified = null;
			}

			if ( ! strtotime( $post_modified_gmt ) || '0000-00-00 00:00:00' === $post_modified_gmt ) {
				$post_modified_gmt = null;
			}
		}

		// To prevent infinite loop, we don't queue when updated_postmeta.
		remove_action( 'updated_postmeta', [ $this->sync_manager, 'action_queue_meta_sync' ], 10 );

		/**
		 * Filter to allow indexing of filtered post content
		 *
		 * @hook ep_allow_post_content_filtered_index
		 * @param  {bool} $ignore True to allow
		 * @return  {bool} New value
		 */
		$post_content_filtered_allowed = apply_filters( 'ep_allow_post_content_filtered_index', true );

		$post_args = array(
			'post_id'               => $post_id,
			'ID'                    => $post_id,
			'post_author'           => $user_data,
			'post_date'             => $post_date,
			'post_date_gmt'         => $post_date_gmt,
			'post_title'            => $post->post_title,
			'post_excerpt'          => $post->post_excerpt,
			'post_content_filtered' => $post_content_filtered_allowed ? apply_filters( 'the_content', $post->post_content ) : '',
			'post_content'          => $post->post_content,
			'post_status'           => $post->post_status,
			'post_name'             => $post->post_name,
			'post_modified'         => $post_modified,
			'post_modified_gmt'     => $post_modified_gmt,
			'post_parent'           => $post->post_parent,
			'post_type'             => $post->post_type,
			'post_mime_type'        => $post->post_mime_type,
			'permalink'             => get_permalink( $post_id ),
			'terms'                 => $this->prepare_terms( $post ),
			'meta'                  => $this->prepare_meta_types( $this->prepare_meta( $post ) ), // post_meta removed in 2.4.
			'date_terms'            => $this->prepare_date_terms( $post_date ),
			'comment_count'         => $comment_count,
			'comment_status'        => $comment_status,
			'ping_status'           => $ping_status,
			'menu_order'            => $menu_order,
			'guid'                  => $post->guid,
			'thumbnail'             => $this->prepare_thumbnail( $post ),
		);

		/**
		 * Filter sync arguments for a post. For backwards compatibility.
		 *
		 * @hook ep_post_sync_args
		 * @param  {array} $post_args Post arguments
		 * @param  {int} $post_id Post ID
		 * @return  {array} New arguments
		 */
		$post_args = apply_filters( 'ep_post_sync_args', $post_args, $post_id );

		/**
		 * Filter sync arguments for a post after meta preparation.
		 *
		 * @hook ep_post_sync_args_post_prepare_meta
		 * @param  {array} $post_args Post arguments
		 * @param  {int} $post_id Post ID
		 * @return  {array} New arguments
		 */
		$post_args = apply_filters( 'ep_post_sync_args_post_prepare_meta', $post_args, $post_id );

		// Turn back on updated_postmeta hook
		add_action( 'updated_postmeta', [ $this->sync_manager, 'action_queue_meta_sync' ], 10, 4 );

		return $post_args;
	}

	/**
	 * Prepare thumbnail to send to ES.
	 *
	 * @param WP_Post $post Post object.
	 * @return array|null Thumbnail data.
	 */
	public function prepare_thumbnail( $post ) {
		$attachment_id = get_post_thumbnail_id( $post );

		if ( ! $attachment_id ) {
			return null;
		}

		/**
		 * Filters the image size to use when indexing the post thumbnail.
		 *
		 * Defaults to the `woocommerce_thumbnail` size if WooCommerce is in
		 * use. Otherwise the `thumbnail` size is used.
		 *
		 * @hook ep_thumbnail_image_size
		 * @since 4.0.0
		 * @param {string|int[]} $image_size Image size. Can be any registered
		 *                                 image size name, or an array of
		 *                                 width and height values in pixels
		 *                                 (in that order).
		 * @param {WP_Post} $post Post being indexed.
		 * @return {array} Image size to pass to wp_get_attachment_image_src().
		 */
		$image_size = apply_filters(
			'ep_post_thumbnail_image_size',
			function_exists( 'WC' ) ? 'woocommerce_thumbnail' : 'thumbnail',
			$post
		);

		$image_src = wp_get_attachment_image_src( $attachment_id, $image_size );
		$image_alt = trim( wp_strip_all_tags( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );

		if ( ! $image_src ) {
			return null;
		}

		return [
			'ID'     => $attachment_id,
			'src'    => $image_src[0],
			'width'  => $image_src[1],
			'height' => $image_src[2],
			'alt'    => $image_alt,
		];
	}

	/**
	 * Prepare date terms to send to ES.
	 *
	 * @param string $date_to_prepare Post date
	 * @since 0.1.4
	 * @return array
	 */
	public function prepare_date_terms( $date_to_prepare ) {
		$terms_to_prepare = [
			'year'          => 'Y',
			'month'         => 'm',
			'week'          => 'W',
			'dayofyear'     => 'z',
			'day'           => 'd',
			'dayofweek'     => 'w',
			'dayofweek_iso' => 'N',
			'hour'          => 'H',
			'minute'        => 'i',
			'second'        => 's',
			'm'             => 'Ym', // yearmonth
		];

		// Combine all the date term formats and perform one single call to date_i18n() for performance.
		$date_format    = implode( '||', array_values( $terms_to_prepare ) );
		$combined_dates = explode( '||', date_i18n( $date_format, strtotime( $date_to_prepare ) ) );

		// Then split up the results for individual indexing.
		$date_terms = [];
		foreach ( $terms_to_prepare as $term_name => $date_format ) {
			$index_in_combined_format = array_search( $term_name, array_keys( $terms_to_prepare ), true );
			$date_terms[ $term_name ] = (int) $combined_dates[ $index_in_combined_format ];
		}

		return $date_terms;
	}

	/**
	 * Get an array of taxonomies that are indexable for the given post
	 *
	 * @since 4.0.0
	 * @param WP_Post $post Post object
	 * @return array Array of WP_Taxonomy objects that should be indexed
	 */
	public function get_indexable_post_taxonomies( $post ) {
		$taxonomies          = get_object_taxonomies( $post->post_type, 'objects' );
		$selected_taxonomies = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public || $taxonomy->publicly_queryable ) {
				$selected_taxonomies[] = $taxonomy;
			}
		}

		/**
		 * Filter taxonomies to be synced with post
		 *
		 * @hook ep_sync_taxonomies
		 * @param  {array} $selected_taxonomies Selected taxonomies
		 * @param  {WP_Post} Post object
		 * @return  {array} New taxonomies
		 */
		$selected_taxonomies = (array) apply_filters( 'ep_sync_taxonomies', $selected_taxonomies, $post );

		// Important we validate here to ensure there are no invalid taxonomy values returned from the filter, as just one would cause wp_get_object_terms() to fail.
		$validated_taxonomies = [];
		foreach ( $selected_taxonomies as $selected_taxonomy ) {
			// If we get a taxonomy name, we need to convert it to taxonomy object
			if ( ! is_object( $selected_taxonomy ) && taxonomy_exists( (string) $selected_taxonomy ) ) {
				$selected_taxonomy = get_taxonomy( $selected_taxonomy );
			}

			// We check if the $taxonomy object has a valid name property. Backward compatibility since WP_Taxonomy introduced in WP 4.7
			if ( ! is_a( $selected_taxonomy, '\WP_Taxonomy' ) || ! property_exists( $selected_taxonomy, 'name' ) || ! taxonomy_exists( $selected_taxonomy->name ) ) {
				continue;
			}

			$validated_taxonomies[] = $selected_taxonomy;
		}

		return $validated_taxonomies;
	}

	/**
	 * Prepare terms to send to ES.
	 *
	 * @param WP_Post $post Post object
	 * @since 0.1.0
	 * @return array
	 */
	private function prepare_terms( $post ) {
		$selected_taxonomies = $this->get_indexable_post_taxonomies( $post );

		if ( empty( $selected_taxonomies ) ) {
			return [];
		}

		$terms = [];

		/**
		 * Filter to allow child terms to be indexed
		 *
		 * @hook ep_sync_terms_allow_hierarchy
		 * @param  {bool} $allow True means allow
		 * @return  {bool} New value
		 */
		$allow_hierarchy = apply_filters( 'ep_sync_terms_allow_hierarchy', true );

		foreach ( $selected_taxonomies as $taxonomy ) {
			$object_terms = get_the_terms( $post->ID, $taxonomy->name );

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			$terms_dic = [];

			foreach ( $object_terms as $term ) {
				if ( ! isset( $terms_dic[ $term->term_id ] ) ) {
					$terms_dic[ $term->term_id ] = array(
						'term_id'          => $term->term_id,
						'slug'             => $term->slug,
						'name'             => $term->name,
						'parent'           => $term->parent,
						'term_taxonomy_id' => $term->term_taxonomy_id,
						'term_order'       => (int) $this->get_term_order( $term->term_taxonomy_id, $post->ID ),
					);

					$terms_dic[ $term->term_id ]['facet'] = wp_json_encode( $terms_dic[ $term->term_id ] );

					if ( $allow_hierarchy ) {
						$terms_dic = $this->get_parent_terms( $terms_dic, $term, $taxonomy->name, $post->ID );
					}
				}
			}
			$terms[ $taxonomy->name ] = array_values( $terms_dic );
		}

		return $terms;
	}

	/**
	 * Recursively get all the ancestor terms of the given term
	 *
	 * @param array   $terms     Terms array
	 * @param WP_Term $term      Current term
	 * @param string  $tax_name  Taxonomy
	 * @param int     $object_id Post ID
	 *
	 * @return array
	 */
	private function get_parent_terms( $terms, $term, $tax_name, $object_id ) {
		$parent_term = get_term( $term->parent, $tax_name );
		if ( ! $parent_term || is_wp_error( $parent_term ) ) {
			return $terms;
		}
		if ( ! isset( $terms[ $parent_term->term_id ] ) ) {
			$terms[ $parent_term->term_id ] = array(
				'term_id'          => $parent_term->term_id,
				'slug'             => $parent_term->slug,
				'name'             => $parent_term->name,
				'parent'           => $parent_term->parent,
				'term_taxonomy_id' => $parent_term->term_taxonomy_id,
				'term_order'       => $this->get_term_order( $parent_term->term_taxonomy_id, $object_id ),
			);

			$terms[ $parent_term->term_id ]['facet'] = wp_json_encode( $terms[ $parent_term->term_id ] );

		}
		return $this->get_parent_terms( $terms, $parent_term, $tax_name, $object_id );
	}

	/**
	 * Retreives term order for the object/term_taxonomy_id combination
	 *
	 * @param int $term_taxonomy_id Term Taxonomy ID
	 * @param int $object_id        Post ID
	 *
	 * @return int Term Order
	 */
	protected function get_term_order( $term_taxonomy_id, $object_id ) {
		global $wpdb;

		$cache_key   = "{$object_id}_term_order";
		$term_orders = wp_cache_get( $cache_key );

		if ( false === $term_orders ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term_taxonomy_id, term_order from $wpdb->term_relationships where object_id=%d;",
					$object_id
				),
				ARRAY_A
			);

			$term_orders = [];

			foreach ( $results as $result ) {
				$term_orders[ $result['term_taxonomy_id'] ] = $result['term_order'];
			}

			wp_cache_set( $cache_key, $term_orders );
		}

		return isset( $term_orders[ $term_taxonomy_id ] ) ? (int) $term_orders[ $term_taxonomy_id ] : 0;

	}

	/**
	 * Checks if meta key is allowed
	 *
	 * @param string  $meta_key meta key to check
	 * @param WP_Post $post Post object
	 * @since 4.3.0
	 * @return boolean
	 */
	public function is_meta_allowed( $meta_key, $post ) {
		$test_metas = [
			$meta_key => true,
		];

		$filtered_test_metas = $this->filter_allowed_metas( $test_metas, $post );

		return array_key_exists( $meta_key, $filtered_test_metas );
	}

	/**
	 * Filter post meta to only the allowed ones to be send to ES
	 *
	 * @param array   $metas Key => value pairs of post meta
	 * @param WP_Post $post Post object
	 * @since 4.3.0
	 * @return array
	 */
	public function filter_allowed_metas( $metas, $post ) {
		$filtered_metas = [];

		/**
		 * Filter indexable protected meta keys for posts
		 *
		 * @hook ep_prepare_meta_allowed_protected_keys
		 * @param  {array} $keys Allowed protected keys
		 * @param  {WP_Post} $post Post object
		 * @since  1.7
		 * @return  {array} New keys
		 */
		$allowed_protected_keys = apply_filters( 'ep_prepare_meta_allowed_protected_keys', [], $post );

		/**
		 * Filter public keys to exclude from indexed post
		 *
		 * @hook ep_prepare_meta_excluded_public_keys
		 * @param  {array} $keys Excluded protected keys
		 * @param  {WP_Post} $post Post object
		 * @since  1.7
		 * @return  {array} New keys
		 */
		$excluded_public_keys = apply_filters( 'ep_prepare_meta_excluded_public_keys', [], $post );

		foreach ( $metas as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys, true ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys, true ) ) {
					$allow_index = true;
				}
			}

			/**
			 * Filter force whitelisting a meta key
			 *
			 * @hook ep_prepare_meta_whitelist_key
			 * @param  {bool} $whitelist True to whitelist key
			 * @param  {string} $key Meta key
			 * @param  {WP_Post} $post Post object
			 * @return  {bool} New whitelist value
			 */
			if ( true === $allow_index || apply_filters( 'ep_prepare_meta_whitelist_key', false, $key, $post ) ) {
				$filtered_metas[ $key ] = $value;
			}
		}
		return $filtered_metas;
	}

	/**
	 * Prepare post meta to send to ES
	 *
	 * @param WP_Post $post Post object
	 * @since 0.1.0
	 * @return array
	 */
	public function prepare_meta( $post ) {
		/**
		 * Filter pre-prepare meta for a post
		 *
		 * @hook ep_prepare_meta_data
		 * @param  {array} $meta Meta data
		 * @param  {WP_Post} $post Post object
		 * @return  {array} New meta
		 */
		$meta = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post );

		if ( empty( $meta ) ) {
			/**
			 * Filter final list of prepared meta.
			 *
			 * @hook ep_prepared_post_meta
			 * @param  {array} $prepared_meta Prepared meta
			 * @param  {WP_Post} $post Post object
			 * @since  3.4
			 * @return  {array} Prepared meta
			 */
			return apply_filters( 'ep_prepared_post_meta', [], $post );
		}

		$filtered_metas = $this->filter_allowed_metas( $meta, $post );
		$prepared_meta  = [];

		foreach ( $filtered_metas as $key => $value ) {
			$prepared_meta[ $key ] = maybe_unserialize( $value );
		}

		/**
		 * Filter final list of prepared meta.
		 *
		 * @hook ep_prepared_post_meta
		 * @param  {array} $prepared_meta Prepared meta
		 * @param  {WP_Post} $post Post object
		 * @since  3.4
		 * @return  {array} Prepared meta
		 */
		return apply_filters( 'ep_prepared_post_meta', $prepared_meta, $post );

	}

	/**
	 * Format WP query args for ES
	 *
	 * @param  array    $args     WP_Query arguments.
	 * @param  WP_Query $wp_query WP_Query object
	 * @since 0.9.0
	 * @return array
	 */
	public function format_args( $args, $wp_query ) {
		if ( ! empty( $args['posts_per_page'] ) ) {
			$posts_per_page = (int) $args['posts_per_page'];

			// ES have a maximum size allowed so we have to convert "-1" to a maximum size.
			if ( -1 === $posts_per_page ) {
				/**
				 * Set the maximum results window size.
				 *
				 * The request will return a HTTP 500 Internal Error if the size of the
				 * request is larger than the [index.max_result_window] parameter in ES.
				 * See the scroll api for a more efficient way to request large data sets.
				 *
				 * @return int The max results window size.
				 *
				 * @since 2.3.0
				 */

				/**
				 * Filter max result size if set to -1
				 *
				 * @hook ep_max_results_window
				 * @param  {int} Max result window
				 * @return {int} New window
				 */
				$posts_per_page = apply_filters( 'ep_max_results_window', 10000 );
			}
		} else {
			$posts_per_page = (int) get_option( 'posts_per_page' );
		}

		$formatted_args = array(
			'from' => 0,
			'size' => $posts_per_page,
		);

		/**
		 * Order and Orderby arguments
		 *
		 * Used for how Elasticsearch will sort results
		 *
		 * @since 1.1
		 */

		// Set sort order, default is 'desc'.
		if ( ! empty( $args['order'] ) ) {
			$order = $this->parse_order( $args['order'] );
		} else {
			$order = 'desc';
		}

		// Default sort for non-searches to date.
		if ( empty( $args['orderby'] ) && ( ! isset( $args['s'] ) || '' === $args['s'] ) ) {
			/**
			 * Filter default post query order by
			 *
			 * @hook ep_set_default_sort
			 * @param  {string} $sort Default sort
			 * @param  {string $order Order direction
			 * @return  {string} New default
			 */
			$args['orderby'] = apply_filters( 'ep_set_default_sort', 'date', $order );
		}

		// Set sort type.
		if ( ! empty( $args['orderby'] ) ) {
			$formatted_args['sort'] = $this->parse_orderby( $args['orderby'], $order, $args );
		} else {
			// Default sort is to use the score (based on relevance).
			$default_sort = array(
				array(
					'_score' => array(
						'order' => $order,
					),
				),
			);

			/**
			 * Filter the ES query order (`sort` clause)
			 *
			 * This filter is used in searches if `orderby` is not set in the WP_Query args.
			 * The default value is:
			 *
			 *    $default_sort = array(
			 *        array(
			 *            '_score' => array(
			 *                'order' => $order,
			 *            ),
			 *        ),
			 *    );
			 *
			 * @hook ep_set_sort
			 * @since 3.6.3
			 * @param  {array}  $sort  Default sort.
			 * @param  {string} $order Order direction
			 * @return {array}  New default
			 */
			$default_sort = apply_filters( 'ep_set_sort', $default_sort, $order );

			$formatted_args['sort'] = $default_sort;
		}

		$filter      = array(
			'bool' => array(
				'must' => [],
			),
		);
		$use_filters = false;

		// Sanitize array query args. Elasticsearch will error if a terms query contains empty items like an
		// empty string.
		$keys_to_sanitize = [
			'author__in',
			'author__not_in',
			'category__and',
			'category__in',
			'category__not_in',
			'tag__and',
			'tag__in',
			'tag__not_in',
			'tag_slug__and',
			'tag_slug__in',
			'post_parent__in',
			'post_parent__not_in',
			'post__in',
			'post__not_in',
			'post_name__in',
		];
		foreach ( $keys_to_sanitize as $key ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}
			$args[ $key ] = array_filter( (array) $args[ $key ] );
		}

		/**
		 * Tax Query support
		 *
		 * Support for the tax_query argument of WP_Query. Currently only provides support for the 'AND' relation
		 * between taxonomies. Field only supports slug, term_id, and name defaulting to term_id.
		 *
		 * @use field = slug
		 *      terms array
		 * @since 0.9.1
		 */
		if ( ! empty( $wp_query->tax_query ) && ! empty( $wp_query->tax_query->queries ) ) {
			$args['tax_query'] = $wp_query->tax_query->queries;
		}

		if ( ! empty( $args['tax_query'] ) ) {
			// Main tax_query array for ES.
			$es_tax_query = [];

			$tax_queries = $this->parse_tax_query( $args['tax_query'] );

			if ( ! empty( $tax_queries['tax_filter'] ) ) {
				$relation = 'must';

				if ( ! empty( $args['tax_query']['relation'] ) && 'or' === strtolower( $args['tax_query']['relation'] ) ) {
					$relation = 'should';
				}

				$es_tax_query[ $relation ] = $tax_queries['tax_filter'];
			}

			if ( ! empty( $tax_queries['tax_must_not_filter'] ) ) {
				$es_tax_query['must_not'] = $tax_queries['tax_must_not_filter'];
			}

			if ( ! empty( $es_tax_query ) ) {
				$filter['bool']['must'][]['bool'] = $es_tax_query;
			}

			$use_filters = true;
		}

		/**
		 * 'post_parent' arg support.
		 *
		 * @since 2.0
		 */
		if ( isset( $args['post_parent'] ) && '' !== $args['post_parent'] && 'any' !== strtolower( $args['post_parent'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'term' => array(
					'post_parent' => $args['post_parent'],
				),
			);

			$use_filters = true;
		}

		/**
		 * 'post__in' arg support.
		 *
		 * @since x.x
		 */
		if ( ! empty( $args['post__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'terms' => array(
					'post_id' => array_values( (array) $args['post__in'] ),
				),
			);

			$use_filters = true;
		}

		/**
		 * 'post_name__in' arg support.
		 *
		 * @since 3.6.0
		 */
		if ( ! empty( $args['post_name__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'terms' => array(
					'post_name.raw' => array_values( (array) $args['post_name__in'] ),
				),
			);

			$use_filters = true;
		}

		/**
		 * 'post__not_in' arg support.
		 *
		 * @since x.x
		 */
		if ( ! empty( $args['post__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = array(
				'terms' => array(
					'post_id' => (array) $args['post__not_in'],
				),
			);

			$use_filters = true;
		}

		/**
		 * 'category__not_in' arg support.
		 *
		 * @since 3.6.0
		 */
		if ( ! empty( $args['category__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = array(
				'terms' => array(
					'terms.category.term_id' => array_values( (array) $args['category__not_in'] ),
				),
			);

			$use_filters = true;
		}

		/**
		 * 'tag__not_in' arg support.
		 *
		 * @since 3.6.0
		 */
		if ( ! empty( $args['tag__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = array(
				'terms' => array(
					'terms.post_tag.term_id' => array_values( (array) $args['tag__not_in'] ),
				),
			);

			$use_filters = true;
		}

		/**
		 * Author query support
		 *
		 * @since 1.0
		 */
		if ( ! empty( $args['author'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_author.id' => $args['author'],
				),
			);

			$use_filters = true;
		} elseif ( ! empty( $args['author_name'] ) ) {
			// Since this was set to use the display name initially, there might be some code that used this feature.
			// Let's ensure that any query vars coming in using author_name are in fact slugs.
			// This was changed back in ticket #1622 to use the display name, so we removed the sanitize_user() call.
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_author.display_name' => $args['author_name'],
				),
			);

			$use_filters = true;
		} elseif ( ! empty( $args['author__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'terms' => array(
					'post_author.id' => array_values( (array) $args['author__in'] ),
				),
			);

			$use_filters = true;
		} elseif ( ! empty( $args['author__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = array(
				'terms' => array(
					'post_author.id' => array_values( (array) $args['author__not_in'] ),
				),
			);

			$use_filters = true;
		}

		/**
		 * Add support for post_mime_type
		 *
		 * If we have array, it will be fool text search filter.
		 * If we have string(like filter images in media screen), we will have mime type "image" so need to check it as
		 * regexp filter.
		 *
		 * @since 2.3
		 */
		if ( ! empty( $args['post_mime_type'] ) ) {
			if ( is_array( $args['post_mime_type'] ) ) {

				$args_post_mime_type = [];

				foreach ( $args['post_mime_type'] as $mime_type ) {
					/**
					 * check if matches the MIME type pattern: type/subtype and
					 * leave an empty string as posts, pages and CPTs don't have a MIME type
					 */
					if ( preg_match( '/^[-._a-z0-9]+\/[-._a-z0-9]+$/i', $mime_type ) || empty( $mime_type ) ) {
						$args_post_mime_type[] = $mime_type;
					} else {
						$filtered_mime_type_by_type = wp_match_mime_types( $mime_type, wp_get_mime_types() );

						$args_post_mime_type = array_merge( $args_post_mime_type, $filtered_mime_type_by_type[ $mime_type ] );
					}
				}

				$filter['bool']['must'][] = array(
					'terms' => array(
						'post_mime_type' => $args_post_mime_type,
					),
				);

				$use_filters = true;
			} elseif ( is_string( $args['post_mime_type'] ) ) {
				$filter['bool']['must'][] = array(
					'regexp' => array(
						'post_mime_type' => $args['post_mime_type'] . '.*',
					),
				);

				$use_filters = true;
			}
		}

		/**
		 * Simple date params support
		 *
		 * @since 1.3
		 */
		$date_filter = DateQuery::simple_es_date_filter( $args );

		if ( ! empty( $date_filter ) ) {
			$filter['bool']['must'][] = $date_filter;
			$use_filters              = true;
		}

		/**
		 * 'date_query' arg support.
		 */
		if ( ! empty( $args['date_query'] ) ) {

			$date_query = new DateQuery( $args['date_query'] );

			$date_filter = $date_query->get_es_filter();

			if ( array_key_exists( 'and', $date_filter ) ) {
				$filter['bool']['must'][] = $date_filter['and'];
				$use_filters              = true;
			}
		}

		$meta_queries = [];

		/**
		 * Support `meta_key`, `meta_value`, `meta_value_num`, and `meta_compare` query args
		 */
		if ( ! empty( $args['meta_key'] ) ) {
			$meta_query_array = [
				'key' => $args['meta_key'],
			];

			if ( isset( $args['meta_value'] ) && '' !== $args['meta_value'] ) {
				$meta_query_array['value'] = $args['meta_value'];
			} elseif ( isset( $args['meta_value_num'] ) && '' !== $args['meta_value_num'] ) {
				$meta_query_array['value'] = $args['meta_value_num'];
			}

			if ( isset( $args['meta_compare'] ) ) {
				$meta_query_array['compare'] = $args['meta_compare'];
			}

			$meta_queries[] = $meta_query_array;
		}

		/**
		 * Todo: Support meta_type
		 */

		/**
		 * 'meta_query' arg support.
		 *
		 * Relation supports 'AND' and 'OR'. 'AND' is the default. For each individual query, the
		 * following 'compare' values are supported: =, !=, EXISTS, NOT EXISTS. '=' is the default.
		 *
		 * @since 1.3
		 */
		if ( ! empty( $args['meta_query'] ) ) {
			$meta_queries = array_merge( $meta_queries, $args['meta_query'] );
		}

		if ( ! empty( $meta_queries ) ) {

			$relation = 'must';
			if ( ! empty( $args['meta_query'] ) && ! empty( $args['meta_query']['relation'] ) && 'or' === strtolower( $args['meta_query']['relation'] ) ) {
				$relation = 'should';
			}

			// get meta query filter
			$meta_filter = $this->build_meta_query( $meta_queries );

			if ( ! empty( $meta_filter ) ) {
				$filter['bool']['must'][] = $meta_filter;

				$use_filters = true;
			}
		}

		/**
		 * Allow for search field specification
		 *
		 * @since 1.0
		 */
		if ( ! empty( $args['search_fields'] ) ) {
			$search_field_args = $args['search_fields'];
			$search_fields     = [];

			if ( ! empty( $search_field_args['taxonomies'] ) ) {
				$taxes = (array) $search_field_args['taxonomies'];

				foreach ( $taxes as $tax ) {
					$search_fields[] = 'terms.' . $tax . '.name';
				}

				unset( $search_field_args['taxonomies'] );
			}

			if ( ! empty( $search_field_args['meta'] ) ) {
				$metas = (array) $search_field_args['meta'];

				foreach ( $metas as $meta ) {
					$search_fields[] = 'meta.' . $meta . '.value';
				}

				unset( $search_field_args['meta'] );
			}

			if ( in_array( 'author_name', $search_field_args, true ) ) {
				$search_fields[] = 'post_author.login';

				$author_name_index = array_search( 'author_name', $search_field_args, true );
				unset( $search_field_args[ $author_name_index ] );
			}

			$search_fields = array_merge( $search_field_args, $search_fields );
		} else {
			$search_fields = array(
				'post_title',
				'post_excerpt',
				'post_content',
			);
		}

		/**
		 * Filter default post search fields
		 *
		 * If you are using the weighting engine, this filter should not be used.
		 * Instead, you should use the ep_weighting_configuration_for_search filter.
		 *
		 * @hook ep_search_fields
		 * @param  {array} $search_fields Default search fields
		 * @param  {array} $args WP Query arguments
		 * @return  {array} New defaults
		 */
		$search_fields = apply_filters( 'ep_search_fields', $search_fields, $args );

		$search_text = ( ! empty( $args['s'] ) ) ? $args['s'] : '';

		/**
		 * We are using ep_integrate instead of ep_match_all. ep_match_all will be
		 * supported for legacy code but may be deprecated and removed eventually.
		 *
		 * @since 1.3
		 */

		if ( ! empty( $search_text ) ) {
			add_filter( 'ep_post_formatted_args_query', [ $this, 'adjust_query_fuzziness' ], 100, 4 );

			$search_algorithm        = $this->get_search_algorithm( $search_text, $search_fields, $args );
			$formatted_args['query'] = $search_algorithm->get_query( 'post', $search_text, $search_fields, $args );
		} elseif ( ! empty( $args['ep_match_all'] ) || ! empty( $args['ep_integrate'] ) ) {
			$formatted_args['query']['match_all'] = array(
				'boost' => 1,
			);
		}

		/**
		 * Order by 'rand' support
		 *
		 * Ref: https://github.com/elastic/elasticsearch/issues/1170
		 */
		if ( ! empty( $args['orderby'] ) ) {
			$orderbys = $this->get_orderby_array( $args['orderby'] );
			if ( in_array( 'rand', $orderbys, true ) ) {
				$formatted_args_query                                      = $formatted_args['query'];
				$formatted_args['query']                                   = [];
				$formatted_args['query']['function_score']['query']        = $formatted_args_query;
				$formatted_args['query']['function_score']['random_score'] = (object) [];
			}
		}

		/**
		 * Sticky posts support
		 */

		// Check first if there's sticky posts and show them only in the front page
		$sticky_posts = get_option( 'sticky_posts' );
		$sticky_posts = ( is_array( $sticky_posts ) && empty( $sticky_posts ) ) ? false : $sticky_posts;

		/**
		 * Filter whether to enable sticky posts for this request
		 *
		 * @hook ep_enable_sticky_posts
		 *
		 * @param {bool}  $allow          Allow sticky posts for this request
		 * @param {array} $args           Query variables
		 * @param {array} $formatted_args EP formatted args
		 *
		 * @return  {bool} $allow
		 */
		$enable_sticky_posts = apply_filters( 'ep_enable_sticky_posts', is_home(), $args, $formatted_args );

		if ( false !== $sticky_posts
			&& $enable_sticky_posts
			&& empty( $args['s'] )
			&& in_array( $args['ignore_sticky_posts'], array( 'false', 0, false ), true ) ) {
			$new_sort = [
				[
					'_score' => [
						'order' => 'desc',
					],
				],
			];

			$formatted_args['sort'] = array_merge( $new_sort, $formatted_args['sort'] );

			$formatted_args_query                                   = $formatted_args['query'];
			$formatted_args['query']                                = array();
			$formatted_args['query']['function_score']['query']     = $formatted_args_query;
			$formatted_args['query']['function_score']['functions'] = array(
				// add extra weight to sticky posts to show them on top
					(object) array(
						'filter' => array(
							'terms' => array( '_id' => $sticky_posts ),
						),
						'weight' => 20,
					),
			);
		}

		/**
		 * If not set default to post. If search and not set, default to "any".
		 */
		if ( ! empty( $args['post_type'] ) ) {
			// should NEVER be "any" but just in case
			if ( 'any' !== $args['post_type'] ) {
				$post_types     = (array) $args['post_type'];
				$terms_map_name = 'terms';

				$filter['bool']['must'][] = array(
					$terms_map_name => array(
						'post_type.raw' => array_values( $post_types ),
					),
				);

				$use_filters = true;
			}
		} elseif ( empty( $args['s'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_type.raw' => 'post',
				),
			);

			$use_filters = true;
		}

		/**
		 * Like WP_Query in search context, if no post_status is specified we default to "any". To
		 * be safe you should ALWAYS specify the post_status parameter UNLIKE with WP_Query.
		 *
		 * @since 2.1
		 */
		if ( ! empty( $args['post_status'] ) ) {
			// should NEVER be "any" but just in case
			if ( 'any' !== $args['post_status'] ) {
				$post_status    = (array) ( is_string( $args['post_status'] ) ? explode( ',', $args['post_status'] ) : $args['post_status'] );
				$post_status    = array_map( 'trim', $post_status );
				$terms_map_name = 'terms';
				if ( count( $post_status ) < 2 ) {
					$terms_map_name = 'term';
					$post_status    = $post_status[0];
				}

				$filter['bool']['must'][] = array(
					$terms_map_name => array(
						'post_status' => $post_status,
					),
				);

				$use_filters = true;
			}
		} else {
			$statuses = get_post_stati( array( 'public' => true ) );

			if ( is_admin() ) {
				/**
				 * In the admin we will add protected and private post statuses to the default query
				 * per WP default behavior.
				 */
				$statuses = array_merge(
					$statuses,
					get_post_stati(
						array(
							'protected'              => true,
							'show_in_admin_all_list' => true,
						)
					)
				);

				if ( is_user_logged_in() ) {
					$statuses = array_merge( $statuses, get_post_stati( array( 'private' => true ) ) );
				}
			}

			$statuses = array_values( $statuses );

			$post_status_filter_type = 'terms';

			$filter['bool']['must'][] = array(
				$post_status_filter_type => array(
					'post_status' => $statuses,
				),
			);

			$use_filters = true;
		}

		if ( isset( $args['offset'] ) ) {
			$formatted_args['from'] = (int) $args['offset'];
		}

		if ( isset( $args['paged'] ) && $args['paged'] > 1 ) {
			$formatted_args['from'] = $args['posts_per_page'] * ( $args['paged'] - 1 );
		}

		/**
		 * Fix negative offset. This happens, for example, on hierarchical post types.
		 *
		 * Ref: https://github.com/10up/ElasticPress/issues/2480
		 */
		if ( $formatted_args['from'] < 0 ) {
			$formatted_args['from'] = 0;
		}

		if ( $use_filters ) {
			$formatted_args['post_filter'] = $filter;
		}

		/**
		 * Support fields.
		 */
		if ( isset( $args['fields'] ) ) {
			switch ( $args['fields'] ) {
				case 'ids':
					$formatted_args['_source'] = array(
						'includes' => array(
							'post_id',
						),
					);
					break;

				case 'id=>parent':
					$formatted_args['_source'] = array(
						'includes' => array(
							'post_id',
							'post_parent',
						),
					);
					break;
			}
		}

		/**
		 * Aggregations
		 */
		if ( ! empty( $args['aggs'] ) && is_array( $args['aggs'] ) ) {
			// Check if the array indexes are all numeric.
			$agg_keys          = array_keys( $args['aggs'] );
			$agg_num_keys      = array_filter( $agg_keys, 'is_int' );
			$has_only_num_keys = count( $agg_num_keys ) === count( $args['aggs'] );

			if ( $has_only_num_keys ) {
				foreach ( $args['aggs'] as $agg ) {
					$formatted_args = $this->apply_aggregations( $formatted_args, $agg, $use_filters, $filter );
				}
			} else {
				// Single aggregation.
				$formatted_args = $this->apply_aggregations( $formatted_args, $args['aggs'], $use_filters, $filter );
			}
		}

		/**
		 * Filter formatted Elasticsearch [ost ]query (entire query)
		 *
		 * @hook ep_formatted_args
		 * @param {array} $formatted_args Formatted Elasticsearch query
		 * @param {array} $query_vars Query variables
		 * @param {array} $query Query part
		 * @return  {array} New query
		 */
		$formatted_args = apply_filters( 'ep_formatted_args', $formatted_args, $args, $wp_query );

		/**
		 * Filter formatted Elasticsearch [ost ]query (entire query)
		 *
		 * @hook ep_post_formatted_args
		 * @param {array} $formatted_args Formatted Elasticsearch query
		 * @param {array} $query_vars Query variables
		 * @param {array} $query Query part
		 * @return  {array} New query
		 */
		$formatted_args = apply_filters( 'ep_post_formatted_args', $formatted_args, $args, $wp_query );

		return $formatted_args;
	}

	/**
	 * Adjust the fuzziness parameter if needed.
	 *
	 * If using fields with type `long`, queries should not have a fuzziness parameter.
	 *
	 * @param array  $query         Current query
	 * @param array  $query_vars    Query variables
	 * @param string $search_text   Search text
	 * @param array  $search_fields Search fields
	 * @return array New query
	 */
	public function adjust_query_fuzziness( $query, $query_vars, $search_text, $search_fields ) {
		if ( empty( array_intersect( $search_fields, [ 'ID', 'post_id', 'post_parent' ] ) ) ) {
			return $query;
		}

		if ( ! isset( $query['bool'] ) || ! isset( $query['bool']['should'] ) ) {
			return $query;
		}

		foreach ( $query['bool']['should'] as &$clause ) {
			if ( ! isset( $clause['multi_match'] ) ) {
				continue;
			}

			if ( isset( $clause['multi_match']['fuzziness'] ) ) {
				unset( $clause['multi_match']['fuzziness'] );
			}
		}

		return $query;
	}

	/**
	 * Parse and build out our tax query.
	 *
	 * @access protected
	 *
	 * @param array $query Tax query
	 * @return array
	 */
	protected function parse_tax_query( $query ) {
		$tax_query = [
			'tax_filter'          => [],
			'tax_must_not_filter' => [],
		];
		$relation  = '';

		foreach ( $query as $tax_queries ) {
			// If we have a nested tax query, recurse through that
			if ( is_array( $tax_queries ) && empty( $tax_queries['taxonomy'] ) ) {
				$result      = $this->parse_tax_query( $tax_queries );
				$relation    = ( ! empty( $tax_queries['relation'] ) ) ? strtolower( $tax_queries['relation'] ) : 'and';
				$filter_type = 'and' === $relation ? 'must' : 'should';

				// Set the proper filter type and must_not filter, as needed
				if ( ! empty( $result['tax_must_not_filter'] ) ) {
					$tax_query['tax_filter'][] = [
						'bool' => [
							$filter_type => $result['tax_filter'],
							'must_not'   => $result['tax_must_not_filter'],
						],
					];
				} else {
					$tax_query['tax_filter'][] = [
						'bool' => [
							$filter_type => $result['tax_filter'],
						],
					];
				}
			}

			// Parse each individual tax query part
			$single_tax_query = $tax_queries;
			if ( ! empty( $single_tax_query['taxonomy'] ) ) {
				$terms = isset( $single_tax_query['terms'] ) ? (array) $single_tax_query['terms'] : array();
				$field = ( ! empty( $single_tax_query['field'] ) ) ? $single_tax_query['field'] : 'term_id';

				if ( 'name' === $field ) {
					$field = 'name.raw';
				}

				if ( 'slug' === $field ) {
					$terms = array_map( 'sanitize_title', $terms );
				}

				// Set up our terms object
				$terms_obj = array(
					'terms.' . $single_tax_query['taxonomy'] . '.' . $field => array_values( array_filter( $terms ) ),
				);

				$operator = ( ! empty( $single_tax_query['operator'] ) ) ? strtolower( $single_tax_query['operator'] ) : 'in';

				switch ( $operator ) {
					case 'exists':
						/**
						 * add support for "EXISTS" operator
						 *
						 * @since 2.5
						 */
						$tax_query['tax_filter'][]['bool'] = array(
							'must' => array(
								array(
									'exists' => array(
										'field' => key( $terms_obj ),
									),
								),
							),
						);

						break;
					case 'not exists':
						/**
						 * add support for "NOT EXISTS" operator
						 *
						 * @since 2.5
						 */
						$tax_query['tax_filter'][]['bool'] = array(
							'must_not' => array(
								array(
									'exists' => array(
										'field' => key( $terms_obj ),
									),
								),
							),
						);

						break;
					case 'not in':
						/**
						 * add support for "NOT IN" operator
						 *
						 * @since 2.1
						 */
						// If "NOT IN" than it should filter as must_not
						$tax_query['tax_must_not_filter'][]['terms'] = $terms_obj;

						break;
					case 'and':
						/**
						 * add support for "and" operator
						 *
						 * @since 2.4
						 */
						$and_nest = array(
							'bool' => array(
								'must' => array(),
							),
						);

						foreach ( $terms as $term ) {
							$and_nest['bool']['must'][] = array(
								'terms' => array(
									'terms.' . $single_tax_query['taxonomy'] . '.' . $field => (array) $term,
								),
							);
						}

						$tax_query['tax_filter'][] = $and_nest;

						break;
					case 'in':
					default:
						/**
						 * Default to IN operator
						 */
						// Add the tax query filter
						$tax_query['tax_filter'][]['terms'] = $terms_obj;

						break;
				}
			}
		}

		return $tax_query;
	}

	/**
	 * Parse an 'order' query variable and cast it to ASC or DESC as necessary.
	 *
	 * @since 1.1
	 * @access protected
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	protected function parse_order( $order ) {
		// Core will always set sort order to DESC for any invalid value,
		// so we can't do any automated testing of this function.
		// @codeCoverageIgnoreStart
		if ( ! is_string( $order ) || empty( $order ) ) {
			return 'desc';
		}
		// @codeCoverageIgnoreEnd

		if ( 'ASC' === strtoupper( $order ) ) {
			return 'asc';
		} else {
			return 'desc';
		}
	}

	/**
	 * Convert the alias to a properly-prefixed sort value.
	 *
	 * @since 1.1
	 * @access protected
	 *
	 * @param string $orderbys Alias or path for the field to order by.
	 * @param string $default_order Default order direction
	 * @param  array  $args Query args
	 * @return array
	 */
	protected function parse_orderby( $orderbys, $default_order, $args ) {
		$orderbys = $this->get_orderby_array( $orderbys );

		$sort = [];

		foreach ( $orderbys as $key => $value ) {
			if ( is_string( $key ) ) {
				$orderby_clause = $key;
				$order          = $value;
			} else {
				$orderby_clause = $value;
				$order          = $default_order;
			}

			if ( ! empty( $orderby_clause ) && 'rand' !== $orderby_clause ) {
				if ( 'relevance' === $orderby_clause ) {
					$sort[] = array(
						'_score' => array(
							'order' => $order,
						),
					);
				} elseif ( 'date' === $orderby_clause ) {
					$sort[] = array(
						'post_date' => array(
							'order' => $order,
						),
					);
				} elseif ( 'type' === $orderby_clause ) {
					$sort[] = array(
						'post_type.raw' => array(
							'order' => $order,
						),
					);
				} elseif ( 'modified' === $orderby_clause ) {
					$sort[] = array(
						'post_modified' => array(
							'order' => $order,
						),
					);
				} elseif ( 'name' === $orderby_clause ) {
					$sort[] = array(
						'post_' . $orderby_clause . '.raw' => array(
							'order' => $order,
						),
					);
				} elseif ( 'title' === $orderby_clause ) {
					$sort[] = array(
						'post_' . $orderby_clause . '.sortable' => array(
							'order' => $order,
						),
					);
				} elseif ( 'meta_value' === $orderby_clause ) {
					if ( ! empty( $args['meta_key'] ) ) {
						$sort[] = array(
							'meta.' . $args['meta_key'] . '.raw' => array(
								'order' => $order,
							),
						);
					}
				} elseif ( 'meta_value_num' === $orderby_clause ) {
					if ( ! empty( $args['meta_key'] ) ) {
						$sort[] = array(
							'meta.' . $args['meta_key'] . '.long' => array(
								'order' => $order,
							),
						);
					}
				} else {
					$sort[] = array(
						$orderby_clause => array(
							'order' => $order,
						),
					);
				}
			}
		}

		return $sort;
	}

	/**
	 * Get Order by args Array
	 *
	 * @param string|array $orderbys Order by string or array
	 * @since 2.1
	 * @return array
	 */
	protected function get_orderby_array( $orderbys ) {
		if ( ! is_array( $orderbys ) ) {
			$orderbys = explode( ' ', $orderbys );
		}

		return $orderbys;
	}

	/**
	 * Given a mapping content, try to determine the version used.
	 *
	 * @since 3.6.3
	 *
	 * @param array  $mapping Mapping content.
	 * @param string $index   Index name
	 * @return string         Version of the mapping being used.
	 */
	protected function determine_mapping_version_based_on_existing( $mapping, $index ) {
		if ( isset( $mapping[ $index ]['mappings']['post']['_meta']['mapping_version'] ) ) {
			return $mapping[ $index ]['mappings']['post']['_meta']['mapping_version'];
		}
		if ( isset( $mapping[ $index ]['mappings']['_meta']['mapping_version'] ) ) {
			return $mapping[ $index ]['mappings']['_meta']['mapping_version'];
		}

		/**
		 * Check for 7-0 mapping.
		 * If mapping has a `post` type, it can't be ES 7, as mapping types were removed in that release.
		 *
		 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/removal-of-types.html
		 */
		if ( ! isset( $mapping[ $index ]['mappings']['post'] ) ) {
			return '7-0.php';
		}

		$post_mapping = $mapping[ $index ]['mappings']['post'];

		/**
		 * Starting at this point, our tests rely on the post_title.fields.sortable field.
		 * As this field is present in all our mappings, if this field is not present in
		 * the mapping, this is a custom mapping.
		 *
		 * To have this code working with custom mappings, use the `ep_post_mapping_version_determined` filter.
		 */
		if ( ! isset( $post_mapping['properties']['post_title']['fields']['sortable'] ) ) {
			return 'unknown';
		}

		$post_title_sortable = $post_mapping['properties']['post_title']['fields']['sortable'];

		/**
		 * Check for 5-2 mapping.
		 * Normalizers on keyword fields were only made available in ES 5.2
		 *
		 * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.2/release-notes-5.2.0.html
		 */
		if ( isset( $post_title_sortable['normalizer'] ) ) {
			return '5-2.php';
		}

		/**
		 * Check for 5-0 mapping.
		 * `keyword` fields were only made available in ES 5.0
		 *
		 * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.0/release-notes-5.0.0.html
		 */
		if ( 'keyword' === $post_title_sortable['type'] ) {
			return '5-0.php';
		}

		/**
		 * Check for pre-5-0 mapping.
		 * `string` fields were deprecated in ES 5.0 in favor of text/keyword
		 *
		 * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.0/release-notes-5.0.0.html
		 */
		if ( 'string' === $post_title_sortable['type'] ) {
			return 'pre-5-0.php';
		}

		return 'unknown';
	}

	/**
	 * Given ES args, add aggregations to it.
	 *
	 * @since 4.1.0
	 * @param array   $formatted_args Formatted Elasticsearch query.
	 * @param array   $agg            Aggregation data.
	 * @param boolean $use_filters    Whether filters should be used or not.
	 * @param array   $filter         Filters defined so far.
	 * @return array Formatted Elasticsearch query with the aggregation added.
	 */
	protected function apply_aggregations( $formatted_args, $agg, $use_filters, $filter ) {
		if ( empty( $agg['aggs'] ) ) {
			return $formatted_args;
		}

		// Add a name to the aggregation if it was passed through
		$agg_name = ( ! empty( $agg['name'] ) ) ? $agg['name'] : 'aggregation_name';

		// Add/use the filter if warranted
		if ( isset( $agg['use-filter'] ) && false !== $agg['use-filter'] && $use_filters ) {

			// If a filter is being used, use it on the aggregation as well to receive relevant information to the query
			$formatted_args['aggs'][ $agg_name ]['filter'] = $filter;
			$formatted_args['aggs'][ $agg_name ]['aggs']   = $agg['aggs'];
		} else {
			$formatted_args['aggs'][ $agg_name ] = $agg['aggs'];
		}

		return $formatted_args;
	}

	/**
	 * Get the search algorithm that should be used.
	 *
	 * @since 4.3.0
	 * @param string $search_text   Search term(s)
	 * @param array  $search_fields Search fields
	 * @param array  $query_vars    Query vars
	 * @return SearchAlgorithm Instance of search algorithm to be used
	 */
	public function get_search_algorithm( string $search_text, array $search_fields, array $query_vars ) : \ElasticPress\SearchAlgorithm {
		$search_algorithm_version_option = \ElasticPress\Utils\get_option( 'ep_search_algorithm_version', '4.0' );

		/**
		 * Filter the algorithm version to be used.
		 *
		 * @since  3.5
		 * @hook ep_search_algorithm_version
		 * @param  {string} $search_algorithm_version Algorithm version.
		 * @return  {string} New algorithm version
		 */
		$search_algorithm = apply_filters( 'ep_search_algorithm_version', $search_algorithm_version_option );

		/**
		 * Filter the search algorithm to be used
		 *
		 * @hook ep_{$indexable_slug}_search_algorithm
		 * @since  4.3.0
		 * @param  {string} $search_algorithm Slug of the search algorithm used as fallback
		 * @param  {string} $search_term      Search term
		 * @param  {array}  $search_fields    Fields to be searched
		 * @param  {array}  $query_vars       Query variables
		 * @return {string} New search algorithm slug
		 */
		$search_algorithm = apply_filters( "ep_{$this->slug}_search_algorithm", $search_algorithm, $search_text, $search_fields, $query_vars );

		return \ElasticPress\SearchAlgorithms::factory()->get( $search_algorithm );
	}
}
