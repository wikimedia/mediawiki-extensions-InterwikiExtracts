<?php

use MediaWiki\MediaWikiServices;

class InterwikiExtracts {

	/**
	 * @var string User agent for querying the API
	 */
	private static $userAgent =
		'Extension:InterwikiExtracts/4.2 (https://www.mediawiki.org/wiki/Extension:InterwikiExtracts)';

	/**
	 * Main hook
	 *
	 * @param Parser $parser Parser object
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'InterwikiExtract', [ self::class, 'onFunctionHook' ] );
	}

	/**
	 * Determine the title, parameters, API endpoint and format
	 *
	 * @param Parser $parser Parser object
	 * @param string|null $input Content of the parser function call
	 * @return string The extract or an error message in case of error
	 * @throws MWException
	 */
	public static function onFunctionHook( Parser $parser, string $input = '' ) {
		try {
			// Get the title to query
			$title = $input ? $input : $parser->getTitle()->getRootText();

			// Get the user parameters
			$params = array_slice( func_get_args(), 2 );
			$params = self::parseParams( $params );

			// Get the API endpoint to query
			$api = $params['api'] ?? null;
			unset( $params['api'] );

			// Get the API endpoint from the interwiki table
			$wiki = $params['wiki'] ?? 'wikipedia';
			unset( $params['wiki'] );
			if ( !$api ) {
				$prefixes = MediaWikiServices::getInstance()->getInterwikiLookup()->getAllPrefixes();
				foreach ( $prefixes as $row ) {
					if ( $row['iw_prefix'] === $wiki ) {
						$api = $row['iw_api'];
					}
				}
			}

			if ( !$api ) {
				throw new InterwikiExtractsError( 'no-api' );
			}

			// Get the format
			$format = 'html'; // Default
			if ( isset( $params['format'] ) &&
				in_array( strtolower( $params['format'] ), [ 'html', 'wiki', 'text' ] )
			) {
				$format = strtolower( $params['format'] );
				unset( $params['format'] );
			}

			// Get the extract in the appropriate format
			switch ( $format ) {
				case 'html':
					return self::getHTML( $api, $title, $params );
				case 'wiki':
					return self::getWiki( $api, $title, $params );
				case 'text':
					return self::getText( $api, $title, $params );
			}
		} catch ( InterwikiExtractsError $error ) {
			return $error->getHtmlMessage();
		}
	}

	/**
	 * Get the HTML for the given title
	 *
	 * @param string $api API endpoint to query
	 * @param string $title Page title to query
	 * @param array $params Query parameters
	 * @return string HTML
	 * @throws InterwikiExtractsError
	 */
	private static function getHTML( string $api, string $title, array $params ) {
		$data = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'parse',
			'prop' => 'text',
			'redirects' => 1,
			'disableeditsection' => 1,
		];
		if ( isset( $params['section'] ) ) {
			$data['section'] = $params['section'];
		}

		// oldid and page are incompatible
		if ( isset( $params['oldid'] ) ) {
			$data['oldid'] = $params['oldid'];
		} else {
			$data['page'] = $title;
		}

		// See https://en.wikipedia.org/w/api.php?action=parse&formatversion=2&prop=text&page=Science
		$html = self::queryInterwiki( $api, $data );

		// Replace relative links for absolute links
		$domain = parse_url( $api, PHP_URL_SCHEME ) . '://' . parse_url( $api, PHP_URL_HOST );
		$html = preg_replace(
			'#<a([^>]*)href="/([^"]+)"([^>]*)>#', '<a$1href="' . $domain . '/$2"$3>', $html
		);

		return [ $html, 'isHTML' => true ];
	}

	/**
	 * Get the wikitext for the given title
	 *
	 * @param string $api API endpoint to query
	 * @param string $title Page title to query
	 * @param array $params Query parameters
	 * @return string Wikitext
	 * @throws InterwikiExtractsError
	 */
	private static function getWiki( string $api, string $title, array $params ) {
		$data = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'parse',
			'prop' => 'wikitext',
			'redirects' => 1,
		];
		if ( isset( $params['section'] ) ) {
			$data['section'] = $params['section'];
		}

		// oldid and page are incompatible
		if ( isset( $params['oldid'] ) ) {
			$data['oldid'] = $params['oldid'];
		} else {
			$data['page'] = $title;
		}

		// See https://en.wikipedia.org/w/api.php?action=parse&formatversion=2&prop=wikitext&page=Science
		$wikitext = self::queryInterwiki( $api, $data );

		return [ $wikitext, 'noparse' => false ];
	}

	/**
	 * Get the text extract for the given title
	 *
	 * @param string $api API endpoint to query
	 * @param string $title Page title to query
	 * @param array $params Query parameters
	 * @return string Text extract
	 * @throws InterwikiExtractsError
	 */
	private static function getText( string $api, string $title, array $params ) {
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

		// See https://en.wikipedia.org/w/api.php?action=query&prop=extracts&exlimit=1&formatversion=2&titles=Science
		$text = self::queryInterwiki( $api, $data );

		// Remove templatestyle links
		$text = preg_replace( "/<link[^>]+>/", '', $text );

		// Remove extra line breaks
		$text = preg_replace( "/\n/", '', $text );

		// Keep only the requested section
		if ( isset( $params['section'] ) && $params['section'] ) {
			$section = $params['section'];
			$text = preg_replace( '/.*?<h\d><span[^>]+?>' . $section . '<\/span><\/h\d>(.+?)<h\d>.*/', '$1', $text );
		}

		// Keep only the requested paragraphs
		if ( isset( $params['paragraphs'] ) && $params['paragraphs'] ) {
			preg_match_all( '/(<p>.+?<\/p>)/sim', $text, $matches );
			if ( isset( $matches[1] ) ) {
				$text = '';
				foreach ( $matches[1] as $i => $match ) {
					if ( $i < $params['paragraphs'] ) {
						$text .= $match;
					}
				}
			}
		}

		return $text;
	}

	/**
	 * Query the given API endpoint and return the relevant content
	 *
	 * @param string $api API endpoint to query
	 * @param array $data Query parameters
	 * @return string Wikitext, text or HTML
	 * @throws InterwikiExtractsError
	 */
	private static function queryInterwiki( string $api, array $data ) {
		$query = $api . '?' . http_build_query( $data );
		$request = MWHttpRequest::factory( $query );
		$request->setUserAgent( self::$userAgent );
		$status = $request->execute();
		if ( !$status->isOK() ) {
			throw new InterwikiExtractsError;
		}
		$content = FormatJson::decode( $request->getContent() );

		// First assume everything went ok
		if ( isset( $content->parse->text ) ) {
			return $content->parse->text;
		}
		if ( isset( $content->parse->wikitext ) ) {
			return $content->parse->wikitext;
		}
		if ( isset( $content->query->pages[0]->extract ) ) {
			return $content->query->pages[0]->extract;
		}

		// If we get to this point, something went wrong
		if ( isset( $content->error->code ) ) {
			switch ( $content->error->code ) {
				case 'missingtitle':
					throw new InterwikiExtractsError( 'missing-title' );
				case 'missingtitle':
					throw new InterwikiExtractsError( 'no-such-section' );
				case 'invalidsection':
					throw new InterwikiExtractsError( 'invalid-section' );
			}
		}
		if ( isset( $content->query->pages[0]->missing ) ) {
			throw new InterwikiExtractsError( 'missing-title' );
		}
		throw new InterwikiExtractsError; // Generic error message
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
			} elseif ( count( $pair ) === 1 ) {
				$array[ $pair[0] ] = true;
			}
		}
		return $array;
	}
}
