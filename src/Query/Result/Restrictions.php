<?php

namespace SMW\Query\Result;

use SMW\Query\PrintRequest;

/**
 * @license GNU GPL v2+
 * @since 3.1
 *
 * @author mwjames
 */
class Restrictions {

	/**
	 * @since 3.1
	 *
	 * @param  PrintRequest $printRequest
	 * @param  DataItem[] $content
	 *
	 * @return []
	 */
	public static function applySortRestriction( PrintRequest $printRequest, array $content ) {

		if ( $content === [] ) {
			return $content;
		}

		$order = strtolower( $printRequest->getParameter( 'order' ) );
		$sampleDataItem = reset( $content );
		$isNumeric = is_numeric( $sampleDataItem->getSortKey() );

		$flag = $isNumeric ? SORT_NUMERIC : SORT_LOCALE_STRING;

		// SORT_NATURAL is selected on n-asc, n-desc
		if ( strpos( $order, 'n-' ) !== false ) {
			$flag = SORT_NATURAL;
		}

		$sortres = [];

		foreach ( $content as $di ) {
			$sortres[] = $di->getSortKey();
		}

		if ( $order === 'asc' || $order === 'n-asc' ) {
			asort( $sortres, $flag );
		} else {
			arsort( $sortres, $flag );
		}

		$newres = [];
		$i = 0;

		foreach ( $sortres as $key => $value ) {
			$newres[] = $content[$key];
			$i++;
		}

		return $newres;
	}

	/**
	 * @since 3.1
	 *
	 * @param  PrintRequest $printRequest
	 * @param  DataItem[] $content
	 *
	 * @return []
	 */
	public static function applyLimitRestriction( PrintRequest $printRequest, array $content ) {

		$limit = (int)$printRequest->getParameter( 'limit' );
		$offset = (int)$printRequest->getParameter( 'offset' );

		if ( $printRequest->getParameter( 'limit' ) !== false ) {
			$content = array_slice( $content, $offset, $limit );
		}

		return $content;
	}

}