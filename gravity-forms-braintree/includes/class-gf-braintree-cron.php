<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Cron {

	public static function bootstrap(): void {
		add_action( 'gf_braintree_daily_housekeeping', [ __CLASS__, 'daily_housekeeping' ] );
		if ( ! wp_next_scheduled( 'gf_braintree_daily_housekeeping' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'gf_braintree_daily_housekeeping' );
		}
	}

	public static function daily_housekeeping(): void {
		GFBraintree_Logger::debug( 'Daily housekeeping executed.' );
	}
}