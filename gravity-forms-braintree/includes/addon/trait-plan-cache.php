<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_AddOn_Plan_Cache_Trait {

	protected function plan_cache_key(): string {
		return 'gf_braintree_plans_cache';
	}

	protected function get_cached_plans(): ?array {
		$plans = get_transient( $this->plan_cache_key() );
		return $plans ?: null;
	}

	protected function cache_plans( array $plans, int $ttl = 3600 ) {
		set_transient( $this->plan_cache_key(), $plans, $ttl );
	}

	public function get_plan_choices(): array {
		$cached = $this->get_cached_plans();
		if ( $cached ) {
			return $cached;
		}
		try {
			$plans = $this->get_api()->get_plans();
			$this->cache_plans( $plans );
			return $plans;
		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Plan fetch error', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}
}