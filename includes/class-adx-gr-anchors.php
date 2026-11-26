<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The #hash values that point at one card on the library page, e.g.
 * https://example.com/sample-library/#door-frame-with-double-sidelight-1000-series
 *
 * Each card answers to a few slugs (see ADX_GR_Frontend::item_anchors()):
 * its name plus its series, the resource's own slug, and its full title.
 * library.js applies the same normalisation to the hash it reads, so what a
 * person types, pastes or is sent always lines up with what the card
 * advertises, whatever the capitals, underscores or percent-encoding.
 *
 * Keep slug() and normaliseHash() in assets/js/library.js in step.
 */
class ADX_GR_Anchors {

	/**
	 * "Door Frame with Double Sidelight 1000 Series" becomes
	 * "door-frame-with-double-sidelight-1000-series".
	 */
	public static function slug( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' );
	}

	/**
	 * Several candidate texts as one space-separated, de-duplicated list of
	 * slugs, ready for a data attribute. Empty candidates are skipped.
	 *
	 * @param array<int, string> $candidates
	 */
	public static function join( array $candidates ) {
		$slugs = array();

		foreach ( $candidates as $candidate ) {
			$slug = self::slug( $candidate );

			if ( '' !== $slug && ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		return implode( ' ', $slugs );
	}
}
