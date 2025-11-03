<?php

use MediaWiki\Html\Html;

/**
 * Error handler
 */
class InterwikiExtractsError extends Exception {

	/**
	 * @var string Key identifying the type of error
	 */
	public $key;

	/**
	 * Error constructor
	 *
	 * @param string $key Key identifying the type of error
	 */
	public function __construct( $key = 'error' ) {
		$this->key = $key;
	}

	/**
	 * Get the HTML of the error message
	 *
	 * @return string Raw HTML of the error message
	 */
	public function getHtmlMessage() {
		return Html::rawElement(
			'span', [
				'class' => 'error'
			],
			wfMessage( 'interwikiextracts-' . $this->key )
		);
	}
}
