<?php

use MediaWiki\MediaWikiServices;

class InterwikiExtracts {

	/**
	 * @var string $userAgent User agent for querying the API
	 */
	private static $userAgent =
		'Extension:InterwikiExtracts/4.0 (https://www.mediawiki.org/wiki/Extension:InterwikiExtracts)';

	/**
	 * Main hook
	 *
	 * @param Parser $parser Parser object
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'InterwikiExtract', 'InterwikiExtracts::onFunctionHook' );
	}

	/**
	 * Parser function hook
	 *
	 * @param Parser $parser Parser object
	 * @param string|null $input Content of the parser function call
	 * @return string The extract or an error message in case of error
	 * @throws MWException
	 */
	public static function onFunctionHook( Parser $parser, $input = null ) {
		try {
			$title = $input ? $input : $parser->getTitle()->getRootText();
			$params = self::parseParams( array_slice( func_get_args(), 2 ) );
			return self::getExtract( $title, $params );
		} catch ( InterwikiExtractsError $error ) {
			return $error->getHtmlMessage();
		}
	}

	/**
	 * Determine the API to query and format, and return the extract
	 *
	 * @param string $title Title of the article
	 * @param array $params User parameters
	 * @return string The extract
	 * @throws InterwikiExtractsError
	 */
	private static function getExtract( $title, $params ) {

		// Get the interwiki
		$wiki = 'wikipedia'; // Default
		if ( array_key_exists( 'wiki', $params ) ) {
			$wiki = $params['wiki'];
			unset( $params['wiki'] );
		}

		// Get the interwiki API endpoint
		// See https://doc.wikimedia.org/mediawiki-core/master/php/classApiQuerySiteinfo.html#a161831ba1940afa68e4cc0f568792cc4
		$prefixes = MediaWikiServices::getInstance()->getInterwikiLookup()->getAllPrefixes();
		foreach ( $prefixes as $row ) {
			if ( $row['iw_prefix'] === $wiki ) {
				$api = $row['iw_api'];
				if ( !$api ) {
					throw new InterwikiExtractsError;
				}
			}
		}

		// Get the format
		$format = 'text'; // Default
		if ( array_key_exists( 'format', $params ) and in_array( strtolower( $format ), array_map( 'strtolower', [ 'text', 'html', 'wiki' ] ) ) ) {
			$format = $params['format'];
			unset( $params['format'] );
		}

		// Return the content in the appropriate format
		switch ( $format ) {
			case 'text':
				return self::getText( $api, $title, $params );
				break;
			case 'html':
				return self::getHTML( $api, $title, $params );
				break;
			case 'wiki':
				return self::getWiki( $api, $title, $params );
				break;
		}
	}

	/**
	 * Get text extract
	 *
	 * @param string $api API endpoint to query
	 * @param string $title Page title to query
	 * @param array $params User parameters
	 * @return string Text extract
	 * @throws InterwikiExtractsError
	 */
	private static function getText( $api, $title, $params ) {
		$data = [
			'action' => 'query',
			'titles' => $title,
			'prop' => 'extracts',
			'exchars' => $params['chars'] ?? '',
			'exsentences' => $params['sentences'] ?? '',
			'exintro' => $params['intro'] ?? '',
			'explaintext' => $params['plaintext'] ?? '',
			'exsectionformat' => $params['sectionformat'] ?? '',
			'exlimit' => 1,
			'redirects' => true,
			'format' => 'json',
			'formatversion' => 2,
		];
		$data = array_filter( $data ); // Remove the empty params

		$text = self::queryInterwiki( $api, $data );

		return $text;
	}

	/**
	 * Get HTML extract
	 *
	 * @param string $api API endpoint to query
	 * @param string $title Page title to query
	 * @param array $params User parameters
	 * @return string HTML
	 * @throws InterwikiExtractsError
	 */
	private static function getHTML( $api, $title, $params ) {
		$data = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'parse',
			'prop' => 'text',
			'redirects' => 1,
			'disableeditsection' => 1,
		];
		if ( array_key_exists( 'section', $params ) ) {
			$data['section'] = $params['section'];
		}

		// oldid and page are incompatible
		if ( array_key_exists( 'oldid', $params ) ) {
			$data['oldid'] = $params['oldid'];
		} else {
			$data['page'] = $title;
		}

		$html = self::queryInterwiki( $api, $data );

		// Replace relative links for absolute links
		$domain = parse_url( $api, PHP_URL_SCHEME ) . '://' . parse_url( $api, PHP_URL_HOST );
		$html = preg_replace( '#<a([^>]*)href="/([^"]+)"([^>]*)>#', '<a$1href="' . $domain . '/$2"$3>', $html );

		return [ $html, 'isHTML' => true ];
	}

	/**
	 * Get wikitexct extract
	 *
	 * @param string $api API endpoint to query
	 * @param string $title Page title to query
	 * @param array $params User parameters
	 * @return string Wikitext
	 * @throws InterwikiExtractsError
	 */
	private static function getWiki( $api, $title, $params ) {
		$data = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'parse',
			'prop' => 'wikitext',
			'redirects' => 1,
		];
		if ( array_key_exists( 'section', $params ) ) {
			$data['section'] = $params['section'];
		}

		// oldid and page are incompatible
		if ( array_key_exists( 'oldid', $params ) ) {
			$data['oldid'] = $params['oldid'];
		} else {
			$data['page'] = $title;
		}

		$wikitext = self::queryInterwiki( $api, $data );

		return [ $wikitext, 'noparse' => false ];
	}

	/**
	 * Helper method to convert an array of values in form [0] => "name=value"
	 * into a real associative array in form [name] => value
	 * If no = is provided, true is assumed like this: [name] => true
	 *
	 * @param array $params
	 * @return array
	 */
	private static function parseParams( array $params ) {
		$array = [];
		foreach ( $params as $param ) {
			$pair = array_map( 'trim', explode( '=', $param, 2 ) );
			if ( count( $pair ) === 2 ) {
				$array[ $pair[0] ] = $pair[1];
			} else if ( count( $pair ) === 1 ) {
				$array[ $pair[0] ] = true;
			}
		}
		return $array;
	}

	/**
	 * Query the given interwiki API and return the interesting content
	 *
	 * @param string $api API endpoint to query
	 * @param array $data Query parameters
	 * @return string Wikitext, text or HTML
	 * @throws InterwikiExtractsError
	 */
	private static function queryInterwiki( $api, array $data ) {
		$query = $api . '?' . http_build_query( $data );
		$request = MWHttpRequest::factory( $query );
		$request->setUserAgent( self::$userAgent );
		$status = $request->execute();
		if ( !$status->isOK() ) {
			throw new InterwikiExtractsError;
		}
		$content = FormatJson::decode( $request->getContent() );
		if ( !$content ) {
			throw new InterwikiExtractsError;
		}
		if ( property_exists( $content, 'parse' ) ) {
			if ( property_exists( $content->parse, 'text' ) ) {
				return $content->parse->text;
			}
			if ( property_exists( $content->parse, 'wikitext' ) ) {
				return $content->parse->wikitext;
			}
		}
		if ( property_exists( $content, 'query' ) and property_exists( $content->query, 'pages' ) ) {
			$page = $content->query->pages[0];
			if ( property_exists( $page, 'extract' ) ) {
				return $page->extract;
			}
		}
		throw new InterwikiExtractsError;
	}
}