<?php
/**
 * Turns a raw custom field value into a list of tracking intents.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses the free-text value typed into the mobile app.
 *
 * This class is deliberately free of WordPress dependencies: it is pure input
 * normalisation, which makes it cheap to test exhaustively. Deciding whether a
 * carrier is usable is the sync layer's job, not the parser's.
 *
 * @since 1.0.0
 */
class Trackbridge_Value_Parser {

	/**
	 * Upper bound on tracking numbers extracted from one value.
	 *
	 * Guards against a stray paste turning into hundreds of tracking rows.
	 *
	 * @var int
	 */
	const MAX_PARCELS = 20;

	/**
	 * Shortest string accepted as a tracking number.
	 *
	 * @var int
	 */
	const MIN_LENGTH = 3;

	/**
	 * Longest string accepted as a tracking number.
	 *
	 * @var int
	 */
	const MAX_LENGTH = 100;

	/**
	 * Descriptive labels people type in front of a number.
	 *
	 * Requires an explicit separator so a number such as `TRACK001` is not
	 * mistaken for the label `track`.
	 *
	 * @var string
	 */
	const LABEL_PATTERN = '/^(?:tracking\s*number|tracking|track|number|seguimiento|env[ií]o)\s*[:#.\x{2013}\x{2014}-]+\s*/iu';

	/**
	 * Short labels, which need a colon or dot to avoid eating real prefixes.
	 *
	 * Without this restriction a number like `NO-12345` would lose its prefix.
	 *
	 * @var string
	 */
	const SHORT_LABEL_PATTERN = '/^(?:nr|no)\s*[:.]+\s*/iu';

	/**
	 * Matches a `Carrier: number` prefix.
	 *
	 * @var string
	 */
	const CARRIER_PREFIX_PATTERN = '/^([\p{L}\p{N} _&.-]{2,40}?)\s*:\s*(.+)$/us';

	/**
	 * Characters allowed inside a tracking number.
	 *
	 * @var string
	 */
	const DISALLOWED_PATTERN = '/[^A-Za-z0-9._\/-]/';

	/**
	 * Parses a raw meta value into tracking intents.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw     Raw meta value as stored on the order.
	 * @param array $options {
	 *     Optional. Parsing options.
	 *
	 *     @type string $default_carrier        Carrier used when none is detected. Default ''.
	 *     @type bool   $allow_carrier_override Whether a recognised prefix may change the carrier. Default false.
	 *     @type bool   $split_multiple         Whether commas and semicolons separate parcels. Default true.
	 *     @type array  $known_carriers         Map of carrier slug => display label. Default array().
	 * }
	 * @return array List of arrays with `number` and `carrier` keys.
	 */
	public static function parse( $raw, array $options = array() ) {
		$options = array_merge(
			array(
				'default_carrier'        => '',
				'allow_carrier_override' => false,
				'split_multiple'         => true,
				'known_carriers'         => array(),
			),
			$options
		);

		if ( ! is_scalar( $raw ) ) {
			return array();
		}

		$segments = self::split( (string) $raw, (bool) $options['split_multiple'] );
		$intents  = array();
		$seen     = array();

		foreach ( $segments as $segment ) {
			$intent = self::parse_segment( $segment, $options );

			if ( null === $intent ) {
				continue;
			}

			$key = strtolower( $intent['carrier'] ) . '|' . strtolower( $intent['number'] );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$intents[]    = $intent;

			if ( count( $intents ) >= self::MAX_PARCELS ) {
				break;
			}
		}

		return $intents;
	}

