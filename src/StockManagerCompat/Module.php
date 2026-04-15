<?php

namespace ASDLabs\TVXWooChangeLog\StockManagerCompat;

use ASDLabs\TVXWooChangeLog\Core\Contracts\Module as ModuleContract;

final class Module implements ModuleContract {
	private $detector;

	public function __construct( ?Detector $detector = null ) {
		$this->detector = $detector ?: new Detector();
	}

	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_refresh_state' ), 20 );
	}

	public function maybe_refresh_state() {
		$this->detector->maybe_refresh_cache();
	}
}
