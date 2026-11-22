<?php
/**
 * The [bmlt_minutes_latest] shortcode: the newest minutes of a committee, shown whole in the page.
 *
 * For a page such as "Area Policy" or "Last month's minutes": it always shows the committee's newest document in
 * full, so nobody has to download it to read it, and the page updates itself when new minutes are posted.
 *
 * - PDFs are drawn page by page in the browser with PDF.js (js/latest.js), with their text selectable and searchable
 *   and their links working, on phones too.
 * - Word, OpenDocument, Excel, CSV and text files are turned into HTML on the server (BMLT_Minutes_By_Month_Document_HTML).
 * - Minutes written in the post itself show their text.
 * - Anything else (Google Docs and other web pages, old .doc/.xls/.ppt files) gets a link to open it.
 * - Password-protected minutes show WordPress's password form; once it is entered they show like any other.
 *
 * @version 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BMLT_Minutes_By_Month_Latest {

	public const SHORTCODE = 'bmlt_minutes_latest';
	public const STYLE     = 'bmlt-minutes-by-month-latest';
	public const SCRIPT    = 'bmlt-minutes-by-month-latest';

	/** Shortcodes being rendered, so minutes whose text contains this shortcode cannot loop. */
	private static int $depth = 0;

	public static function init(): void {
		add_action( 'init', array( self::class, 'register_shortcode' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	/**
	 * Adds the shortcode, unless BMLT Minutes itself already has [bmlt_minutes_latest] (it was proposed to BMLT
	 * Minutes; once a version of it has it, that one is used).
	 */
	public static function register_shortcode(): void {
		if ( ! shortcode_exists( self::SHORTCODE ) ) {
			add_shortcode( self::SHORTCODE, array( self::class, 'render_shortcode' ) );
		}
	}

	public static function register_assets(): void {
		wp_register_style( self::STYLE, plugins_url( 'css/latest.css', BMLT_Minutes_By_Month::FILE ), array(), BMLT_Minutes_By_Month::VERSION );
		wp_register_script( self::SCRIPT, plugins_url( 'js/latest.js', BMLT_Minutes_By_Month::FILE ), array(), BMLT_Minutes_By_Month::VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * The shortcode attributes, checked. Anything unrecognised falls back to the default.
	 *
	 * @param mixed $atts Attributes as WordPress passes them ('' when there are none).
	 * @return array{committees: string[], date: string, year: int, level: int, show_title: bool, show_date: bool, download: bool}
	 */
	public static function parse_atts( $atts ): array {
		$a     = shortcode_atts(
			array(
				'committee'     => '',
				'date'          => 'posted',
				'heading_level' => '2',
				'show_title'    => 'true',
				'show_date'     => 'true',
				'download'      => 'true',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$level = (int) $a['heading_level'];
		$yes   = static fn( $v ): bool => false !== filter_var( $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return array(
			'committees' => array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', (string) $a['committee'] ) ) ) ) ),
			'date'       => 'meeting' === strtolower( trim( (string) $a['date'] ) ) ? 'meeting' : 'posted',
			'year'       => 0,
			// The minutes' title takes this level and the document's own headings the levels below it.
			'level'      => $level >= 2 && $level <= 5 ? $level : 2,
			'show_title' => $yes( $a['show_title'] ),
			'show_date'  => $yes( $a['show_date'] ),
			'download'   => $yes( $a['download'] ),
		);
	}

	/**
	 * The newest published minutes for the attributes (by posting date, or meeting date with date="meeting"), or null.
	 *
	 * @param array{committees: string[], date: string, year: int} $args Parsed attributes.
	 */
	public static function latest( array $args ): ?WP_Post {
		$best = null;
		$key  = '';
		foreach ( BMLT_Minutes_By_Month::query( $args ) as $post ) {
			// Ties (the same date and time) go to the minutes added last.
			$k = BMLT_Minutes_By_Month::filing_date( $post, $args['date'] ) . sprintf( '%020d', $post->ID );
			if ( $k > $key ) {
				$best = $post;
				$key  = $k;
			}
		}
		return $best;
	}

	/**
	 * @param mixed $atts Shortcode attributes.
	 */
	public static function render_shortcode( $atts ): string {
		if ( ! class_exists( 'BMLT_Minutes' ) ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				return '<p class="bmbm bmbm--missing">' . esc_html__( 'The minutes need the BMLT Minutes plugin, which is not active.', 'bmlt-minutes-by-month' ) . '</p>';
			}
			return '';
		}
		if ( self::$depth > 0 ) {
			return ''; // Minutes that show themselves.
		}
		$args = self::parse_atts( $atts );
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( self::STYLE );
		wp_enqueue_style( 'bmlt-minutes' );

		$post = self::latest( $args );
		if ( null === $post ) {
			return '<div class="bmlt-minutes bmbm-latest bmlt-minutes--empty">' . esc_html__( 'No minutes published yet.', 'bmlt-minutes-by-month' ) . '</div>';
		}
		++self::$depth;
		try {
			return self::render( $post, $args );
		} finally {
			--self::$depth;
		}
	}

	/**
	 * @param array{level: int, show_title: bool, show_date: bool, download: bool} $args Parsed attributes.
	 */
	private static function render( WP_Post $post, array $args ): string {
		$locked = post_password_required( $post );
		$title  = get_the_title( $post );
		$h      = 'h' . $args['level'];
		$out    = '<article class="bmlt-minutes bmbm-latest" id="' . esc_attr( 'bmbm-latest-' . $post->ID ) . '">';

		if ( $args['show_title'] ) {
			$out .= '<' . $h . ' class="bmbm-latest__title">' . esc_html( $title ) . '</' . $h . '>';
		}
		[ $url, , $type ] = $locked ? array( '', '', '' ) : BMLT_Minutes::resolve_document( $post->ID );
		$url              = '' !== $url ? esc_url( $url ) : '';

		$meta = array();
		if ( $args['show_date'] ) {
			$meeting = (string) get_post_meta( $post->ID, BMLT_Minutes::META_DATE, true );
			$date    = '' !== $meeting ? (string) mysql2date( (string) get_option( 'date_format', 'F j, Y' ), $meeting, true ) : '';
			if ( '' !== $date ) {
				$meta[] = '<span class="bmbm-latest__date">' . esc_html( $date ) . '</span>';
			}
		}
		if ( $args['download'] && '' !== $url ) {
			$meta[] = self::open_link( $url, $type );
		}
		if ( array() !== $meta ) {
			$out .= '<p class="bmbm-latest__meta">' . implode( ' ', $meta ) . '</p>';
		}

		if ( $locked ) {
			// WordPress's own form: after the password, this page shows the minutes.
			return $out . '<div class="bmbm-latest__locked">' . get_the_password_form( $post ) . '</div></article>';
		}

		$text = trim( (string) $post->post_content );
		if ( '' !== $text ) {
			$out .= '<div class="bmbm-latest__text">' . self::post_text( $post ) . '</div>';
		}
		if ( '' !== $url ) {
			$out .= self::document( $post, $url, $type, $args['level'] + 1 );
		} elseif ( '' === $text ) {
			$out .= '<p class="bmbm-latest__none">' . esc_html__( 'These minutes have no document.', 'bmlt-minutes-by-month' ) . '</p>';
		}
		return $out . '</article>';
	}

	/**
	 * A link to download the file, or to open a web page (a Google Doc, say), labelled with its type.
	 */
	private static function open_link( string $url, string $type ): string {
		if ( in_array( $type, BMLT_Minutes::ALLOWED_EXTENSIONS, true ) ) {
			return '<a class="bmbm-latest__download" href="' . $url . '" download>' . esc_html(
				sprintf(
					/* translators: %s: file type, such as PDF or DOCX */
					__( 'Download (%s)', 'bmlt-minutes-by-month' ),
					strtoupper( $type )
				)
			) . '</a>';
		}
		return '<a class="bmbm-latest__download" href="' . $url . '" target="_blank" rel="noopener">' . esc_html__( 'Open the document', 'bmlt-minutes-by-month' ) . '</a>';
	}

	/**
	 * The minutes' own text, as WordPress shows a post's content.
	 */
	private static function post_text( WP_Post $post ): string {
		global $wp_query;
		$saved = $GLOBALS['post'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the_content filters read the global post; restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$html = (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a post shows its content.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring it.
		$GLOBALS['post'] = $saved;
		if ( $saved instanceof WP_Post ) {
			setup_postdata( $saved );
		} elseif ( $wp_query instanceof WP_Query ) {
			$wp_query->reset_postdata();
		}
		return $html;
	}

	/**
	 * The document, whole: a PDF viewer, converted HTML, or a link when it cannot be shown in the page.
	 */
	private static function document( WP_Post $post, string $url, string $type, int $top ): string {
		if ( 'pdf' === $type ) {
			wp_enqueue_script( self::SCRIPT );
			$base = plugins_url( 'pdfjs/', BMLT_Minutes_By_Month::FILE );
			$out  = '<div class="bmbm-latest__pdf" data-bmbm-pdf="' . $url . '" data-bmbm-pdfjs="' . esc_url( $base ) . '" data-bmbm-ver="' . esc_attr( BMLT_Minutes_By_Month::VERSION ) . '"';
			$out .= ' data-bmbm-loading="' . esc_attr__( 'Loading the document…', 'bmlt-minutes-by-month' ) . '"';
			$out .= ' data-bmbm-error="' . esc_attr__( 'This PDF could not be shown here.', 'bmlt-minutes-by-month' ) . '"';
			/* translators: 1: page number, 2: number of pages */
			$out .= ' data-bmbm-page="' . esc_attr__( 'Page %1$s of %2$s', 'bmlt-minutes-by-month' ) . '">';
			// Without JavaScript: the browser's own PDF viewer.
			$out .= '<noscript><object class="bmbm-latest__embed" data="' . $url . '" type="application/pdf" aria-label="' . esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ) . '"><a href="' . $url . '">' . esc_html( get_the_title( $post ) ) . '</a></object></noscript>';
			return $out . '</div>';
		}

		$attachment = (int) get_post_meta( $post->ID, BMLT_Minutes::META_ATTACHMENT, true );
		$path       = $attachment ? (string) get_attached_file( $attachment ) : '';
		if ( '' !== $path && in_array( $type, BMLT_Minutes_By_Month_Document_HTML::TYPES, true ) ) {
			$html = BMLT_Minutes_By_Month_Document_HTML::cached( $path, $type, $top );
			if ( null !== $html ) {
				return '<div class="bmbm-latest__document bmbm-doc bmbm-doc--' . esc_attr( $type ) . '">' . $html . '</div>';
			}
		}

		$label = in_array( $type, BMLT_Minutes::ALLOWED_EXTENSIONS, true )
			? __( 'This document cannot be shown in the page. Download it to read it.', 'bmlt-minutes-by-month' )
			: __( 'This document is on another website.', 'bmlt-minutes-by-month' );
		return '<p class="bmbm-latest__elsewhere">' . esc_html( $label ) . ' ' . self::open_link( $url, $type ) . '</p>';
	}
}
