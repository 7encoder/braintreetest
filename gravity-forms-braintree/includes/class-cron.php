<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Cron {

	public static function init(): void {
		add_action( 'gf_braintree_daily_housekeeping', [ __CLASS__, 'daily_housekeeping' ] );
	}

	public static function daily_housekeeping(): void {
		// Future: purge old maps, rotate logs, etc.
		GFBraintree_Logger::debug( 'Daily housekeeping executed.' );
	}
}
GFBraintree_Cron::init();