<?php

namespace SMW\MediaWiki\Specials;

use ParamProcessor\Param;
use SMW\Query\PrintRequest;
use SMW\Query\QueryLinker;
use SMW\MediaWiki\Specials\Ask\ErrorWidget;
use SMW\MediaWiki\Specials\Ask\LinksWidget;
use SMW\MediaWiki\Specials\Ask\ParametersWidget;
use SMW\MediaWiki\Specials\Ask\ParametersProcessor;
use SMW\MediaWiki\Specials\Ask\FormatterWidget;
use SMW\MediaWiki\Specials\Ask\NavigationLinksWidget;
use SMW\MediaWiki\Specials\Ask\SortWidget;
use SMW\MediaWiki\Specials\Ask\FormatSelectionWidget;
use SMW\MediaWiki\Specials\Ask\QueryInputWidget;
use SMW\MediaWiki\Specials\Ask\UrlArgs;
use SMW\ApplicationFactory;
use SMWQueryProcessor as QueryProcessor;
use SMWInfoLink as InfoLink;
use SpecialPage;
use SMWOutputs;
use SMWQuery;
use Html;

/**
 * This special page for MediaWiki implements a customisable form for executing
 * queries outside of articles.
 *
 * @license GNU GPL v2+
 * @since   3.0
 *
 * @author mwjames
 * @author Markus Krötzsch
 * @author Yaron Koren
 * @author Sanyam Goyal
 * @author Jeroen De Dauw
 */
class SpecialAsk extends SpecialPage {

	/**
	 * @var QuerySourceFactory
	 */
	private $querySourceFactory;

	/**
	 * @var string
	 */
	private $queryString = '';

	/**
	 * @var array
	 */
	private $parameters = array();

	/**
	 * @var array
	 */
	private $printouts = array();

	/**
	 * @var boolean
	 */
	private $isEditMode = false;

	/**
	 * @var boolean
	 */
	private $isBorrowedMode = false;

	/**
	 * @var Param[]
	 */
	private $params = array();

	public function __construct() {
		parent::__construct( 'Ask' );
		$this->querySourceFactory = ApplicationFactory::getInstance()->getQuerySourceFactory();
	}

	/**
	 * @see SpecialPage::doesWrites
	 *
	 * @return boolean
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @see SpecialPage::execute
	 *
	 * @param string $p
	 */
	public function execute( $p ) {
		global $smwgQEnabled;

		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.smw.style' );
		$out->addModuleStyles( 'ext.smw.ask.styles' );
		$out->addModuleStyles( 'ext.smw.table.styles' );

		$out->addModules( 'ext.smw.ask' );
		$out->addModules( 'ext.smw.property' );

		$out->addModules(
			LinksWidget::getModules()
		);

		$this->setHeaders();

		$request->setVal( 'wpEditToken',
			$this->getUser()->getEditToken()
		);

		// #2590
		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			return $out->addHtml( ErrorWidget::sessionFailure() );
		}

		$out->addHTML( ErrorWidget::noScript() );

		NavigationLinksWidget::setMaxInlineLimit(
			$GLOBALS['smwgQMaxInlineLimit']
		);

		FormatSelectionWidget::setResultFormats(
			$GLOBALS['smwgResultFormats']
		);

		ParametersWidget::setTooltipDisplay(
			$this->getUser()->getOption( 'smw-prefs-ask-options-tooltip-display' )
		);

		ParametersWidget::setDefaultLimit(
			$GLOBALS['smwgQDefaultLimit']
		);

		SortWidget::setSortingSupport(
			$GLOBALS['smwgQSortingSupport']
		);

		// @see #835
		SortWidget::setRandSortingSupport(
			$GLOBALS['smwgQRandSortingSupport']
		);

		ParametersProcessor::setDefaultLimit(
			$GLOBALS['smwgQDefaultLimit']
		);

		ParametersProcessor::setMaxInlineLimit(
			$GLOBALS['smwgQMaxInlineLimit']
		);

		$this->isBorrowedMode = $request->getCheck( 'bTitle' );

		if ( $this->isBorrowedMode ) {
			$visibleLinks = [];
		} elseif( $request->getVal( 'eq' ) === 'no' || $p !== null || $request->getVal( 'x' ) ) {
			$visibleLinks = [ 'search', 'empty' ];
		} else {
			$visibleLinks = [ 'options', 'search', 'empty' ];
		}

