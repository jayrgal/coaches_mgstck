<?php
namespace Magstack\Coaches\Seo;

use Magstack\Coaches\Assets;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Coach\Directory;
use Magstack\Coaches\Facility\Facility;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\PostTypes;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-generated SEO landing pages built from coach and facility data:
 *   /pickleball-coaches/{city}/  and  /pickleball-courts/{city}/  (+ index pages).
 * Virtual pages (no posts created) – always current, nothing to maintain.
 * Plays nicely with Yoast SEO and Rank Math: we feed our title/description/canonical
 * through their filters and only print our own meta tags when neither is active.
 */
final class LocationPages {

	/** @var array|null Context of the current virtual page. */
	private static ?array $ctx = null;

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'add_rewrites' ), 20 );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'the_posts', array( self::class, 'inject_post' ), 10, 2 );
		add_filter( 'pre_get_document_title', array( self::class, 'title' ), 20 );
		add_action( 'wp_head', array( self::class, 'head' ), 1 );
		add_filter( 'redirect_canonical', array( self::class, 'no_canonical_redirect' ) );
		add_shortcode( 'magstack_locations', array( self::class, 'locations_shortcode' ) );

		// Yoast SEO.
		add_filter( 'wpseo_title', array( self::class, 'seo_title' ), 20 );
		add_filter( 'wpseo_metadesc', array( self::class, 'seo_desc' ), 20 );
		add_filter( 'wpseo_canonical', array( self::class, 'seo_canonical' ), 20 );
		// Rank Math.
		add_filter( 'rank_math/frontend/title', array( self::class, 'seo_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( self::class, 'seo_desc' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( self::class, 'seo_canonical' ), 20 );
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'seo_enabled', 1 );
	}

	public static function add_rewrites(): void {
		// Index routes yield to a real page that already owns the slug (recorded at flush time, no query per request).
		$skip = (array) get_option( 'magstack_seo_index_skip', array() );
		foreach ( array( 'coaches', 'courts' ) as $type ) {
			if ( ! in_array( $type, $skip, true ) ) {
				add_rewrite_rule( '^pickleball-' . $type . '/?$', 'index.php?mc_loc=' . $type, 'top' );
			}
			add_rewrite_rule( '^pickleball-' . $type . '/([^/]+)/?$', 'index.php?mc_loc=' . $type . '&mc_city=$matches[1]', 'top' );
		}
	}

	/** Remember which index slugs are already taken by real pages. */
	public static function detect_conflicts(): void {
		$skip = array();
		foreach ( array( 'coaches', 'courts' ) as $type ) {
			if ( get_page_by_path( 'pickleball-' . $type ) ) {
				$skip[] = $type;
			}
		}
		update_option( 'magstack_seo_index_skip', $skip );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'mc_loc';
		$vars[] = 'mc_city';
		return $vars;
	}

	public static function flush(): void {
		Coach::clear_caches();
		self::detect_conflicts();
		self::add_rewrites();
		flush_rewrite_rules();
	}

	public static function index_url( string $type ): string {
		if ( in_array( $type, (array) get_option( 'magstack_seo_index_skip', array() ), true ) ) {
			$page = get_page_by_path( 'pickleball-' . $type );
			return $page ? (string) get_permalink( $page ) : home_url( '/' );
		}
		return home_url( user_trailingslashit( 'pickleball-' . $type ) );
	}

	public static function city_url( string $type, string $slug ): string {
		return home_url( user_trailingslashit( 'pickleball-' . $type . '/' . $slug ) );
	}

	public static function no_canonical_redirect( $redirect ) {
		return get_query_var( 'mc_loc' ) ? false : $redirect;
	}

	private static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	// ---- Context ----------------------------------------------------------------------------

	/** Build (and cache) the context for the current request, or null when not a location page. */
	private static function context(): ?array {
		if ( null !== self::$ctx ) {
			return self::$ctx ?: null;
		}
		self::$ctx = array();
		$type = sanitize_key( (string) get_query_var( 'mc_loc' ) );
		if ( ! $type || ! self::enabled() || ! in_array( $type, array( 'coaches', 'courts' ), true ) ) {
			return null;
		}
		$slug   = sanitize_title( (string) get_query_var( 'mc_city' ) );
		$cities = Coach::cities()[ 'coaches' === $type ? 'coaches' : 'facilities' ];

		if ( '' === $slug ) {
			self::$ctx = array(
				'type'  => $type,
				'index' => true,
				'city'  => '',
				'slug'  => '',
			) + self::index_meta( $type, $cities );
			return self::$ctx;
		}
		if ( ! isset( $cities[ $slug ] ) ) {
			self::$ctx = array( 'type' => $type, 'missing' => true );
			return self::$ctx;
		}
		$city = $cities[ $slug ];
		$data = 'coaches' === $type ? self::coach_data( $city ) : self::court_data( $city );
		self::$ctx = array(
			'type'  => $type,
			'index' => false,
			'city'  => $city,
			'slug'  => $slug,
		) + $data;
		return self::$ctx;
	}

	private static function index_meta( string $type, array $cities ): array {
		$label = 'coaches' === $type ? __( 'Pickleball Coaches in the Philippines', 'magstack-coaches' ) : __( 'Pickleball Courts in the Philippines', 'magstack-coaches' );
		$desc  = 'coaches' === $type
			? sprintf( /* translators: %d: number of cities */ __( 'Find and book pickleball coaches in %d Philippine cities. Compare rates, skill levels and availability, then reserve online.', 'magstack-coaches' ), count( $cities ) )
			: sprintf( /* translators: %d: number of cities */ __( 'Browse pickleball courts and partner facilities in %d Philippine cities with hours, surfaces and court fees.', 'magstack-coaches' ), count( $cities ) );
		return array(
			'title' => $label,
			'desc'  => $desc,
			'h1'    => $label,
			'ids'   => array(),
		);
	}

	private static function pick( array $variants, string $seed ): string {
		return $variants[ abs( crc32( $seed ) ) % count( $variants ) ];
	}

	private static function coach_data( string $city ): array {
		$ids   = Coach::query( array( 'city' => $city ) );
		$n     = count( $ids );
		$rates = array_map( array( Coach::class, 'rate' ), $ids );
		$min   = $rates ? min( $rates ) : 0;
		$max   = $rates ? max( $rates ) : 0;
		$ver   = count( array_filter( $ids, array( Coach::class, 'is_verified' ) ) );
		$lv    = array();
		foreach ( $ids as $id ) {
			foreach ( Coach::levels( $id ) as $l ) {
				$lv[ $l ] = ( $lv[ $l ] ?? 0 ) + 1;
			}
		}
		arsort( $lv );
		$labels   = Helpers::level_labels();
		$top      = array_map( static fn( $k ) => strtolower( $labels[ $k ] ), array_slice( array_keys( $lv ), 0, 3 ) );
		$courts   = array_slice( array_map( 'get_the_title', Facility::query( array( 'city' => $city, 'partner_only' => true ) ) ), 0, 3 );
		$rate_txt = $n ? ( $min === $max ? Helpers::money( $min ) : Helpers::money( $min ) . '–' . Helpers::money( $max ) ) : '';

		$count = sprintf( /* translators: %d: number of coaches */ _n( '%d coach', '%d coaches', $n, 'magstack-coaches' ), $n );
		$intro1 = self::pick(
			array(
				/* translators: 1: "3 coaches" 2: city */
				sprintf( __( 'Looking for a pickleball coach in %2$s? Browse %1$s who train players in and around %2$s.', 'magstack-coaches' ), $count, $city ),
				sprintf( __( '%2$s has %1$s ready to help you improve your pickleball game, from first dinks to tournament play.', 'magstack-coaches' ), $count, $city ),
				sprintf( __( 'Book a pickleball lesson in %2$s with %1$s, all listed with transparent rates and open time slots.', 'magstack-coaches' ), $count, $city ),
			),
			'c1' . $city
		);
		$bits = array();
		if ( $rate_txt ) {
			$bits[] = sprintf( /* translators: %s: price range */ __( 'Lesson rates range from %s per hour.', 'magstack-coaches' ), $rate_txt );
		}
		if ( $top ) {
			$bits[] = sprintf( /* translators: %s: list of levels */ __( 'Most coaches here focus on %s players.', 'magstack-coaches' ), implode( ', ', $top ) );
		}
		if ( $ver ) {
			$bits[] = sprintf( /* translators: %d: verified coaches */ _n( '%d coach is Verified by our team.', '%d coaches are Verified by our team.', $ver, 'magstack-coaches' ), $ver );
		}
		if ( $courts ) {
			$bits[] = sprintf( /* translators: %s: venues */ __( 'Popular partner courts nearby include %s.', 'magstack-coaches' ), implode( ', ', $courts ) );
		}
		$intro = '<p>' . esc_html( $intro1 ) . '</p>' . ( $bits ? '<p>' . esc_html( implode( ' ', $bits ) ) . '</p>' : '' );

		$desc = sprintf(
			/* translators: 1: "3 coaches" 2: city 3: rate text */
			__( 'Book a pickleball lesson in %2$s: %1$s available. %3$sCompare profiles, check open time slots and reserve online.', 'magstack-coaches' ),
			$count,
			$city,
			$rate_txt ? sprintf( __( 'Rates from %s/hr. ', 'magstack-coaches' ), Helpers::money( $min ) ) : ''
		);
		return array(
			'title' => sprintf( /* translators: %s: city */ __( 'Pickleball Coaches in %s – Book Lessons Online', 'magstack-coaches' ), $city ),
			'h1'    => sprintf( __( 'Pickleball Coaches in %s', 'magstack-coaches' ), $city ),
			'desc'  => $desc,
			'intro' => $intro,
			'ids'   => $ids,
		);
	}

	private static function court_data( string $city ): array {
		$ids      = Facility::query( array( 'city' => $city ) );
		$n        = count( $ids );
		$partners = count( array_filter( $ids, array( Facility::class, 'is_partner' ) ) );
		$courts   = array_sum( array_map( static fn( $id ) => (int) Facility::meta( $id, 'courts', 0 ), $ids ) );
		$count    = sprintf( /* translators: %d: number of venues */ _n( '%d venue', '%d venues', $n, 'magstack-coaches' ), $n );
		$intro1   = self::pick(
			array(
				sprintf( /* translators: 1: "3 venues" 2: city */ __( 'Find pickleball courts in %2$s: %1$s with opening hours, surfaces and court fees in one place.', 'magstack-coaches' ), $count, $city ),
				sprintf( __( 'Where can you play pickleball in %2$s? Explore %1$s, then book a coach to train with.', 'magstack-coaches' ), $count, $city ),
				sprintf( __( 'Compare %1$s for pickleball in %2$s and pair your court time with a coach.', 'magstack-coaches' ), $count, $city ),
			),
			'f1' . $city
		);
		$bits = array();
		if ( $courts ) {
			$bits[] = sprintf( /* translators: %d: total courts */ _n( '%d court in total.', '%d courts in total.', $courts, 'magstack-coaches' ), $courts );
		}
		if ( $partners ) {
			$bits[] = sprintf( /* translators: %d: partner count */ _n( '%d partner facility is listed first.', '%d partner facilities are listed first.', $partners, 'magstack-coaches' ), $partners );
		}
		return array(
			'title' => sprintf( /* translators: %s: city */ __( 'Pickleball Courts in %s – Hours, Fees & Map', 'magstack-coaches' ), $city ),
			'h1'    => sprintf( __( 'Pickleball Courts in %s', 'magstack-coaches' ), $city ),
			'desc'  => sprintf( /* translators: 1: "3 venues" 2: city */ __( 'Explore %1$s for pickleball in %2$s with addresses, opening hours, surfaces and court fees. Book a coach at your favorite court.', 'magstack-coaches' ), $count, $city ),
			'intro' => '<p>' . esc_html( $intro1 ) . '</p>' . ( $bits ? '<p>' . esc_html( implode( ' ', $bits ) ) . '</p>' : '' ),
			'ids'   => $ids,
		);
	}

	// ---- Virtual page injection -------------------------------------------------------------

	public static function inject_post( $posts, $query ) {
		if ( ! $query->is_main_query() || is_admin() || ! $query->get( 'mc_loc' ) ) {
			return $posts;
		}
		$ctx = self::context();
		if ( ! $ctx || ! empty( $ctx['missing'] ) ) {
			$query->set_404();
			status_header( 404 );
			return array();
		}
		Assets::base();
		$post                 = new \WP_Post(
			(object) array(
				'ID'             => 0,
				'post_author'    => 0,
				'post_date'      => current_time( 'mysql' ),
				'post_date_gmt'  => current_time( 'mysql', true ),
				'post_content'   => self::render( $ctx ),
				'post_title'     => $ctx['h1'],
				'post_excerpt'   => $ctx['desc'],
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_name'      => 'pickleball-' . $ctx['type'] . ( $ctx['slug'] ? '-' . $ctx['slug'] : '' ),
				'post_type'      => 'page',
				'filter'         => 'raw',
			)
		);
		$query->is_page       = true;
		$query->is_singular   = true;
		$query->is_home       = false;
		$query->is_archive    = false;
		$query->is_category   = false;
		$query->is_404        = false;
		$query->queried_object    = $post;
		$query->queried_object_id = 0;
		status_header( 200 );
		return array( $post );
	}

	private static function render( array $ctx ): string {
		$type = $ctx['type'];
		$out  = '<div class="mc mc-loc">';
		if ( $ctx['index'] ) {
			$cities = Coach::cities()['coaches' === $type ? 'coaches' : 'facilities'];
			$out   .= '<p>' . esc_html( $ctx['desc'] ) . '</p><ul class="mc-loc__cities">';
			foreach ( $cities as $slug => $label ) {
				$out .= '<li><a class="mc-chip mc-chip--link" href="' . esc_url( self::city_url( $type, $slug ) ) . '"><span>' . esc_html( $label ) . '</span></a></li>';
			}
			$out .= '</ul>';
			return $out . '</div>';
		}
		$out .= wp_kses_post( $ctx['intro'] );
		if ( 'coaches' === $type ) {
			$out .= $ctx['ids'] ? Directory::cards_html( $ctx['ids'] ) : '<p>' . esc_html__( 'No coaches listed here yet.', 'magstack-coaches' ) . '</p>';
			$out .= '<p><a class="mc-btn" href="' . esc_url( Settings::page_url( 'coaches' ) ) . '">' . esc_html__( 'Browse all coaches', 'magstack-coaches' ) . '</a> <a class="mc-link" href="' . esc_url( self::city_url( 'courts', $ctx['slug'] ) ) . '">' . esc_html( sprintf( /* translators: %s: city */ __( 'Pickleball courts in %s', 'magstack-coaches' ), $ctx['city'] ) ) . '</a></p>';
		} else {
			$out .= '<div class="mc-grid">';
			foreach ( $ctx['ids'] as $id ) {
				$out .= Helpers::template( 'facility-card', array( 'f' => Facility::public_data( $id ) ) );
			}
			$out .= '</div>';
			$out .= '<p><a class="mc-btn" href="' . esc_url( Settings::page_url( 'map' ) ) . '">' . esc_html__( 'See the map', 'magstack-coaches' ) . '</a> <a class="mc-link" href="' . esc_url( self::city_url( 'coaches', $ctx['slug'] ) ) . '">' . esc_html( sprintf( __( 'Pickleball coaches in %s', 'magstack-coaches' ), $ctx['city'] ) ) . '</a></p>';
		}
		$extra = trim( (string) Settings::get( 'seo_intro_extra', '' ) );
		if ( $extra ) {
			$out .= '<div class="mc-loc__extra">' . wp_kses_post( wpautop( str_replace( '{city}', esc_html( $ctx['city'] ), $extra ) ) ) . '</div>';
		}
		$others = array_diff_key( Coach::cities()['coaches' === $type ? 'coaches' : 'facilities'], array( $ctx['slug'] => 1 ) );
		if ( $others ) {
			$out .= '<h2>' . esc_html( 'coaches' === $type ? __( 'Coaches in other cities', 'magstack-coaches' ) : __( 'Courts in other cities', 'magstack-coaches' ) ) . '</h2><ul class="mc-loc__cities">';
			foreach ( array_slice( $others, 0, 30, true ) as $slug => $label ) {
				$out .= '<li><a href="' . esc_url( self::city_url( $type, $slug ) ) . '">' . esc_html( $label ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		return $out . '</div>';
	}

	public static function locations_shortcode( $atts ): string {
		$a = shortcode_atts( array( 'type' => 'coaches' ), $atts, 'magstack_locations' );
		$t = 'courts' === $a['type'] ? 'courts' : 'coaches';
		Assets::base();
		$cities = Coach::cities()['coaches' === $t ? 'coaches' : 'facilities'];
		if ( ! $cities || ! self::enabled() ) {
			return '';
		}
		$out = '<ul class="mc mc-loc__cities">';
		foreach ( $cities as $slug => $label ) {
			$out .= '<li><a class="mc-chip mc-chip--link" href="' . esc_url( self::city_url( $t, $slug ) ) . '"><span>' . esc_html( $label ) . '</span></a></li>';
		}
		return $out . '</ul>';
	}

	// ---- SEO output -------------------------------------------------------------------------

	private static function current(): ?array {
		$ctx = get_query_var( 'mc_loc' ) ? self::context() : null;
		return ( $ctx && empty( $ctx['missing'] ) ) ? $ctx : null;
	}

	public static function title( $title ) {
		$ctx = self::current();
		return $ctx ? $ctx['title'] : $title;
	}

	public static function seo_title( $title ) {
		$ctx = self::current();
		return $ctx ? $ctx['title'] : $title;
	}

	public static function seo_desc( $desc ) {
		$ctx = self::current();
		return $ctx ? $ctx['desc'] : $desc;
	}

	public static function seo_canonical( $url ) {
		$ctx = self::current();
		return $ctx ? self::canonical( $ctx ) : $url;
	}

	private static function canonical( array $ctx ): string {
		return $ctx['slug'] ? self::city_url( $ctx['type'], $ctx['slug'] ) : self::index_url( $ctx['type'] );
	}

	public static function head(): void {
		$ctx = self::current();
		if ( $ctx ) {
			if ( ! self::seo_plugin_active() ) {
				echo '<meta name="description" content="' . esc_attr( $ctx['desc'] ) . '">' . "\n";
				echo '<link rel="canonical" href="' . esc_url( self::canonical( $ctx ) ) . '">' . "\n";
				echo '<meta property="og:title" content="' . esc_attr( $ctx['title'] ) . '">' . "\n";
				echo '<meta property="og:description" content="' . esc_attr( $ctx['desc'] ) . '">' . "\n";
			}
			self::print_schema( self::location_schema( $ctx ) );
			return;
		}
		// Person / LocalBusiness markup on single coach and facility pages.
		if ( is_singular( PostTypes::COACH ) && Coach::is_listable( get_queried_object_id() ) ) {
			self::print_schema( self::person_schema( get_queried_object_id() ) );
		} elseif ( is_singular( PostTypes::FACILITY ) ) {
			self::print_schema( self::business_schema( get_queried_object_id() ) );
		}
	}

	private static function print_schema( array $data ): void {
		if ( $data ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	private static function location_schema( array $ctx ): array {
		$items = array();
		$pos   = 1;
		foreach ( $ctx['ids'] as $id ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'item'     => 'coaches' === $ctx['type'] ? self::person_schema( $id, false ) : self::business_schema( $id, false ),
			);
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'CollectionPage',
			'name'       => $ctx['h1'],
			'description' => $ctx['desc'],
			'url'        => self::canonical( $ctx ),
			'about'      => $ctx['city'] ? array( '@type' => 'City', 'name' => $ctx['city'] ) : null,
			'mainEntity' => array(
				'@type'           => 'ItemList',
				'numberOfItems'   => count( $items ),
				'itemListElement' => $items,
			),
		);
	}

	private static function person_schema( int $id, bool $context = true ): array {
		$d   = Coach::public_data( $id );
		$out = array(
			'@type'       => 'Person',
			'name'        => $d['name'],
			'url'         => $d['url'],
			'jobTitle'    => __( 'Pickleball Coach', 'magstack-coaches' ),
			'description' => wp_trim_words( $d['bio'], 40 ),
			'address'     => array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $d['city'],
				'addressCountry'  => 'PH',
			),
			'makesOffer'  => array(
				'@type'              => 'Offer',
				'itemOffered'        => array( '@type' => 'Service', 'name' => __( 'Pickleball coaching session', 'magstack-coaches' ) ),
				'priceSpecification' => array(
					'@type'         => 'UnitPriceSpecification',
					'price'         => (string) $d['rate'],
					'priceCurrency' => (string) Settings::get( 'currency', 'PHP' ),
					'unitText'      => 'HUR',
				),
			),
		);
		if ( $d['photo'] ) {
			$out['image'] = $d['photo'];
		}
		if ( $context ) {
			$out = array( '@context' => 'https://schema.org' ) + $out;
		}
		return $out;
	}

	private static function business_schema( int $id, bool $context = true ): array {
		$f   = Facility::public_data( $id );
		$out = array(
			'@type'   => 'SportsActivityLocation',
			'name'    => $f['name'],
			'url'     => $f['url'],
			'address' => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $f['address'],
				'addressLocality' => $f['city'],
				'addressCountry'  => 'PH',
			),
		);
		if ( null !== $f['lat'] && null !== $f['lng'] ) {
			$out['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => $f['lat'], 'longitude' => $f['lng'] );
		}
		if ( $f['photo'] ) {
			$out['image'] = $f['photo'];
		}
		if ( $f['hours'] ) {
			$out['openingHours'] = $f['hours'];
		}
		if ( $f['contact'] && preg_match( '/^[\d\s+()\-]{7,}$/', $f['contact'] ) ) {
			$out['telephone'] = $f['contact'];
		}
		if ( $f['courts'] ) {
			$out['amenityFeature'] = array( '@type' => 'LocationFeatureSpecification', 'name' => __( 'Pickleball courts', 'magstack-coaches' ), 'value' => $f['courts'] );
		}
		if ( $context ) {
			$out = array( '@context' => 'https://schema.org' ) + $out;
		}
		return $out;
	}
}
