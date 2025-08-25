<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Cron {
	public static function init(): void {
		add_action( 'gf_braintree_daily_housekeeping', [ __CLASS__, 'daily_housekeeping' ] );
	}
	public static function daily_housekeeping(): void {
		// Placeholder
	}
}
GFBraintree_Cron::init();