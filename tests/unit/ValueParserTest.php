<?php
/**
 * Unit tests for the tracking value parser.
 *
 * @package TrackBridge
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * @covers Trackbridge_Value_Parser
 */
final class ValueParserTest extends TestCase {

	/**
	 * Parses a raw value with sensible test defaults.
	 *
	 * @param mixed $raw     Raw meta value.
	 * @param array $options Option overrides.
	 * @return array
	 */
	private function parse( $raw, array $options = array() ): array {
		return Trackbridge_Value_Parser::parse(
			$raw,
			array_merge(
				array(
					'default_carrier'        => 'gls',
					'allow_carrier_override' => false,
					'split_multiple'         => true,
					'known_carriers'         => array(
						'gls'     => 'GLS',
						'dhl'     => 'DHL Parcel',
						'ups'     => 'UPS',
						'correos' => 'Correos Express',
					),
				),
				$options
			)
		);
	}

	public function test_parses_a_plain_tracking_number(): void {
		$this->assertSame(
			array(
				array(
					'number'  => '1234567890',
					'carrier' => 'gls',
				),
			),
			$this->parse( '1234567890' )
		);
	}

	public function test_trims_surrounding_whitespace(): void {
		$result = $this->parse( "  \t 1234567890 \n " );

		$this->assertCount( 1, $result );
		$this->assertSame( '1234567890', $result[0]['number'] );
	}

	public function test_trims_non_breaking_spaces(): void {
		$result = $this->parse( "\xC2\xA01234567890\xC2\xA0" );

		$this->assertCount( 1, $result );
		$this->assertSame( '1234567890', $result[0]['number'] );
	}