	/**
	 * Splits a raw value into candidate segments.
	 *
	 * Newlines always separate parcels because a newline can never be part of a
	 * tracking number. Commas and semicolons are only separators when the shop
	 * has opted into multi-parcel values.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw            Raw value.
	 * @param bool   $split_multiple Whether commas and semicolons separate parcels.
	 * @return array List of trimmed, non-empty segments.
	 */
	private static function split( $raw, $split_multiple ) {
		$segments = preg_split( '/[\r\n]+/', $raw );

		if ( ! is_array( $segments ) ) {
			return array();
		}

		if ( $split_multiple ) {
			$expanded = array();

			foreach ( $segments as $segment ) {
				$parts = preg_split( '/[,;]+/', $segment );

				if ( is_array( $parts ) ) {
					$expanded = array_merge( $expanded, $parts );
				}
			}

			$segments = $expanded;
		}

		$segments = array_map( array( __CLASS__, 'trim_whitespace' ), $segments );

		return array_values( array_filter( $segments, 'strlen' ) );
	}

	/**
	 * Trims ASCII and UTF-8 whitespace, including non-breaking spaces.
	 *
	 * The mobile keyboard and copy-paste from carrier sites both introduce
	 * non-breaking spaces, which a plain trim() would leave behind.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to trim.
	 * @return string
	 */
	private static function trim_whitespace( $value ) {
		$trimmed = preg_replace( '/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', (string) $value );

		return null === $trimmed ? trim( (string) $value ) : $trimmed;
	}

	/**
	 * Parses a single segment into a tracking intent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $segment Candidate segment.
	 * @param array  $options Parsing options, already merged with defaults.
	 * @return array|null Intent array, or null when the segment yields nothing usable.
	 */
	private static function parse_segment( $segment, array $options ) {
		$segment = self::strip_label( $segment );
		$carrier = (string) $options['default_carrier'];

		$detected = self::detect_carrier( $segment, $options['known_carriers'] );

		if ( null !== $detected ) {
			$segment = $detected['number'];

			if ( $options['allow_carrier_override'] ) {
				$carrier = $detected['carrier'];
			}
		}

		$number = preg_replace( self::DISALLOWED_PATTERN, '', $segment );
		$number = is_string( $number ) ? trim( $number, '._/-' ) : '';
		$length = strlen( $number );

		if ( $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
			return null;
		}

		// Separators alone are not a tracking number.
		if ( ! preg_match( '/[A-Za-z0-9]/', $number ) ) {
			return null;
		}

		return array(
			'number'  => $number,
			'carrier' => $carrier,
		);
	}

	/**
	 * Removes a leading human-written label such as `Tracking:`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $segment Candidate segment.
	 * @return string
	 */
	private static function strip_label( $segment ) {
		foreach ( array( self::LABEL_PATTERN, self::SHORT_LABEL_PATTERN ) as $pattern ) {
			$stripped = preg_replace( $pattern, '', $segment );

			if ( is_string( $stripped ) && $stripped !== $segment ) {
				return self::trim_whitespace( $stripped );
			}
		}

		return $segment;
	}

	/**
	 * Detects a `Carrier: number` prefix that maps to a known carrier.
	 *
	 * The prefix is stripped whenever it resolves, regardless of whether the
	 * shop allows per-parcel carrier overrides — otherwise disabling the
	 * override would silently fold the carrier name into the tracking number.
	 * An unrecognised prefix is left alone so that no carrier is invented.
	 *
	 * @since 1.0.0
	 *
	 * @param string $segment        Candidate segment.
	 * @param array  $known_carriers Map of carrier slug => display label.
	 * @return array|null Array with `carrier` and `number`, or null when no prefix resolves.
	 */
	private static function detect_carrier( $segment, array $known_carriers ) {
		if ( empty( $known_carriers ) || ! preg_match( self::CARRIER_PREFIX_PATTERN, $segment, $matches ) ) {
			return null;
		}

		$candidate = strtolower( self::trim_whitespace( $matches[1] ) );

		foreach ( $known_carriers as $slug => $label ) {
			$matches_slug  = strtolower( (string) $slug ) === $candidate;
			$matches_label = strtolower( (string) $label ) === $candidate;

			if ( $matches_slug || $matches_label ) {
				return array(
					'carrier' => (string) $slug,
					'number'  => self::trim_whitespace( $matches[2] ),
				);
			}
		}

		return null;
	}
}
