<?php
// Optional: If you wanted to enforce nav removal outside the add-on class.
add_filter( 'gform_addon_navigation', static function( $menus ) {
	return array_values(
		array_filter(
			$menus,
			static fn( $m ) => ( $m['name'] ?? '' ) !== 'gravity-forms-braintree'
		)
	);
}, 50 );