<?php
/**
 * Decides which follow-up actions a sync should perform.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision logic for the actions that follow a tracking write.
 *
 * Kept free of WordPress so the full settings matrix can be unit tested. The
 * subtlety this class exists to contain: `update_status( 'completed' )` is a
 * no-op on an already-completed order and therefore sends no email, so
 * "complete the order" and "email the customer" cannot be treated as one step.
 *
 * @since 1.0.0
 */
class Trackbridge_Sync_Decision {

	/**
	 * Works out the follow-up actions for a completed sync.
	 *
	 * @since 1.0.0
	 *
	 * @param array $outcome {
	 *     Result of writing the tracking numbers.
	 *
	 *     @type int $added   Tracking numbers newly written.
	 *     @type int $skipped Tracking numbers already present on the order.
	 *     @type int $failed  Tracking numbers that could not be written.
	 * }
	 * @param array $settings {
	 *     Shop configuration and order state.
	 *
	 *     @type bool $delete            Whether to remove the temporary field.
	 *     @type bool $mark_completed    Whether to complete the order.
	 *     @type bool $send_email        Whether the customer should be emailed.
	 *     @type bool $already_completed Whether the order is already completed.
	 * }
	 * @return array {
	 *     @type bool $delete_meta       Remove the temporary custom field.
	 *     @type bool $complete_order    Transition the order to completed.
	 *     @type bool $suppress_email    Mute WooCommerce's email for that transition.
	 *     @type bool $trigger_email     Send the completed-order email explicitly.
	 * }
	 */
	public static function decide( array $outcome, array $settings ) {
		$outcome  = array_merge(
			array(
				'added'   => 0,
				'skipped' => 0,
				'failed'  => 0,
			),
			$outcome
		);
		$settings = array_merge(
			array(
				'delete'            => false,
				'mark_completed'    => false,
				'send_email'        => false,
				'already_completed' => false,
			),
			$settings
		);

		$added        = (int) $outcome['added'];
		$has_failures = (int) $outcome['failed'] > 0;

		// "Skipped" means the tracking number is already on the order, which is
		// just as good as having written it now.
		$progressed = $added > 0 || (int) $outcome['skipped'] > 0;
		$clean      = $progressed && ! $has_failures;

		// A failed write keeps the temporary field so the number is never lost.
		$delete_meta = $settings['delete'] && $clean;

		$complete_order = $settings['mark_completed'] && $clean && ! $settings['already_completed'];

		// WooCommerce emails the customer during the transition, so it only needs
		// muting, or triggering separately when no transition happens.
		$suppress_email = $complete_order && ! $settings['send_email'];
		$trigger_email  = $settings['send_email'] && $added > 0 && ! $has_failures && ! $complete_order;

		return array(
			'delete_meta'    => (bool) $delete_meta,
			'complete_order' => (bool) $complete_order,
			'suppress_email' => (bool) $suppress_email,
			'trigger_email'  => (bool) $trigger_email,
		);
	}
}