	/**
	 * @dataProvider provide_empty_values
	 * @param mixed $raw Raw value that must yield no tracking intents.
	 */
	public function test_returns_nothing_for_empty_or_non_scalar_values( $raw ): void {
		$this->assertSame( array(), $this->parse( $raw ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_empty_values(): array {
		return array(
			'empty string'     => array( '' ),
			'spaces only'      => array( '   ' ),
			'newline only'     => array( "\n" ),
			'null'             => array( null ),
			'array'            => array( array( '123' ) ),
			'boolean false'    => array( false ),
			'separators only'  => array( ' , ; ' ),
			'punctuation only' => array( '---' ),
		);
	}

	/**
	 * @dataProvider provide_labelled_values
	 * @param string $raw      Raw value including a human label.
	 * @param string $expected Expected extracted number.
	 */
	public function test_strips_a_leading_human_label( string $raw, string $expected ): void {
		$result = $this->parse( $raw );

		$this->assertCount( 1, $result, 'Expected exactly one tracking intent.' );
		$this->assertSame( $expected, $result[0]['number'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_labelled_values(): array {
		return array(
			'colon'           => array( 'Tracking: 1234567890', '1234567890' ),
			'lowercase'       => array( 'tracking:1234567890', '1234567890' ),
			'tracking number' => array( 'Tracking number: 1234567890', '1234567890' ),
			'dash separator'  => array( 'Tracking - 1234567890', '1234567890' ),
			'hash separator'  => array( 'Tracking #1234567890', '1234567890' ),
			'nr abbreviation' => array( 'nr: 1234567890', '1234567890' ),
			'no abbreviation' => array( 'no. 1234567890', '1234567890' ),
		);
	}

	public function test_splits_on_commas_into_multiple_parcels(): void {
		$this->assertSame(
			array(
				array(
					'number'  => '111111',
					'carrier' => 'gls',
				),
				array(
					'number'  => '222222',
					'carrier' => 'gls',
				),
			),
			$this->parse( '111111, 222222' )
		);
	}

	public function test_splits_on_semicolons(): void {
		$this->assertCount( 2, $this->parse( '111111;222222' ) );
	}

	public function test_splits_on_newlines_even_when_splitting_is_disabled(): void {
		$result = $this->parse( "111111\n222222", array( 'split_multiple' => false ) );

		$this->assertCount( 2, $result, 'A newline can never be part of a tracking number.' );
	}

	public function test_does_not_split_on_commas_when_splitting_is_disabled(): void {
		$result = $this->parse( '111111,222222', array( 'split_multiple' => false ) );

		$this->assertCount( 1, $result );
		$this->assertSame( '111111222222', $result[0]['number'] );
	}

	public function test_uses_the_default_carrier_when_override_is_disabled(): void {
		$result = $this->parse( 'DHL:1234567890' );

		$this->assertCount( 1, $result );
		$this->assertSame( '1234567890', $result[0]['number'], 'A recognised prefix is always stripped.' );
		$this->assertSame( 'gls', $result[0]['carrier'], 'Override disabled keeps the configured carrier.' );
	}

	public function test_honours_a_carrier_prefix_when_override_is_enabled(): void {
		$result = $this->parse( 'DHL:1234567890', array( 'allow_carrier_override' => true ) );

		$this->assertSame(
			array(
				array(
					'number'  => '1234567890',
					'carrier' => 'dhl',
				),
			),
			$result
		);
	}

	public function test_matches_a_carrier_prefix_by_slug_case_insensitively(): void {
		$result = $this->parse( 'ups: 1Z999AA10123456784', array( 'allow_carrier_override' => true ) );

		$this->assertSame( 'ups', $result[0]['carrier'] );
		$this->assertSame( '1Z999AA10123456784', $result[0]['number'] );
	}

	public function test_matches_a_carrier_prefix_by_display_label(): void {
		$result = $this->parse( 'Correos Express: 1234567890', array( 'allow_carrier_override' => true ) );

		$this->assertSame( 'correos', $result[0]['carrier'] );
		$this->assertSame( '1234567890', $result[0]['number'] );
	}

	public function test_treats_an_unknown_prefix_as_part_of_the_number(): void {
		$result = $this->parse( 'XYZ:1234567890', array( 'allow_carrier_override' => true ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'XYZ1234567890', $result[0]['number'], 'Unknown prefixes must not invent a carrier.' );
		$this->assertSame( 'gls', $result[0]['carrier'] );
	}

	public function test_allows_a_per_parcel_carrier_when_override_is_enabled(): void {
		$result = $this->parse( 'gls:111111, dhl:222222', array( 'allow_carrier_override' => true ) );

		$this->assertSame(
			array(
				array(
					'number'  => '111111',
					'carrier' => 'gls',
				),
				array(
					'number'  => '222222',
					'carrier' => 'dhl',
				),
			),
			$result
		);
	}

	public function test_removes_internal_whitespace_from_numbers(): void {
		$result = $this->parse( '1234 5678 90' );

		$this->assertSame( '1234567890', $result[0]['number'] );
	}

	public function test_preserves_dashes_dots_and_slashes(): void {
		$result = $this->parse( 'AB-12.34/56' );

		$this->assertSame( 'AB-12.34/56', $result[0]['number'] );
	}

	public function test_strips_disallowed_characters(): void {
		$result = $this->parse( '12<script>34' );

		$this->assertSame( '12script34', $result[0]['number'] );
	}

	public function test_deduplicates_identical_numbers(): void {
		$this->assertCount( 1, $this->parse( '1234567890, 1234567890' ) );
	}

	public function test_deduplicates_case_insensitively_but_preserves_first_casing(): void {
		$result = $this->parse( 'abc123456, ABC123456' );

		$this->assertCount( 1, $result );
		$this->assertSame( 'abc123456', $result[0]['number'] );
	}

	public function test_keeps_the_same_number_for_two_different_carriers(): void {
		$result = $this->parse( 'gls:111111, dhl:111111', array( 'allow_carrier_override' => true ) );

		$this->assertCount( 2, $result );
	}

	public function test_drops_numbers_that_are_too_short(): void {
		$this->assertSame( array(), $this->parse( '1, 2, 3' ) );
	}

	public function test_drops_numbers_that_are_too_long(): void {
		$this->assertSame( array(), $this->parse( str_repeat( '9', 101 ) ) );
	}

	public function test_caps_the_number_of_parcels(): void {
		$numbers = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$numbers[] = sprintf( 'TRACK%03d', $i );
		}

		$result = $this->parse( implode( ',', $numbers ) );

		$this->assertCount( Trackbridge_Value_Parser::MAX_PARCELS, $result );
		$this->assertSame( 'TRACK000', $result[0]['number'], 'The cap keeps the first entries.' );
	}

	public function test_passes_an_empty_default_carrier_through_untouched(): void {
		$result = $this->parse( '1234567890', array( 'default_carrier' => '' ) );

		$this->assertCount( 1, $result );
		$this->assertSame( '', $result[0]['carrier'], 'Carrier validation belongs to the sync layer.' );
	}

	public function test_works_without_any_options(): void {
		$result = Trackbridge_Value_Parser::parse( '1234567890' );

		$this->assertCount( 1, $result );
		$this->assertSame( '1234567890', $result[0]['number'] );
		$this->assertSame( '', $result[0]['carrier'] );
	}

	public function test_accepts_numeric_input(): void {
		$result = $this->parse( 1234567890 );

		$this->assertSame( '1234567890', $result[0]['number'] );
	}
}
