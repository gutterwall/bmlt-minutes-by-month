<?php
/**
 * Plugin Name: BMLT Minutes by Month
 * Description: Adds the [bmlt_minutes_by_month] shortcode: BMLT Minutes listed by committee, then by year and month.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: bmlt-minutes
 * Author: Richard Schellenberg
 * License: GPL-2.0-or-later
 * Text Domain: bmlt-minutes-by-month
 *
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [bmlt_minutes_by_month] shortcode.
 *
 * Minutes are grouped by committee, then by year (a collapsible section), then by month. The month is the one the
 * minutes were posted in (the post's publish date), or with date="meeting" the meeting date. Minutes filed under
 * several committees are listed under each of them.
 */
final class BMLT_Minutes_By_Month {

	public const VERSION   = '1.0.0';
	public const SHORTCODE = 'bmlt_minutes_by_month';
	public const STYLE     = 'bmlt-minutes-by-month';

	/** Group key for minutes without a committee (term ids are never 0). */
	private const NO_COMMITTEE = 0;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_style( self::STYLE, plugins_url( 'css/by-month.css', __FILE__ ), array(), self::VERSION );
	}

	/**
	 * The shortcode attributes, checked. Anything unrecognised falls back to the default.
	 *
	 * @param mixed $atts Attributes as WordPress passes them ('' when there are none).
	 * @return array{committees: string[], date: string, year: int, order: string, open: string, level: int, show_date: bool}
	 */
	public static function parse_atts( $atts ): array {
		$a = shortcode_atts(
			array(
				'committee'     => '',
				'date'          => 'posted',
				'year'          => '',
				'order'         => (string) get_option( 'bmlt_minutes_sort_order', 'desc' ),
				'open'          => 'latest',
				'heading_level' => '3',
				'show_date'     => 'true',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$year  = trim( (string) $a['year'] );
		$open  = strtolower( trim( (string) $a['open'] ) );
		$level = (int) $a['heading_level'];

		return array(
			'committees' => array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', (string) $a['committee'] ) ) ) ) ),
			'date'       => 'meeting' === strtolower( trim( (string) $a['date'] ) ) ? 'meeting' : 'posted',
			'year'       => 1 === preg_match( '/^\d{4}$/', $year ) ? (int) $year : 0,
			'order'      => 'asc' === strtolower( trim( (string) $a['order'] ) ) ? 'asc' : 'desc',
			'open'       => in_array( $open, array( 'latest', 'all', 'none' ), true ) ? $open : 'latest',
			// The committee heading takes this level, the year one below it and the month one below that.
			'level'      => $level >= 2 && $level <= 4 ? $level : 3,
			'show_date'  => false !== filter_var( $a['show_date'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
		);
	}

	/**
	 * @param mixed $atts Shortcode attributes.
	 */
	public static function render_shortcode( $atts ): string {
		if ( ! class_exists( 'BMLT_Minutes' ) ) {
			// Only people who can fix it see why the list is missing.
			if ( current_user_can( 'activate_plugins' ) ) {
				return '<p class="bmbm bmbm--missing">' . esc_html__( 'The minutes list needs the BMLT Minutes plugin, which is not active.', 'bmlt-minutes-by-month' ) . '</p>';
			}
			return '';
		}

		$args = self::parse_atts( $atts );
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( self::STYLE );
		// BMLT Minutes' own list styles (and dashicons) for the items, so both shortcodes look alike.
		wp_enqueue_style( 'bmlt-minutes' );

		$groups = self::group( self::query( $args ), $args );
		if ( array() === $groups ) {
			return '<div class="bmlt-minutes bmbm bmlt-minutes--empty">' . esc_html__( 'No minutes published yet.', 'bmlt-minutes-by-month' ) . '</div>';
		}
		return self::render( $groups, $args );
	}

	/**
	 * Committee term ids the list is limited to (with their sub-committees), or null for all committees. An empty
	 * array means the attribute named only committees that do not exist, so nothing is shown.
	 *
	 * @param string[] $slugs Committee slugs.
	 * @return int[]|null
	 */
	private static function allowed_terms( array $slugs ): ?array {
		if ( array() === $slugs ) {
			return null;
		}
		$ids = array();
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, BMLT_Minutes::TAX_COMMITTEE );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$ids[] = (int) $term->term_id;
			$kids  = get_term_children( (int) $term->term_id, BMLT_Minutes::TAX_COMMITTEE );
			if ( is_array( $kids ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $kids ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Published minutes for the attributes.
	 *
	 * @param array{committees: string[], date: string, year: int} $args Parsed attributes.
	 * @return WP_Post[]
	 */
	public static function query( array $args ): array {
		$q = array(
			'post_type'           => BMLT_Minutes::CPT,
			'post_status'         => 'publish',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'date',
			'order'               => 'DESC',
		);
		$allowed = self::allowed_terms( $args['committees'] );
		if ( null !== $allowed ) {
			if ( array() === $allowed ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering minutes by committee is the point of the attribute.
			$q['tax_query'] = array(
				array(
					'taxonomy'         => BMLT_Minutes::TAX_COMMITTEE,
					'field'            => 'term_id',
					'terms'            => $allowed,
					'include_children' => false, // Already in $allowed.
				),
			);
		}
		if ( $args['year'] > 0 && 'posted' === $args['date'] ) {
			$q['date_query'] = array( array( 'year' => $args['year'] ) );
		}
		$query = new WP_Query( $q );
		return array_values( array_filter( $query->posts, static fn( $p ) => $p instanceof WP_Post ) );
	}

	/**
	 * The date a post is filed under, as Y-m-d H:i:s: when it was posted, or its meeting date (when it has a valid
	 * one) at the posting time of day, so minutes of the same meeting keep the order they were posted in.
	 */
	public static function filing_date( WP_Post $post, string $date ): string {
		$posted = (string) $post->post_date;
		if ( 'meeting' !== $date ) {
			return $posted;
		}
		$meeting = (string) get_post_meta( $post->ID, BMLT_Minutes::META_DATE, true );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $meeting, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $posted;
		}
		return $meeting . substr( $posted, 10 );
	}

	/**
	 * Groups posts by committee, year and month, each level sorted.
	 *
	 * @param WP_Post[] $posts Published minutes.
	 * @param array{committees: string[], date: string, year: int, order: string} $args Parsed attributes.
	 * @return list<array{id: int, label: string, years: array<string, array<string, WP_Post[]>>}>
	 */
	public static function group( array $posts, array $args ): array {
		$allowed = self::allowed_terms( $args['committees'] );
		$tree    = array(); // term id => year => month => list of [filing date, post].
		$terms   = array(); // term id => WP_Term.

		foreach ( $posts as $post ) {
			$when = self::filing_date( $post, $args['date'] );
			$year = substr( $when, 0, 4 );
			if ( $args['year'] > 0 && (int) $year !== $args['year'] ) {
				continue;
			}
			$month = substr( $when, 5, 2 );

			$post_terms = get_the_terms( $post->ID, BMLT_Minutes::TAX_COMMITTEE );
			$keys       = array();
			if ( is_array( $post_terms ) ) {
				foreach ( $post_terms as $term ) {
					if ( null === $allowed || in_array( (int) $term->term_id, $allowed, true ) ) {
						$keys[]                         = (int) $term->term_id;
						$terms[ (int) $term->term_id ] = $term;
					}
				}
			}
			if ( array() === $keys ) {
				if ( null !== $allowed ) {
					continue;
				}
				$keys[] = self::NO_COMMITTEE;
			}
			foreach ( $keys as $key ) {
				$tree[ $key ][ $year ][ $month ][] = array( $when, $post );
			}
		}

		$labels = self::labels( $terms );
		$groups = array();
		foreach ( $tree as $key => $years ) {
			self::sort_keys( $years, $args['order'] );
			foreach ( $years as $y => $months ) {
				self::sort_keys( $months, $args['order'] );
				foreach ( $months as $m => $items ) {
					usort(
						$items,
						static function ( array $a, array $b ) use ( $args ): int {
							$cmp = 'asc' === $args['order'] ? strcmp( $a[0], $b[0] ) : strcmp( $b[0], $a[0] );
							return 0 !== $cmp ? $cmp : strnatcasecmp( (string) $a[1]->post_title, (string) $b[1]->post_title );
						}
					);
					$months[ $m ] = array_column( $items, 1 );
				}
				$years[ $y ] = $months;
			}
			$groups[] = array(
				'id'    => (int) $key,
				'label' => self::NO_COMMITTEE === $key ? __( 'Uncategorized', 'bmlt-minutes-by-month' ) : $labels[ $key ],
				'years' => $years,
			);
		}

		// Committees alphabetically, minutes without a committee last.
		usort(
			$groups,
			static function ( array $a, array $b ): int {
				if ( ( self::NO_COMMITTEE === $a['id'] ) !== ( self::NO_COMMITTEE === $b['id'] ) ) {
					return self::NO_COMMITTEE === $a['id'] ? 1 : -1;
				}
				return strnatcasecmp( $a['label'], $b['label'] );
			}
		);
		return $groups;
	}

	/**
	 * Sorts year or month keys ("2026", "09") newest first or oldest first, keeping them strings.
	 *
	 * @param array<string, mixed> $list  Keyed by year or month.
	 * @param string               $order 'asc' or 'desc'.
	 */
	private static function sort_keys( array &$list, string $order ): void {
		uksort(
			$list,
			static fn( $a, $b ): int => 'asc' === $order ? strcmp( (string) $a, (string) $b ) : strcmp( (string) $b, (string) $a )
		);
	}

	/**
	 * Committee headings: the committee's name, or, where two listed committees share a name (two areas' "Public
	 * Relations"), its full path ("Richmond Area / Public Relations").
	 *
	 * @param array<int, WP_Term> $terms Committees being listed.
	 * @return array<int, string>
	 */
	private static function labels( array $terms ): array {
		$names = array();
		foreach ( $terms as $id => $term ) {
			$names[ $id ] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
		}
		$counts = array_count_values( array_map( 'strtolower', $names ) );
		$labels = array();
		foreach ( $names as $id => $name ) {
			if ( $counts[ strtolower( $name ) ] < 2 ) {
				$labels[ $id ] = $name;
				continue;
			}
			$path = array( $name );
			foreach ( get_ancestors( $id, BMLT_Minutes::TAX_COMMITTEE, 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( (int) $ancestor_id, BMLT_Minutes::TAX_COMMITTEE );
				if ( $ancestor instanceof WP_Term ) {
					array_unshift( $path, html_entity_decode( (string) $ancestor->name, ENT_QUOTES, 'UTF-8' ) );
				}
			}
			$labels[ $id ] = implode( ' / ', $path );
		}
		return $labels;
	}

	/**
	 * @param list<array{id: int, label: string, years: array<string, array<string, WP_Post[]>>}> $groups Grouped minutes.
	 * @param array{open: string, level: int, show_date: bool} $args Parsed attributes.
	 */
	private static function render( array $groups, array $args ): string {
		global $wp_locale;
		$h1  = 'h' . $args['level'];
		$h2  = 'h' . ( $args['level'] + 1 );
		$h3  = 'h' . ( $args['level'] + 2 );
		$out = '<div class="bmlt-minutes bmbm">';
		foreach ( $groups as $group ) {
			$slug = self::NO_COMMITTEE === $group['id'] ? 'none' : (string) $group['id'];
			$out .= '<section class="bmbm__committee bmbm__committee--' . esc_attr( $slug ) . '">';
			$out .= '<' . $h1 . ' class="bmlt-minutes__group bmbm__committee-title">' . esc_html( $group['label'] ) . '</' . $h1 . '>';
			$first = true;
			foreach ( $group['years'] as $year => $months ) {
				$count = array_sum( array_map( 'count', $months ) );
				$open  = 'all' === $args['open'] || ( 'latest' === $args['open'] && $first );
				$first = false;
				$out  .= '<details class="bmbm__year"' . ( $open ? ' open' : '' ) . '>';
				$out  .= '<summary class="bmbm__year-summary"><' . $h2 . ' class="bmbm__year-title">' . esc_html( (string) $year ) . '</' . $h2 . '>';
				$out  .= ' <span class="bmbm__count">' . esc_html(
					sprintf(
						/* translators: %s: number of documents in that year */
						_n( '%s document', '%s documents', $count, 'bmlt-minutes-by-month' ),
						number_format_i18n( $count )
					)
				) . '</span></summary>';
				foreach ( $months as $month => $posts ) {
					$out .= '<' . $h3 . ' class="bmbm__month">' . esc_html( (string) $wp_locale->get_month( (string) $month ) ) . '</' . $h3 . '>';
					$out .= '<ul class="bmlt-minutes__list bmbm__list">';
					foreach ( $posts as $post ) {
						$out .= self::render_item( $post, $args['show_date'] );
					}
					$out .= '</ul>';
				}
				$out .= '</details>';
			}
			$out .= '</section>';
		}
		return $out . '</div>';
	}

	/**
	 * One document, marked up like BMLT Minutes' own list. Password-protected minutes link to their page (where
	 * WordPress asks for the password) and never reveal the document's address or type.
	 */
	private static function render_item( WP_Post $post, bool $show_date ): string {
		$locked = post_password_required( $post );
		if ( $locked ) {
			$href     = (string) get_permalink( $post );
			$icon     = 'dashicons-lock';
			$badge    = __( 'Protected', 'bmlt-minutes-by-month' );
			$external = false;
		} else {
			[ $url, , $type ] = BMLT_Minutes::resolve_document( $post->ID );
			$href             = '' !== $url ? $url : (string) get_permalink( $post );
			$icon             = self::dashicon( $type );
			$badge            = '' !== $type && 'link' !== $type ? strtoupper( $type ) : '';
			$external         = '' !== $url;
		}

		$date = '';
		if ( $show_date ) {
			$meeting = (string) get_post_meta( $post->ID, BMLT_Minutes::META_DATE, true );
			$date    = '' !== $meeting ? (string) mysql2date( (string) get_option( 'date_format', 'F j, Y' ), $meeting, true ) : '';
		}

		$out  = '<li class="bmlt-minutes__item' . ( $locked ? ' bmlt-minutes__item--locked' : '' ) . '">';
		$out .= '<a class="bmlt-minutes__link" href="' . esc_url( $href ) . '"' . ( $external ? ' target="_blank" rel="noopener"' : '' ) . '>';
		$out .= '<span class="bmlt-minutes__icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		$out .= '<span class="bmlt-minutes__title">' . esc_html( get_the_title( $post ) ) . '</span>';
		if ( '' !== $badge ) {
			$out .= '<span class="bmlt-minutes__type' . ( $locked ? ' bmlt-minutes__type--locked' : '' ) . '">' . esc_html( $badge ) . '</span>';
		}
		if ( '' !== $date ) {
			$out .= '<span class="bmlt-minutes__date">' . esc_html( $date ) . '</span>';
		}
		return $out . '</a></li>';
	}

	/**
	 * The icon BMLT Minutes shows for a document type.
	 */
	private static function dashicon( string $type ): string {
		switch ( $type ) {
			case 'pdf':
				return 'dashicons-pdf';
			case 'doc':
			case 'docx':
			case 'odt':
			case 'rtf':
				return 'dashicons-media-document';
			case 'xls':
			case 'xlsx':
			case 'ods':
			case 'csv':
				return 'dashicons-media-spreadsheet';
			case 'ppt':
			case 'pptx':
			case 'odp':
				return 'dashicons-media-interactive';
			case 'txt':
				return 'dashicons-media-text';
			case 'google':
				return 'dashicons-google';
			default:
				return 'dashicons-admin-links';
		}
	}
}

BMLT_Minutes_By_Month::init();
