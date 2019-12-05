<?php
/**
 * P4 Search Class
 *
 * @package P4MT
 */

use Timber\Timber;
use Timber\Post as TimberPost;

if ( ! class_exists( 'P4_Search' ) ) {

	/**
	 * Abstract Class P4_Search
	 */
	abstract class P4_Search {

		const POSTS_LIMIT           = 300;
		const POSTS_PER_PAGE        = 10;
		const POSTS_PER_LOAD        = 5;
		const ARCHIVES_PER_LOAD     = 2;
		const PAGES_BEFORE_ARCHIVE  = 5;
		const SHOW_SCROLL_TIMES     = 2;
		const DEFAULT_SORT          = '_score';
		const DEFAULT_MIN_WEIGHT    = 1;
		const DEFAULT_PAGE_WEIGHT   = 100;
		const DEFAULT_ACTION_WEIGHT = 2000;
		const DEFAULT_MAX_WEIGHT    = 3000;
		const DEFAULT_CACHE_TTL     = 600;
		const DUMMY_THUMBNAIL       = '/images/dummy-thumbnail.png';
		const EXCLUDE_FROM_SEARCH   = 'p4_do_not_index';
		const POST_TYPES            = [
			'page',
			'campaign',
			'post',
			'attachment',
		];
		const DOCUMENT_TYPES        = [
			'application/pdf',
		];

		/**
		 * Search Query
		 *
		 * @var string $search_query
		 */
		protected $search_query;

		/**
		 * Posts
		 *
		 * @var array|bool|null $posts
		 */
		protected $posts;

		/**
		 * Paged Posts
		 *
		 * @var array|bool|null $paged_posts
		 */
		protected $paged_posts;

		/**
		 * Selected sort criteria
		 *
		 * @var array $selected_sort
		 */
		protected $selected_sort;

		/**
		 * FIlters
		 *
		 * @var array $filters
		 */
		protected $filters;

		/**
		 * Localizations
		 *
		 * @var array $localizations
		 */
		protected $localizations;

		/**
		 * Templates
		 *
		 * @var array $templates
		 */
		public $templates;

		/**
		 * Context
		 *
		 * @var array $context
		 */
		public $context;

		/**
		 * Current Page
		 *
		 * @var int $current_page
		 */
		public $current_page;

		/**
		 * P4_Search constructor.
		 */
		public function __construct() {}

		/**
		 * Initialize the class. Hook necessary actions and filters.
		 */
		protected function initialize() {
			$this->localizations = [
				// The ajaxurl variable is a global js variable defined by WP itself but only for the WP admin
				// For the frontend we need to define it ourselves and pass it to js.
				'ajaxurl'           => admin_url( 'admin-ajax.php' ),
				'show_scroll_times' => self::SHOW_SCROLL_TIMES,
			];
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
			add_filter( 'posts_where', [ $this, 'edit_search_mime_types' ] );
		}

		/**
		 * Conducts the actual search.
		 *
		 * @param string     $search_query The searched term.
		 * @param string     $selected_sort The selected order_by.
		 * @param array      $filters The selected filters.
		 * @param array      $templates An indexed array with template file names. The first to be found will be used.
		 * @param array|null $context An associative array with all the context needed to render the template found first.
		 */
		public function load( $search_query, $selected_sort = self::DEFAULT_SORT, $filters = [], $templates = [ 'search.twig', 'archive.twig', 'index.twig' ], $context = null ) {
			$this->initialize();
			$this->search_query = $search_query;
			$this->templates    = $templates;

			if ( $context ) {
				$this->context = $context;
			} else {
				$this->context = Timber::get_context();

				// Validate user input (sort, filters, etc).
				if ( $this->validate( $selected_sort, $filters, $this->context ) ) {
					$this->selected_sort = $selected_sort;
					$this->filters       = $filters;
				}

				// Set the decoded url query string as key.
				$query_string = urldecode( filter_input( INPUT_SERVER, 'QUERY_STRING', FILTER_SANITIZE_STRING ) );
				$group        = 'search';
				$subgroup     = $this->search_query ? $this->search_query : 'all';

				// Check Object cache for stored key.
				$cache_key_set = $this->prepare_keys_for_cache( $query_string, $group, $subgroup );
				$this->check_cache( $cache_key_set->key, $cache_key_set->group );

				// If posts were found either in object cache or primary database then get the first POSTS_PER_LOAD results.
				if ( $this->posts ) {
					$this->paged_posts = array_slice( $this->posts, 0, self::POSTS_PER_LOAD );
				}

				$this->current_page = ( 0 === get_query_var( 'paged' ) ) ? 1 : get_query_var( 'paged' );
				$this->set_context( $this->context );
			}
		}

		/**
		 * Callback for Lazy-loading the next results.
		 * Gets the paged posts that belong to the next page/load and are to be used with the twig template.
		 */
		public function get_paged_posts() {
			// If this is an ajax call.
			if ( wp_doing_ajax() ) {

				$search_action              = filter_input( INPUT_GET, 'search-action', FILTER_SANITIZE_STRING );
				$paged                      = filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_STRING );
				$total_posts                = filter_input(INPUT_GET, 'total_posts', FILTER_SANITIZE_STRING );
				$only_archived_loads        = filter_input( INPUT_GET, 'only_archived_loads', FILTER_SANITIZE_STRING );
				$archive_and_live_loads     = filter_input( INPUT_GET, 'archive_and_live_loads', FILTER_SANITIZE_STRING );

				$search_async = new static();
				$search_async->context['results_left'] = $paged;
				$search_async->set_context( $search_async->context );
				$search_async->search_query = urldecode( filter_input( INPUT_GET, 'search_query', FILTER_SANITIZE_STRING ) );

				// Get the decoded url query string and then use it as key for redis.
				$query_string_full = urldecode( filter_input( INPUT_SERVER, 'QUERY_STRING', FILTER_SANITIZE_STRING ) );
				$query_string      = str_replace( '&query-string=', '', strstr( $query_string_full, '&query-string=' ) );

				$group                      = 'search';
				$subgroup                   = $search_async->search_query ? $search_async->search_query : 'all';
				$search_async->current_page = $paged;

				parse_str( $query_string, $filters_array );
				$selected_sort    = $filters_array['orderby'] ?? self::DEFAULT_SORT;
				$selected_filters = $filters_array['f'] ?? [];
				$filters          = [];

				// Handle submitted filter options.
				if ( $selected_filters && is_array( $selected_filters ) ) {
					foreach ( $selected_filters as $type => $filter_type ) {
						foreach ( $filter_type as $name => $id ) {
							$filters[ $type ][] = [
								'id'   => $id,
								'name' => $name,
							];
						}
					}
				}

				// Validate user input (sort, filters, etc).
				if ( $search_async->validate( $selected_sort, $filters, $search_async->context ) ) {
					$search_async->selected_sort = $selected_sort;
					$search_async->filters       = $filters;
				}

				// Check Object cache for stored key.
				$cache_key_set = $search_async->prepare_keys_for_cache( urldecode( $query_string ), $group, $subgroup );
				$search_async->check_cache( $cache_key_set->key, $cache_key_set->group );

				// Set paged posts depending on call action
				if ( 'get_paged_posts' === $search_action ) {
					$search_async->set_paged_posts( $archive_and_live_loads );
				} else if ( 'get_archived_posts' === $search_action ) {
					$search_async->set_archived_posts( $only_archived_loads, $archive_and_live_loads, $total_posts );
				}

				// If there are paged results then set their context and send them back to client.
				if ( $search_async->paged_posts ) {
					$search_async->set_results_context( $search_async->context );
					$search_async->view_paged_posts();
				}

				wp_die();
			}
		}

		/**
		 * Set paged posts with next load of live posts.
		 *
		 * @param $archive_and_live_loads integer the number of loads with a combination or live and archived results.
		 */
		protected function set_paged_posts( $archive_and_live_loads ) {
			// Check if there are results already in the cache else fallback to the primary database.
			if ( $this->posts ) {
				$live_posts_offset = ( ( $this->current_page - ( $archive_and_live_loads + 1 ) ) * self::POSTS_PER_LOAD ) + ( $archive_and_live_loads * ( self::POSTS_PER_LOAD - self::ARCHIVES_PER_LOAD ) );

				$this->paged_posts = array_slice( $this->posts, $live_posts_offset, self::POSTS_PER_LOAD );

			} else {
				$this->paged_posts = $this->get_timber_posts( $this->current_page );
			}
		}

		/**
		 * Sets the paged posts as a combination of live posts and archived results.
		 *
		 * @param $only_archived_loads integer the number of loads containing only archived results.
		 * @param $archive_and_live_loads integer the number of loads with a combination or live and archived results.
		 * @param $total_posts integer the total number of live posts.
		 */
		protected function set_archived_posts( $only_archived_loads, $archive_and_live_loads, $total_posts ) {

			$archived_results = $this->get_archived_results( $this->search_query );

			$archived_results_offset = ( $archive_and_live_loads * self::ARCHIVES_PER_LOAD ) + ( $only_archived_loads * self::POSTS_PER_LOAD );

			$live_posts_offset = ( ( $this->current_page - ( $archive_and_live_loads + 1 ) ) * self::POSTS_PER_LOAD ) + ( $archive_and_live_loads * ( self::POSTS_PER_LOAD - self::ARCHIVES_PER_LOAD ) );

			if ( $this->posts ) {

				$live_posts_per_load = $archived_results_offset >= count( $archived_results ) ? self::POSTS_PER_LOAD : self::POSTS_PER_LOAD - self::ARCHIVES_PER_LOAD;

				$archives_per_load = $live_posts_offset >= count( $this->posts ) ? self::POSTS_PER_LOAD : self::ARCHIVES_PER_LOAD;

				$live_posts_to_load = array_slice( $this->posts, $live_posts_offset, $live_posts_per_load );

				$archived_results_to_load = array_slice( $archived_results, $archived_results_offset, $archives_per_load );

				$this->paged_posts = array_merge( $live_posts_to_load, $archived_results_to_load );
			} else {
				//Posts not cached:
				if ( $live_posts_offset >= $total_posts ) {
					$this->paged_posts = array_slice( $archived_results, $archived_results_offset );
				} else {
					$live_results_to_load = array_slice( $this->get_timber_posts( $this->current_page ), 0, self::POSTS_PER_LOAD - self::ARCHIVES_PER_LOAD );
					$archived_results_to_load = array_slice( $archived_results, $archived_results_offset, self::ARCHIVES_PER_LOAD );
					$this->paged_posts = array_merge( $live_results_to_load, $archived_results_to_load );
				}
			}
		}

		/**
		 * Check if search is cached. If it is not then get posts from primary database and cache it.
		 *
		 * @param string $cache_key The key that will be used for storing the results in the object cache.
		 * @param string $cache_group The group that will be used for storing the results in the object cache.
		 */
		protected function check_cache( $cache_key, $cache_group ) {
			// Get search results from cache and then set the context for those results.
			$this->posts = wp_cache_get( $cache_key, $cache_group );

			// If cache key expired then retrieve results once again and re-cache them.
			if ( false === $this->posts ) {
				$this->posts = $this->get_timber_posts();

				if ( $this->posts ) {
					wp_cache_add( $cache_key, $this->posts, $cache_group, self::DEFAULT_CACHE_TTL );
				}
			}
		}

		/**
		 * Fetches archived results from ArchiveIt.
		 *
		 * @param $search_query string search query entered by user.
		 *
		 * @return array of archived results.
		 */
		protected function get_archived_results( $search_query ) {

			$group = 'archive-search';
			$subgroup = 'all';
			$cache_key_set = $this->prepare_keys_for_cache( urldecode( $search_query.'-archived' ), $group, $subgroup );
			$archive_results = wp_cache_get( $cache_key_set->key, $cache_key_set->group );

			if ( !$archive_results ) {
				/**
				 * q = search query.
				 * s = site.
				 * h = hits per site (0 = all).
				 * n = number of results. defaults to 10, so making it 100 should get enough.
				 */
				$archive_url = "https://archive-it.org/search-master/opensearch?q=$search_query&s=p3-raw.greenpeace.org&h=0&n=100";

				$archive_response = wp_remote_get( $archive_url, array(
					'timeout' => 100
				) );

				$xml_archive_response_body = wp_remote_retrieve_body( $archive_response );

				$response_body = new SimpleXMLElement( $xml_archive_response_body );

				$filtered_results = $this->get_filtered_archived_results( $response_body );

				if ( 0 == count( $filtered_results ) ) {
					return [];
				}

				$parsed_items = [];

				foreach ( $filtered_results as $item ) {

					$parsed_item            = (object) array();
					$parsed_item->title     = (string) $item->title;
					$parsed_item->link      = (string) $item->link;
					$parsed_item->excerpt   = (string) $item->description;
					$parsed_item->post_type = 'archive';

					array_push( $parsed_items, $parsed_item );
				}

				$archive_results = $parsed_items;

				wp_cache_add( $cache_key_set->key, $parsed_items, $cache_key_set->group, self::DEFAULT_CACHE_TTL );
			}

			return $archive_results;
		}

		/**
		 * Filter archived results by current domain.
		 *
		 * @param $results array of all results fetched from archive.
		 *
		 * @return array of archived results filtered by the current site.
		 */
		protected function get_filtered_archived_results( $results ) {

			$full_url = explode('/', strtok( get_page_link(), '?' ) );

			//["https:","","www.greenpeace.org","africa","en",""] - Language Specified
			//["https:","","www.greenpeace.org","international",""] - No Language
			//["https:","","www.greenpeace.ch","de",""] - Switzerland

			if ( strcmp('nl', $full_url[3] ) == 0 ) {
				$archive_ref = 'secured.greenpeace.nl';
			} else if ( false != strpos( $full_url[2],'greenpeace.ch' ) ) {
				$archive_ref = 'switzerland/'.$full_url[3];
			} else if ( count( $full_url ) > 4 ) {
				//Contains language in URL
				$archive_ref = $full_url[3].'/'.$full_url[4];
			} else if ( count( $full_url ) > 3 ) {
				$archive_ref = $full_url[3];
			} else {
				$archive_ref = '/';
			}

			$filtered_items = [];

			foreach ( $results->channel->item as $item ) {
				if ( strpos( (string) $item->link, $archive_ref ) !== false) {
					array_push($filtered_items, $item );
				}
			}

			return $filtered_items;
		}

		/**
		 * Gets the respective Timber Posts, to be used with the twig template.
		 * If there are not then uses Timber's get_posts to retrieve all of them (up to the limit set).
		 *
		 * @param int $paged The number of the page of the results to be shown when using pagination/load_more.
		 *
		 * @return array The respective Timber Posts.
		 */
		protected function get_timber_posts( $paged = 1 ) : array {
			$timber_posts = [];

			$posts = $this->get_posts( $paged );
			// Use Timber's Post instead of WP_Post so that we can make use of Timber within the template.
			if ( $posts ) {
				foreach ( $posts as $post ) {
					$timber_posts[] = new TimberPost( $post->ID );
				}
			}
			return (array) $timber_posts;
		}

		/**
		 * Applies user selected filters to the search if there are any and gets the filtered posts.
		 *
		 * @param int $paged The number of the page of the results to be shown when using pagination/load_more.
		 *
		 * @return array The posts of the search.
		 */
		public function get_posts( $paged = 1 ) : array {
			// Set General Query arguments.
			$args = $this->get_general_args( $paged );

			// Set Filters Query arguments.
			try {
				$this->set_filters_args( $args );
			} catch ( UnexpectedValueException $e ) {
				$this->context['exception'] = $e->getMessage();
				return [];
			}

			// Set Engine Query arguments.
			$this->set_engines_args( $args );
			$posts = ( new WP_Query( $args ) )->posts;
			return (array) $posts;
		}

		/**
		 * Sets arguments for the WP_Query that are related to the application.
		 *
		 * @param int $paged The number of the page of the results to be shown when using pagination/load_more.
		 * @return array
		 */
		protected function get_general_args( $paged ): array {
			$args = [
				'posts_per_page' => self::POSTS_LIMIT,          // Set a high maximum because -1 will get ALL posts and this can be very intensive in production.
				'no_found_rows'  => true,                       // This means that the result counters of each filter might not be 100% precise.
				'post_type'      => self::POST_TYPES,
				'post_status'    => [ 'publish', 'inherit' ],
			];

			if ( $paged > 1 ) {
				$args['posts_per_page'] = self::POSTS_PER_LOAD;
				$args['paged']          = $paged;
			}

			if ( $this->search_query ) {
				$args['s']          = $this->search_query;
				$args['meta_query'] = [
					[
						'key'     => self::EXCLUDE_FROM_SEARCH,
						'compare' => 'NOT EXISTS',
					],
				];
			} else {
				$args2 = [
					'orderby'    => 'meta_value date',  // If we search for everything then order first by 'weight' and then by 'post_date'.
					'order'      => 'DESC DESC',
					'meta_query' => [
						'relation' => 'AND',
						[
							'relation' => 'OR',
							[
								'key'     => 'weight',
								'compare' => 'NOT EXISTS',
							],
							[
								'key'     => 'weight',
								'compare' => 'EXISTS',
							],
						],
						[
							'key'     => self::EXCLUDE_FROM_SEARCH,
							'compare' => 'NOT EXISTS',
						],
					],
				];
				$args  = array_merge( $args, $args2 );
			}

			$args['s'] = $this->search_query;
			// Add sort by date.
			$selected_sort = filter_input( INPUT_GET, 'orderby', FILTER_SANITIZE_STRING );
			$selected_sort = sanitize_sql_orderby( $selected_sort );

			if ( $selected_sort && self::DEFAULT_SORT !== $selected_sort ) {
				$args['orderby'] = 'date';
				$args['order']   = 'desc';
			}

			$args['search_fields'] = [
				'post_title',
				'post_content',
				'post_excerpt',
				'post_author.display_name',
			];

			return $args;
		}

		/**
		 * Adds arguments for the WP_Query that are related only to the search engine.
		 *
		 * @param array $args The search query args.
		 */
		abstract public function set_engines_args( &$args );

		/**
		 * Applies user selected filters to the search if there are any and gets the filtered posts.
		 *
		 * @param array $args The search query args.
		 *
		 * @throws UnexpectedValueException When filter type is not recognized.
		 */
		public function set_filters_args( &$args ) {
			if ( $this->filters ) {
				foreach ( $this->filters as $type => $filter_type ) {
					foreach ( $filter_type as $filter ) {
						switch ( $type ) {
							case 'cat':
								$args['tax_query'][] = [
									'taxonomy' => 'category',
									'field'    => 'term_id',
									'terms'    => $filter['id'],
								];
								break;
							case 'tag':
								$args['tax_query'][] = [
									'taxonomy' => 'post_tag',
									'field'    => 'term_id',
									'terms'    => $filter['id'],
								];
								break;
							case 'ptype':
								// This taxonomy is used only for Posts.
								$args['post_type']   = 'post';
								$args['tax_query'][] = [
									'taxonomy' => 'p4-page-type',
									'field'    => 'term_id',
									'terms'    => $filter['id'],
								];
								break;
							case 'ctype':
								switch ( $filter['id'] ) {
									case 0:
										$args['post_type']   = 'page';
										$args['post_status'] = 'publish';
										$options             = get_option( 'planet4_options' );
										$args['post_parent'] = esc_sql( $options['act_page'] );
										break;
									case 1:
										$args['post_type']      = 'attachment';
										$args['post_status']    = 'inherit';
										$args['post_mime_type'] = self::DOCUMENT_TYPES;
										break;
									case 2:
										$args['post_type']             = 'page';
										$args['post_status']           = 'publish';
										$options                       = get_option( 'planet4_options' );
										$args['post_parent__not_in'][] = esc_sql( $options['act_page'] );
										break;
									case 3:
										$args['post_type']   = 'post';
										$args['post_status'] = 'publish';
										break;
									case 4:
										$args['post_type']   = 'campaign';
										$args['post_status'] = 'publish';
										break;
									default:
										throw new UnexpectedValueException( 'Unexpected content type!' );
								}
								break;
							default:
								throw new UnexpectedValueException( 'Unexpected filter!' );
						}
					}
				}
			}
		}

		/**
		 * Sets the P4 Search page context.
		 *
		 * @param array $context Associative array with the data to be passed to the view.
		 */
		protected function set_context( &$context ) {
			$this->set_general_context( $context );
			try {
				$this->set_filters_context( $context );
			} catch ( UnexpectedValueException $e ) {
				$this->context['exception'] = $e->getMessage();
			}
			$this->set_results_context( $context );
		}

		/**
		 * Sets the general context for the Search page.
		 *
		 * @param array $context Associative array with the data to be passed to the view.
		 */
		protected function set_general_context( &$context ) {

			// Search context.
			$context['posts']            = $this->posts;
			$context['paged_posts']      = $this->paged_posts;
			$context['current_page']     = $this->current_page;
			$context['search_query']     = $this->search_query;
			$context['selected_sort']    = $this->selected_sort;
			$context['default_sort']     = self::DEFAULT_SORT;
			$context['filters']          = $this->filters;
			$context['found_posts']      = count( (array) $this->posts );
			$context['source_selection'] = false;
			$context['page_category']    = 'Search Page';
			$context['sort_options']     = [
				'_score'    => [
					'name'  => __( 'Most relevant', 'planet4-master-theme' ),
					'order' => 'DESC',
				],
				'post_date' => [
					'name'  => __( 'Most recent', 'planet4-master-theme' ),
					'order' => 'DESC',
				],
			];

			if ( $this->search_query ) {
				$context['page_title'] = sprintf(
				// translators: %1$d = Number of results.
					_n( '%1$d result for \'%2$s\'', '%1$d results for \'%2$s\'', $context['found_posts'], 'planet4-master-theme' ),
					$context['found_posts'],
					$this->search_query
				);
			} else {
				// translators: %d = Number of results.
				$context['page_title'] = sprintf( _n( '%d result', '%d results', $context['found_posts'], 'planet4-master-theme' ), $context['found_posts'] );
			}
		}

		/**
		 * Set filters context
		 *
		 * @param mixed $context Context.
		 *
		 * @throws UnexpectedValueException When filter type is not recognized.
		 */
		protected function set_filters_context( &$context ) {
			// Retrieve P4 settings in order to check that we add only categories that are children of the Issues category.
			$options = get_option( 'planet4_options' );

			// Category <-> Issue.
			// Consider Issues that have multiple Categories.
			$categories = get_categories();
			if ( $categories ) {
				foreach ( $categories as $category ) {
					if ( $category->parent === (int) $options['issues_parent_category'] ) {
						$context['categories'][ $category->term_id ] = [
							'term_id' => $category->term_id,
							'name'    => $category->name,
							'results' => 0,
						];
					}
				}
			}

			// Tag <-> Campaign.
			$tags = get_terms(
				[
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
				]
			);
			if ( $tags ) {
				foreach ( (array) $tags as $tag ) {
					// Tag filters.
					$context['tags'][ $tag->term_id ] = [
						'term_id' => $tag->term_id,
						'name'    => $tag->name,
						'results' => 0,
					];
				}
			}

			// Page Type <-> Category.
			$page_types = get_terms(
				[
					'taxonomy'   => 'p4-page-type',
					'hide_empty' => false,
				]
			);
			if ( $page_types ) {
				foreach ( (array) $page_types as $page_type ) {
					// p4-page-type filters.
					$context['page_types'][ $page_type->term_id ] = [
						'term_id' => $page_type->term_id,
						'name'    => $page_type->name,
						'results' => 0,
					];
				}
			}

			// Post Type (+Action) <-> Content Type.
			$context['content_types']['0'] = [
				'name'    => __( 'Action', 'planet4-master-theme' ),
				'results' => 0,
			];
			$context['content_types']['4'] = [
				'name'    => __( 'Campaign', 'planet4-master-theme' ),
				'results' => 0,
			];
			$context['content_types']['1'] = [
				'name'    => __( 'Document', 'planet4-master-theme' ),
				'results' => 0,
			];
			$context['content_types']['2'] = [
				'name'    => __( 'Page', 'planet4-master-theme' ),
				'results' => 0,
			];
			$context['content_types']['3'] = [
				'name'    => __( 'Post', 'planet4-master-theme' ),
				'results' => 0,
			];

			// Keep track of which filters are already checked.
			if ( $this->filters ) {
				foreach ( $this->filters as $type => $filter_type ) {
					foreach ( $filter_type as $filter ) {
						switch ( $type ) {
							case 'cat':
								$context['categories'][ $filter['id'] ]['checked'] = 'checked';
								break;
							case 'tag':
								$context['tags'][ $filter['id'] ]['checked'] = 'checked';
								break;
							case 'ptype':
								$context['page_types'][ $filter['id'] ]['checked'] = 'checked';
								break;
							case 'ctype':
								$context['content_types'][ $filter['id'] ]['checked'] = 'checked';
								break;
							default:
								throw new UnexpectedValueException( 'Unexpected filter!' );
						}
					}
				}
			}

			// Sort associative array with filters alphabetically.
			if ( $context['categories'] ) {
				uasort(
					$context['categories'],
					function ( $a, $b ) {
						return strcmp( $a['name'], $b['name'] );
					}
				);
			}
			if ( $context['tags'] ) {
				uasort(
					$context['tags'],
					function ( $a, $b ) {
						return strcmp( $a['name'], $b['name'] );
					}
				);
			}
		}

		/**
		 * Sets the context for the results of the Search.
		 *
		 * Categories are Issues.
		 * Tags       are Campaigns.
		 * Page types are Categories.
		 * Post_types are Content Types.
		 *
		 * @param array $context Associative array with the data to be passed to the view.
		 */
		protected function set_results_context( &$context ) {

			$posts = $this->posts;

			// Retrieve P4 settings in order to check that we add only categories that are children of the Issues category.
			$options = get_option( 'planet4_options' );

			// Pass planet4 settings.
			$context['settings'] = $options;

			// Set default thumbnail.
			$context['posts_data']['dummy_thumbnail'] = get_template_directory_uri() . self::DUMMY_THUMBNAIL;

			foreach ( (array) $posts as $post ) {
				// Post Type (+Action) <-> Content Type.
				switch ( $post->post_type ) {
					case 'page':
						if ( $post->post_parent === (int) $options['act_page'] ) {
							$content_type_text = __( 'ACTION', 'planet4-master-theme' );
							$content_type      = 'action';
							$context['content_types']['0']['results']++;
						} else {
							$content_type_text = __( 'PAGE', 'planet4-master-theme' );
							$content_type      = 'page';
							$context['content_types']['2']['results']++;
						}
						break;
					case 'campaign':
						$content_type_text = __( 'CAMPAIGN', 'planet4-master-theme' );
						$content_type      = 'campaign';
						$context['content_types']['4']['results']++;
						break;
					case 'attachment':
						$content_type_text = __( 'DOCUMENT', 'planet4-master-theme' );
						$content_type      = 'document';
						$context['content_types']['1']['results']++;
						break;
					case 'post':
						$content_type_text = __( 'POST', 'planet4-master-theme' );
						$content_type      = 'post';
						$context['content_types']['3']['results']++;
						break;
					default:
						continue 2;     // Ignore other post_types and continue with next $post.
				}
				$context['posts_data'][ $post->ID ]['content_type_text'] = $content_type_text;
				$context['posts_data'][ $post->ID ]['content_type']      = $content_type;

				// Page Type <-> Category. This taxonomy is used only for Posts.
				if ( 'post' === $post->post_type ) {
					$page_types = get_the_terms( $post->ID, 'p4-page-type' );
					if ( is_array( $page_types ) ) {
						foreach ( (array) $page_types as $page_type ) {
							// p4-page-type filters.
							$context['page_types'][ $page_type->term_id ]['term_id'] = $page_type->term_id;
							$context['page_types'][ $page_type->term_id ]['name']    = $page_type->name;
							$context['page_types'][ $page_type->term_id ]['results'] ++;
						}
					}
					$context['posts_data'][ $post->ID ]['page_types'] = $page_types;
				}

				// Tag <-> Campaign.
				if ( 'attachment' !== $post->post_type ) {
					// Category <-> Issue.
					// Consider Issues that have multiple Categories.
					$categories = get_the_category( $post->ID );
					if ( $categories ) {
						foreach ( $categories as $category ) {
							if ( $category->parent === (int) $options['issues_parent_category'] ) {
								$context['categories'][ $category->term_id ]['term_id'] = $category->term_id;
								$context['categories'][ $category->term_id ]['name']    = $category->name;
								$context['categories'][ $category->term_id ]['results']++;
							}
						}
					}

					$tags = get_the_terms( $post->ID, 'post_tag' );
					if ( $tags ) {
						foreach ( (array) $tags as $tag ) {
							// Set tags info for each result item.
							$context['posts_data'][ $post->ID ]['tags'][] = [
								'name' => $tag->name,
								'link' => get_tag_link( $tag ),
							];

							// Tag filters.
							$context['tags'][ $tag->term_id ]['term_id'] = $tag->term_id;
							$context['tags'][ $tag->term_id ]['name']    = $tag->name;
							$context['tags'][ $tag->term_id ]['results'] ++;
						}
					}
				}
			}
		}

		/**
		 * Customize which mime types we want to search for regarding attachments.
		 *
		 * @param string $where The WHERE clause of the query.
		 *
		 * @return string The edited WHERE clause.
		 */
		public function edit_search_mime_types( $where ) : string {
			global $wpdb;

			$search_action = filter_input( INPUT_GET, 'search-action', FILTER_SANITIZE_STRING );

			if ( ! is_admin() && is_search() ||
			     wp_doing_ajax() && ( 'get_paged_posts' === $search_action ) ) {
				$mime_types = implode( ',', self::DOCUMENT_TYPES );
				$where     .= ' AND ' . $wpdb->posts . '.post_mime_type IN("' . $mime_types . '","") ';
			}
			return $where;
		}

		/**
		 * Validates the input.
		 *
		 * @param string $selected_sort The selected orderby to be validated.
		 * @param array  $filters The selected filters to be validated.
		 * @param array  $context Associative array with the data to be passed to the view.
		 *
		 * @return bool True if validation is ok, false if validation fails.
		 */
		public function validate( &$selected_sort, &$filters, $context ) : bool {
			$selected_sort = filter_var( $selected_sort, FILTER_SANITIZE_STRING );
			if ( ! isset( $context['sort_options'] ) || ! in_array( $selected_sort, array_keys( $context['sort_options'] ), true ) ) {
				$selected_sort = self::DEFAULT_SORT;
			}

			if ( $filters ) {
				foreach ( $filters as &$filter_type ) {
					foreach ( $filter_type as &$filter ) {
						$filter['id'] = filter_var( $filter['id'], FILTER_VALIDATE_INT );
						if ( false === $filter['id'] || null === $filter['id'] || $filter['id'] < 0 ) {
							return false;
						}
					}
				}
			}
			return true;
		}

		/**
		 * Adds a section with a Load more button.
		 *
		 * @param array|null $args The array with the data for the pagination.
		 */
		public function add_load_more( $args = null ) {
			$this->context['load_more'] = $args ?? [
					'posts_per_load' => self::POSTS_PER_LOAD,
					'archives_per_load' => self::ARCHIVES_PER_LOAD,
					'page_archive_available_after' => $this->get_page_to_add_load_archive_after(),
					// Translators: %s = number of results per page.
					'button_text'    => sprintf( __( 'SHOW %s MORE RESULTS', 'planet4-master-theme' ), self::POSTS_PER_LOAD ),
					'async'          => true,
				];
		}

		protected function get_page_to_add_load_archive_after() {
			$pages = ceil( count( $this->posts ) / self::POSTS_PER_LOAD );
			return min( self::PAGES_BEFORE_ARCHIVE, $pages );
		}

		/**
		 * View the Search page template.
		 */
		public function view() {
			Timber::render( $this->templates, $this->context, self::DEFAULT_CACHE_TTL, \Timber\Loader::CACHE_OBJECT );
		}

		/**
		 * View the paged posts of the next page/load.
		 */
		public function view_paged_posts() {
			// TODO - The $paged_context related code should be transferred to set_results_context method for better separation of concerns.
			if ( $this->paged_posts ) {
				$paged_context             = [
					'posts_data' => $this->context['posts_data'],
				];
				$paged_context['settings'] = get_option( 'planet4_options' );

				foreach ( $this->paged_posts as $index => $post ) {
					$paged_context['post'] = $post;
					if ( 0 === $index % self::POSTS_PER_LOAD ) {
						$paged_context['first_of_the_page'] = true;
					} else {
						$paged_context['first_of_the_page'] = false;
					}
					Timber::render( [ 'tease-search.twig' ], $paged_context, self::DEFAULT_CACHE_TTL, \Timber\Loader::CACHE_OBJECT );
				}
			}
		}

		/**
		 * Load assets only on the search page.
		 */
		public function enqueue_public_assets() {
			if ( is_search() ) {
				wp_register_script( 'search', get_template_directory_uri() . '/assets/js/search.js', [ 'jquery' ], '0.2.8', true );
				wp_localize_script( 'main', 'localizations', $this->localizations );
				wp_enqueue_script( 'search' );
			}
		}

		/**
		 * Prepare query strings for cache keys.
		 *
		 * @param string $query_string Search query string.
		 * @param string $group        Cache group.
		 * @param string $subgroup     Cache subgroup.
		 *
		 * @return stdClass
		 */
		private function prepare_keys_for_cache( $query_string, $group, $subgroup ) {
			$cache_key_set        = new stdClass();
			$cache_key_set->key   = urlencode( $query_string );
			$cache_key_set->group = urlencode( $group ) . ':' . urlencode( $subgroup );

			return $cache_key_set;
		}
	}
}
