<?php
/**
 * Small text helpers shared by the features.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers.
 */
class Text {

	/**
	 * Allowed tags in AI-written descriptions.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		return array(
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	/**
	 * Cleans description HTML.
	 *
	 * @param string $html HTML.
	 * @param int    $max  Max length.
	 * @return string
	 */
	public static function html( $html, $max = 6000 ) {
		$html = wp_kses( (string) $html, self::allowed_html() );
		return trim( mb_substr( $html, 0, $max ) );
	}

	/**
	 * Plain single-line string.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function line( $value, $max = 200 ) {
		return trim( mb_substr( sanitize_text_field( (string) $value ), 0, $max ) );
	}

	/**
	 * Plain multi-line string.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function block( $value, $max = 3000 ) {
		return trim( mb_substr( sanitize_textarea_field( (string) $value ), 0, $max ) );
	}

	/**
	 * List of short strings.
	 *
	 * @param mixed $values Values.
	 * @param int   $count  Max items.
	 * @param int   $max    Max length each.
	 * @return string[]
	 */
	public static function lines( $values, $count = 10, $max = 60 ) {
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = self::line( is_scalar( $value ) ? $value : '', $max );
			if ( '' !== $value && ! in_array( mb_strtolower( $value ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $value;
			}
			if ( count( $out ) >= $count ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Output language for AI copy.
	 *
	 * @return string
	 */
	public static function language() {
		$lang = (string) Settings::get( 'language', '' );
		if ( '' !== $lang ) {
			return $lang;
		}
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		return 'the language of the locale ' . $locale;
	}

	/**
	 * Tone (request override or setting).
	 *
	 * @param string $tone Requested tone.
	 * @return string
	 */
	public static function tone( $tone = '' ) {
		$allowed = array( 'friendly', 'professional', 'enthusiastic', 'concise', 'luxury' );
		if ( in_array( $tone, $allowed, true ) ) {
			return $tone;
		}
		$setting = (string) Settings::get( 'tone', 'friendly' );
		return in_array( $setting, $allowed, true ) ? $setting : 'friendly';
	}

	/**
	 * Lowercase words of 3+ letters, minus stop words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function words( $text ) {
		$stop  = array( 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'your', 'you', 'are', 'our', 'set', 'new', 'pack', 'size' );
		$text  = mb_strtolower( wp_strip_all_tags( (string) $text ) );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			if ( mb_strlen( $p ) >= 3 && ! in_array( $p, $stop, true ) ) {
				$out[ $p ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Jaccard similarity of two word lists.
	 *
	 * @param string[] $a Words.
	 * @param string[] $b Words.
	 * @return float
	 */
	public static function similarity( array $a, array $b ) {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$inter = count( array_intersect( $a, $b ) );
		$union = count( array_unique( array_merge( $a, $b ) ) );
		return $union ? $inter / $union : 0.0;
	}

	/**
	 * First name only (privacy: no surnames or emails go to the AI).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function first_name( $name ) {
		$name  = trim( (string) $name );
		$parts = preg_split( '/\s+/', $name );
		$first = $parts ? (string) $parts[0] : '';
		return false !== strpos( $first, '@' ) ? '' : self::line( $first, 40 );
	}

	/**
	 * Money string without HTML entities.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format_i18n( (float) $amount, 2 );
	}
}