		$out->addHTML(
			NavigationLinksWidget::topLinks(
				SpecialPage::getSafeTitleFor( 'Ask' ),
				$visibleLinks
			)
		);

		if ( !$smwgQEnabled ) {
			$out->addHTML( '<br />' . wfMessage( 'smw_iq_disabled' )->escaped() );
		} else {
			if ( $request->getCheck( 'showformatoptions' ) ) {
				// handle Ajax action
				$format = $request->getVal( 'showformatoptions' );
				$params = $request->getArray( 'params' );
				$out->disable();
				echo ParametersWidget::parameterList( $format, $params );
			} else {
				$this->extractQueryParameters( $p );
				$this->makeHTMLResult();
			}
		}

		$this->addExternalHelpLinkFor( 'smw_ask_doculink' );

		// make sure locally collected output data is pushed to the output!
		SMWOutputs::commitToOutputPage( $out );
	}

	/**
	 * @see SpecialPage::getGroupName
	 */
	protected function getGroupName() {
		return 'smw_group';
	}

	/**
	 * @param string $p
	 */
	protected function extractQueryParameters( $p ) {

		$request = $this->getRequest();
		$this->isEditMode = false;

		list( $this->queryString, $this->parameters, $this->printouts ) = ParametersProcessor::process(
			$request,
			$p
		);

		$this->isEditMode = ( $request->getVal( 'eq' ) == 'yes' ) || ( $this->queryString === '' );
	}

	/**
	 * TODO: document
	 */
	protected function makeHTMLResult() {

		$result = '';
		$res = null;

		$navigation = '';
		$urlArgs = $this->newUrlArgs();

		$isFromCache = false;
		$duration = 0;

		if ( $this->isBorrowedMode ) {
			$urlArgs->set( 'bTitle', $this->getRequest()->getVal( 'bTitle' ) );
			$urlArgs->set( 'bMsg', $this->getRequest()->getVal( 'bMsg' ) );
		}

		$queryLink = null;
		$error = '';

		if ( $this->queryString !== '' ) {

			list( $res, $debug, $duration, $queryobj ) = $this->getQueryResult();

			$printer = QueryProcessor::getResultPrinter(
				$this->parameters['format'],
				QueryProcessor::SPECIAL_PAGE
			);

			$printer->setShowErrors( false );
			$hidequery = $this->getRequest()->getVal( 'eq' ) == 'no';

			if ( !$printer->isExportFormat() ) {
				if ( $res->getCount() > 0 ) {
					if ( $this->isEditMode ) {
						$urlArgs->set( 'eq', 'yes' );
					} elseif ( $hidequery ) {
						$urlArgs->set( 'eq', 'no' );
					}

					$query_result = $printer->getResult( $res, $this->params, SMW_OUTPUT_HTML );

					$result .= $debug;

					if ( is_array( $query_result ) ) {
						$result .= $query_result[0];
					} else {
						$result .= $query_result;
					}
				} else {
					$result = ErrorWidget::noResult();
					$result .= $debug;
				}
			}
		}

		// FileExport will override the header and cause issues during the unit
		// test when fetching the output stream therefore use the plain output
		if ( defined( 'MW_PHPUNIT_TEST' ) && isset( $printer ) && $printer->isExportFormat() ) {
			$result = $printer->getResult( $res, $this->params, SMW_OUTPUT_FILE );
			$printer = null;
		}

		if ( isset( $printer ) && $printer->isExportFormat() ) {
			$this->getOutput()->disable();

			/**
			 * @var SMWIExportPrinter $printer
			 */
			return $printer->outputAsFile( $res, $this->params );
		}

		if ( $this->queryString ) {
			$this->getOutput()->setHTMLtitle( $this->queryString );
		} else {
			$this->getOutput()->setHTMLtitle( wfMessage( 'ask' )->text() );
		}

		$urlArgs->set( 'offset', $this->parameters['offset'] );
		$urlArgs->set( 'limit', $this->parameters['limit'] );

		$result = FormatterWidget::div(
			$result,
			[
				'id' => 'result',
				"class" => 'smw-ask-result' . ( $this->isBorrowedMode ? ' is-disabled' : '' )
			]
		);

		if ( $res !== null ) {
			$navigation = NavigationLinksWidget::navigationLinks(
				SpecialPage::getSafeTitleFor( 'Ask' ),
				$urlArgs,
				$res->getCount(),
				$res->hasFurtherResults()
			);

			$isFromCache = $res->isFromCache();
			$queryLink = QueryLinker::get( $queryobj, $this->parameters );
			$error = ErrorWidget::queryError( $queryobj );
		}

		$infoText = $this->getInfoText(
			$duration,
			$isFromCache
		);

		$form = $this->buildForm(
			$urlArgs,
			$queryLink,
			$navigation,
			$infoText
		);

		// The overall form is "soft-disabled" so that when JS is fully
		// loaded, the ask module will remove this class and releases the form
		// for input
		$html = FormatterWidget::div(
			$form . $error . $result,
			[
				'id' => 'ask',
				"class" => ( $this->isBorrowedMode ? '' : 'is-disabled' )
			]
		);

		$this->getOutput()->addHTML(
			$html
		);
	}

	private function getInfoText( $duration, $isFromCache = false ) {

		$infoText = '';

		$querySource = $this->querySourceFactory->getAsString(
			isset( $this->parameters['source'] ) ? $this->parameters['source'] : null
		);

		if ( $duration > 0 ) {
			$infoText = wfMessage( 'smw-ask-query-search-info', $this->queryString, $querySource, $isFromCache, $duration )->parse();
		}

		return $infoText;
	}

	/**
	 * Generates the Search Box UI
	 *
	 * @param string $printoutstring
	 * @param string $urltail
	 *
	 * @return string
	 */
	protected function buildForm( UrlArgs $urlArgs, InfoLink $queryLink = null, $navigation = '', $infoText = '' ) {

		$html = '';
		$hideForm = false;

		$title = SpecialPage::getSafeTitleFor( 'Ask' );
		$urlArgs->set( 'eq', 'yes' );

		if ( $this->isEditMode ) {
			$html .= Html::hidden( 'title', $title->getPrefixedDBKey() );

			// Table for main query and printouts.
			$html .= QueryInputWidget::input( $this->queryString , $urlArgs->get( 'po' ) );

			// Format selection
			$html .= FormatSelectionWidget::selection( $title, $this->parameters );

			// Other options fieldset
			$html .= ParametersWidget::options( $this->parameters['format'], $this->parameters );

			$urlArgs->set( 'eq', 'no' );
			$hideForm = true;
		}

		$isEmpty = $queryLink === null;

		// Submit
		$links = LinksWidget::resultSubmitLink(
			$hideForm
		) . LinksWidget::showHideLink(
			$title,
			$urlArgs,
			$hideForm,
			$isEmpty
		) .	LinksWidget::clipboardLink(
			$queryLink
		);

		if ( !isset( $this->parameters['source'] ) || $this->parameters['source'] === '' ) {
			$links .= LinksWidget::debugLink( $title, $urlArgs, $isEmpty );
		}

		$links .= LinksWidget::embeddedCodeLink(
			$isEmpty
		) . LinksWidget::embeddedCodeBlock(
			$this->getQueryAsCodeString()
		);

		$links .= '<p></p>';

		$this->applyFinalOutputChanges(
			$links,
			$infoText
		);

		$links .= NavigationLinksWidget::wrap(
			$navigation,
			$infoText,
			$queryLink
		);

		$fieldset = Html::rawElement(
			'fieldset',
			[
				'class' => 'smw-ask-actions',
				'style' => 'margin-top:0px;'
			],
			Html::rawElement(
				'legend',
				[],
				wfMessage( 'smw-ask-search' )->escaped()
			) . '<p></p>' . $links
		);

		$html .= Html::rawElement(
			'div',
			[
				'id' => 'search',
				'class' => 'smw-ask-search'
			],
			$fieldset
		);

		return Html::rawElement(
			'form',
			[
				'action' => $GLOBALS['wgScript'],
				'name' => 'ask',
				'method' => 'get'
			],
			$html
		);
	}

	private function getQueryAsCodeString() {

		$code = $this->queryString ? htmlspecialchars( $this->queryString ) . "\n" : "\n";

		foreach ( $this->printouts as $printout ) {
			$serialization = $printout->getSerialisation( true );
			$mainlabel = isset( $this->parameters['mainlabel'] ) ? '?=' . $this->parameters['mainlabel'] . '#' : '';

			if ( $serialization !== '?#' && $serialization !== $mainlabel ) {
				$code .= ' |' . $serialization . "\n";
			}
		}

		foreach ( $this->params as $param ) {

			if ( !isset( $this->parameters[$param->getName()] ) ) {
				continue;
			}

			if ( !$param->wasSetToDefault() ) {
				$code .= ' |' . htmlspecialchars( $param->getName() ) . '=';
				$code .= htmlspecialchars( $this->parameters[$param->getName()] ) . "\n";
			}
		}

		return '{{#ask: ' . $code . '}}';
	}

	private function applyFinalOutputChanges( &$html, &$searchInfoText ) {

		if ( !$this->isBorrowedMode ) {
			return;
		}

		$borrowedMessage = $this->getRequest()->getVal( 'bMsg' );

		$searchInfoText = '';

		if ( $borrowedMessage !== null && wfMessage( $borrowedMessage )->exists() ) {
			$html = wfMessage( $borrowedMessage )->parse();
		}

		$borrowedTitle = $this->getRequest()->getVal( 'bTitle' );

		if ( $borrowedTitle !== null && wfMessage( $borrowedTitle )->exists() ) {
			$this->getOutput()->setPageTitle( wfMessage( $borrowedTitle )->text() );
		}
	}

	private function newUrlArgs() {

		$urlArgs = new UrlArgs();

		// build parameter strings for URLs, based on current settings
		$urlArgs->set( 'q', $this->queryString );

		$tmp_parray = array();

		foreach ( $this->parameters as $key => $value ) {
			if ( !in_array( $key, array( 'sort', 'order', 'limit', 'offset', 'title' ) ) ) {
				$tmp_parray[$key] = $value;
			}
		}

		$urlArgs->set( 'p', Infolink::encodeParameters( $tmp_parray ) );
		$printoutstring = '';

		/**
		 * @var PrintRequest $printout
		 */
		foreach ( $this->printouts as $printout ) {
			$printoutstring .= $printout->getSerialisation( true ) . "\n";
		}

		if ( $printoutstring !== '' ) {
			$urlArgs->set( 'po', $printoutstring );
		}

		if ( array_key_exists( 'sort', $this->parameters ) ) {
			$urlArgs->set( 'sort', $this->parameters['sort'] );
		}

		if ( array_key_exists( 'order', $this->parameters ) ) {
			$urlArgs->set( 'order', $this->parameters['order'] );
		}

		if ( $this->getRequest()->getCheck( 'bTitle' ) ) {
			$urlArgs->set( 'bTitle', $this->getRequest()->getVal( 'bTitle' ) );
			$urlArgs->set( 'bMsg', $this->getRequest()->getVal( 'bMsg' ) );
		}

		return $urlArgs;
	}

	private function getQueryResult() {

		$res = null;
		$debug = '';
		$duration = 0;
		$queryobj = null;

		// FIXME: this is a hack
		QueryProcessor::addThisPrintout( $this->printouts, $this->parameters );

		$params = QueryProcessor::getProcessedParams(
			$this->parameters,
			$this->printouts
		);

		$this->parameters['format'] = $params['format']->getValue();
		$this->params = $params;

		$queryobj = QueryProcessor::createQuery(
			$this->queryString,
			$params,
			QueryProcessor::SPECIAL_PAGE,
			$this->parameters['format'],
			$this->printouts
		);

		$queryobj->setOption( SMWQuery::PROC_CONTEXT, 'SpecialAsk' );

		/**
		 * @var QueryEngine $queryEngine
		 */
		$queryEngine = $this->querySourceFactory->get(
			$params['source']->getValue()
		);

		/**
		 * @var QueryResult $res
		 */
		$res = $queryEngine->getQueryResult( $queryobj );

		$duration = number_format(
			$queryobj->getOption( SMWQuery::PROC_QUERY_TIME ), 4, '.', ''
		);

		// Allow to generate a debug output
		if ( $this->getRequest()->getVal( 'debug' ) && $params['source']->getValue() === '' ) {

			$queryobj = QueryProcessor::createQuery(
				$this->queryString,
				$params,
				QueryProcessor::SPECIAL_PAGE,
				'debug',
				$this->printouts
			);

			$debug = $queryEngine->getQueryResult( $queryobj );
		}

		return [ $res, $debug, $duration, $queryobj ];
	}

	/**
	 * FIXME MW 1.25
	 */
	private function addExternalHelpLinkFor( $key ) {

		if ( !method_exists( $this, 'addHelpLink' ) ) {
			return null;
		}

		$this->addHelpLink( wfMessage( $key )->escaped(), true );
	}

}