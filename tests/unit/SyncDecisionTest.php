<?php
/**
 * Unit tests for the post-sync action matrix.
 *
 * @package TrackBridge
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * @covers Trackbridge_Sync_Decision
 */
final class SyncDecisionTest extends TestCase {

	/**
	 * Decides with all settings enabled unless overridden.
	 *
	 * @param array $outcome  Sync outcome.
	 * @param array $settings Settings overrides.
	 * @return array
	 */
	private function decide( array $outcome, array $settings = array() ): array {
		return Trackbridge_Sync_Decision::decide(
			$outcome,
			array_merge(
				array(
					'delete'            => true,
					'mark_completed'    => true,
					'send_email'        => true,
					'already_completed' => false,
				),
				$settings
			)
		);
	}

	public function test_a_clean_sync_deletes_completes_and_lets_woocommerce_email(): void {
		$this->assertSame(
			array(
				'delete_meta'    => true,
				'complete_order' => true,
				'suppress_email' => false,
				'trigger_email'  => false,
			),
			$this->decide( array( 'added' => 1 ) )
		);
	}

	public function test_completing_without_email_suppresses_the_transition_email(): void {
		$decision = $this->decide( array( 'added' => 1 ), array( 'send_email' => false ) );

		$this->assertTrue( $decision['complete_order'] );
		$this->assertTrue( $decision['suppress_email'] );
		$this->assertFalse( $decision['trigger_email'] );
	}

	public function test_emailing_without_completing_triggers_the_email_directly(): void {
		$decision = $this->decide( array( 'added' => 1 ), array( 'mark_completed' => false ) );

		$this->assertFalse( $decision['complete_order'] );
		$this->assertFalse( $decision['suppress_email'] );
		$this->assertTrue( $decision['trigger_email'] );
	}

	public function test_an_already_completed_order_still_gets_the_email(): void {
		$decision = $this->decide( array( 'added' => 1 ), array( 'already_completed' => true ) );

		$this->assertFalse( $decision['complete_order'], 'Completing an already-completed order is a no-op.' );
		$this->assertTrue( $decision['trigger_email'], 'The email must be triggered explicitly instead.' );
	}

	public function test_neither_setting_writes_tracking_only(): void {
		$decision = $this->decide(
			array( 'added' => 1 ),
			array(
				'mark_completed' => false,
				'send_email'     => false,
			)
		);

		$this->assertFalse( $decision['complete_order'] );
		$this->assertFalse( $decision['trigger_email'] );
		$this->assertFalse( $decision['suppress_email'] );
		$this->assertTrue( $decision['delete_meta'] );
	}

	public function test_a_failure_keeps_the_temporary_field(): void {
		$decision = $this->decide(
			array(
				'added'  => 1,
				'failed' => 1,
			)
		);

		$this->assertFalse( $decision['delete_meta'], 'A partly failed sync must not discard the typed value.' );
		$this->assertFalse( $decision['complete_order'] );
		$this->assertFalse( $decision['trigger_email'] );
	}

	public function test_a_total_failure_does_nothing_else(): void {
		$this->assertSame(
			array(
				'delete_meta'    => false,
				'complete_order' => false,
				'suppress_email' => false,
				'trigger_email'  => false,
			),
			$this->decide( array( 'failed' => 2 ) )
		);
	}

	public function test_an_empty_outcome_does_nothing(): void {
		$decision = $this->decide( array() );

		$this->assertFalse( $decision['delete_meta'] );
		$this->assertFalse( $decision['complete_order'] );
		$this->assertFalse( $decision['trigger_email'] );
	}

	public function test_an_already_synced_order_is_cleaned_up_without_re_emailing(): void {
		$decision = $this->decide( array( 'skipped' => 1 ) );

		$this->assertTrue( $decision['delete_meta'], 'The stale field should still be removed.' );
		$this->assertTrue( $decision['complete_order'] );
		$this->assertFalse( $decision['trigger_email'], 'Nothing new shipped, so do not email again.' );
	}

	public function test_a_repeat_save_on_a_completed_order_does_not_re_email(): void {
		$decision = $this->decide(
			array( 'skipped' => 1 ),
			array( 'already_completed' => true )
		);

		$this->assertTrue( $decision['delete_meta'] );
		$this->assertFalse( $decision['complete_order'] );
		$this->assertFalse( $decision['trigger_email'], 'Guards against emailing the customer on every order save.' );
	}

	public function test_deletion_can_be_disabled_independently(): void {
		$decision = $this->decide( array( 'added' => 1 ), array( 'delete' => false ) );

		$this->assertFalse( $decision['delete_meta'] );
		$this->assertTrue( $decision['complete_order'] );
	}

	public function test_defaults_are_all_disabled_when_settings_are_missing(): void {
		$this->assertSame(
			array(
				'delete_meta'    => false,
				'complete_order' => false,
				'suppress_email' => false,
				'trigger_email'  => false,
			),
			Trackbridge_Sync_Decision::decide( array( 'added' => 1 ), array() )
		);
	}

	public function test_multiple_parcels_are_treated_as_one_clean_sync(): void {
		$decision = $this->decide(
			array(
				'added'   => 3,
				'skipped' => 1,
			)
		);

		$this->assertTrue( $decision['delete_meta'] );
		$this->assertTrue( $decision['complete_order'] );
		$this->assertFalse( $decision['trigger_email'], 'WooCommerce emails once during the transition.' );
	}
}
