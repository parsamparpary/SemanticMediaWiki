<?php

namespace SMW\SPARQLStore\QueryEngine;

use SMW\SPARQLStore\QueryEngine\Condition\Condition;
use SMW\SPARQLStore\QueryEngine\Condition\FalseCondition;
use SMW\SPARQLStore\QueryEngine\Condition\TrueCondition;
use SMW\SPARQLStore\QueryEngine\Condition\WhereCondition;
use SMW\SPARQLStore\QueryEngine\Condition\SingletonCondition;
use SMW\SPARQLStore\QueryEngine\Condition\FilterCondition;

use SMW\Query\Language\Description;
use SMW\Query\Language\SomeProperty;
use SMW\Query\Language\NamespaceDescription;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Disjunction;
use SMW\Query\Language\ClassDescription;
use SMW\Query\Language\ValueDescription;
use SMW\Query\Language\ConceptDescription;
use SMW\Query\Language\ThingDescription;

use SMW\DataTypeRegistry;
use SMW\Store;
use SMW\DIProperty;
use SMW\DIWikiPage;

use SMWDataItem as DataItem;
use SMWDIBlob as DIBlob;
use SMWExporter as Exporter;
use SMWTurtleSerializer as TurtleSerializer;
use SMWExpNsResource as ExpNsResource;
use SMWExpLiteral as ExpLiteral;
use SMWExpElement as ExpElement;

use RuntimeException;

/**
 * Condition mapping from Query objects to SPARQL
 *
 * @ingroup SMWStore
 *
 * @license GNU GPL v2+
 * @since 2.0
 *
 * @author Markus Krötzsch
 */
class CompoundConditionBuilder {

	/**
	 * @var ConditionBuilderStrategyFinder
	 */
	private $conditionBuilderStrategyFinder = null;

	/**
	 * Counter used to generate globally fresh variables.
	 * @var integer
	 */
	private $variableCounter = 0;

	/**
	 * Sortkeys that are being used while building the query conditions
	 * @var array
	 */
	private $sortkeys = array();

	/**
	 * The name of the SPARQL variable that represents the query result
	 * @var string
	 */
	private $resultVariable = 'result';

	/**
	 * @since 2.0
	 *
	 * @param string $resultVariable
	 */
	public function setResultVariable( $resultVariable ) {
		$this->resultVariable = $resultVariable;
		return $this;
	}

	/**
	 * @since 2.0
	 *
	 * @param array $sortkeys
	 */
	public function setSortKeys( $sortkeys ) {
		$this->sortkeys = $sortkeys;
		return $this;
	}

	/**
	 * @since 2.1
	 *
	 * @return array
	 */
	public function getSortKeys() {
		return $this->sortkeys;
	}

	/**
	 * Get a Condition object for an Description.
	 *
	 * This conversion is implemented by a number of recursive functions,
	 * and this is the main entry point for this recursion. In particular,
	 * it resets global variables that are used for the construction.
	 *
	 * If property value variables should be recorded for ordering results
	 * later on, the keys of the respective properties need to be given in
	 * sortkeys earlier.
	 *
	 * @param Description $description
	 *
	 * @return Condition
	 */
	public function buildCondition( Description $description ) {
		$this->variableCounter = 0;
		$condition = $this->mapDescriptionToCondition( $description, $this->resultVariable, null );
		$this->addMissingOrderByConditions( $condition );
		return $condition;
	}

	/**
	 * Build the condition (WHERE) string for a given Condition.
	 * The function also expresses the single value of
	 * SingletonCondition objects in the condition, which may
	 * lead to additional namespaces for serializing its URI.
	 *
	 * @param Condition $condition
	 *
	 * @return string
	 */
	public function convertConditionToString( Condition &$condition ) {

		$conditionAsString = $condition->getWeakConditionString();

		if ( ( $conditionAsString === '' ) && !$condition->isSafe() ) {
			$swivtPageResource = Exporter::getSpecialNsResource( 'swivt', 'page' );
			$conditionAsString = '?' . $this->resultVariable . ' ' . $swivtPageResource->getQName() . " ?url .\n";
		}

		$conditionAsString .= $condition->getCondition();

		if ( $condition instanceof SingletonCondition ) { // prepare for ASK, maybe rather use BIND?

			$matchElement = $condition->matchElement;
			$matchElementName = TurtleSerializer::getTurtleNameForExpElement( $matchElement );

			if ( $matchElement instanceof ExpNsResource ) {
				$condition->namespaces[$matchElement->getNamespaceId()] = $matchElement->getNamespace();
			}

			$conditionAsString = str_replace( '?' . $this->resultVariable . ' ', "$matchElementName ", $conditionAsString );
		}

		return $conditionAsString;
	}

	/**
	 * Recursively create an Condition from an Description.
	 *
	 * @param $description Description
	 * @param $joinVariable string name of the variable that conditions
	 * will refer to
	 * @param $orderByProperty mixed DIProperty or null, if given then
	 * this is the property the values of which this condition will refer
	 * to, and the condition should also enable ordering by this value
	 * @return Condition
	 */
	public function mapDescriptionToCondition( Description $description, $joinVariable, $orderByProperty ) {

		if ( $description instanceof Conjunction ) {
			return $this->buildConjunctionCondition( $description, $joinVariable, $orderByProperty );
		} elseif ( $description instanceof Disjunction ) {
			return $this->buildDisjunctionCondition( $description, $joinVariable, $orderByProperty );
		} elseif ( $description instanceof SomeProperty || $description instanceof NamespaceDescription || $description instanceof ClassDescription || $description instanceof ValueDescription ) {
			return $this->findStrategyForDescription( $description )->buildCondition( $description, $joinVariable, $orderByProperty );
		} elseif ( $description instanceof ConceptDescription ) {
			return new TrueCondition(); ///TODO Implement concept queries
		}

		 // (e.g. ThingDescription)
		return $this->buildTrueCondition( $joinVariable, $orderByProperty );
	}

	/**
	 * Recursively create an Condition from an Conjunction.
	 *
	 * @param $description Conjunction
	 * @param $joinVariable string name, see mapDescriptionToCondition()
	 * @param $orderByProperty mixed DIProperty or null, see mapDescriptionToCondition()
	 *
	 * @return Condition
	 */
	protected function buildConjunctionCondition( Conjunction $description, $joinVariable, $orderByProperty ) {

		$subDescriptions = $description->getDescriptions();

		if ( count( $subDescriptions ) == 0 ) { // empty conjunction: true
			return $this->buildTrueCondition( $joinVariable, $orderByProperty );
		} elseif ( count( $subDescriptions ) == 1 ) { // conjunction with one element
			return $this->mapDescriptionToCondition( reset( $subDescriptions ), $joinVariable, $orderByProperty );
		}

		$condition = '';
		$filter = '';
		$namespaces = $weakConditions = $orderVariables = array();
		$singletonMatchElement = null;
		$singletonMatchElementName = '';
		$hasSafeSubconditions = false;

		foreach ( $subDescriptions as $subDescription ) {

			$subCondition = $this->mapDescriptionToCondition( $subDescription, $joinVariable, null );

			if ( $subCondition instanceof FalseCondition ) {
				return new FalseCondition();
			} elseif ( $subCondition instanceof TrueCondition ) {
				// ignore true conditions in a conjunction
			} elseif ( $subCondition instanceof WhereCondition ) {
				$condition .= $subCondition->condition;
			} elseif ( $subCondition instanceof FilterCondition ) {
				$filter .= ( $filter ? ' && ' : '' ) . $subCondition->filter;
			} elseif ( $subCondition instanceof SingletonCondition ) {
				$matchElement = $subCondition->matchElement;

				if ( $matchElement instanceOf ExpElement ) {
					$matchElementName = TurtleSerializer::getTurtleNameForExpElement( $matchElement );
				} else {
					$matchElementName = $matchElement;
				}

				if ( $matchElement instanceof ExpNsResource ) {
					$namespaces[$matchElement->getNamespaceId()] = $matchElement->getNamespace();
				}

				if ( ( !is_null( $singletonMatchElement ) ) &&
				     ( $singletonMatchElementName !== $matchElementName ) ) {
					return new FalseCondition();
				}

				$condition .= $subCondition->condition;
				$singletonMatchElement = $subCondition->matchElement;
				$singletonMatchElementName = $matchElementName;
			}

			$hasSafeSubconditions = $hasSafeSubconditions || $subCondition->isSafe();
			$namespaces = array_merge( $namespaces, $subCondition->namespaces );
			$weakConditions = array_merge( $weakConditions, $subCondition->weakConditions );
			$orderVariables = array_merge( $orderVariables, $subCondition->orderVariables );
		}

		if ( !is_null( $singletonMatchElement ) ) {
			if ( $filter !== '' ) {
				$condition .= "FILTER( $filter )";
			}

			$result = new SingletonCondition(
				$singletonMatchElement,
				$condition,
				$hasSafeSubconditions,
				$namespaces
			);

		} elseif ( $condition === '' ) {
			$result = new FilterCondition( $filter, $namespaces );
		} else {
			if ( $filter !== '' ) {
				$condition .= "FILTER( $filter )";
			}

			$result = new WhereCondition( $condition, $hasSafeSubconditions, $namespaces );
		}

		$result->weakConditions = $weakConditions;
		$result->orderVariables = $orderVariables;

		$this->addOrderByDataForProperty( $result, $joinVariable, $orderByProperty );

		return $result;
	}

	/**
	 * Recursively create an Condition from an Disjunction.
	 *
	 * @param $description Disjunction
	 * @param $joinVariable string name, see mapDescriptionToCondition()
	 * @param $orderByProperty mixed DIProperty or null, see mapDescriptionToCondition()
	 *
	 * @return Condition
	 */
	protected function buildDisjunctionCondition( Disjunction $description, $joinVariable, $orderByProperty ) {
		$subDescriptions = $description->getDescriptions();
		if ( count( $subDescriptions ) == 0 ) { // empty disjunction: false
			return new FalseCondition();
		} elseif ( count( $subDescriptions ) == 1 ) { // disjunction with one element
			return $this->mapDescriptionToCondition( reset( $subDescriptions ), $joinVariable, $orderByProperty );
		} // else: proper disjunction; note that orderVariables found in subconditions cannot be used for the whole disjunction

		$unionCondition = '';
		$filter = '';
		$namespaces = $weakConditions = array();
		$hasSafeSubconditions = false;
		foreach ( $subDescriptions as $subDescription ) {
			$subCondition = $this->mapDescriptionToCondition( $subDescription, $joinVariable, null );
			if ( $subCondition instanceof FalseCondition ) {
				// empty parts in a disjunction can be ignored
			} elseif ( $subCondition instanceof TrueCondition ) {
				return  $this->buildTrueCondition( $joinVariable, $orderByProperty );
			} elseif ( $subCondition instanceof WhereCondition ) {
				$hasSafeSubconditions = $hasSafeSubconditions || $subCondition->isSafe();
				$unionCondition .= ( $unionCondition ? ' UNION ' : '' ) .
				                   "{\n" . $subCondition->condition . "}";
			} elseif ( $subCondition instanceof FilterCondition ) {
				$filter .= ( $filter ? ' || ' : '' ) . $subCondition->filter;
			} elseif ( $subCondition instanceof SingletonCondition ) {
				$hasSafeSubconditions = $hasSafeSubconditions || $subCondition->isSafe();
				$matchElement = $subCondition->matchElement;

				if ( $matchElement instanceOf ExpElement ) {
					$matchElementName = TurtleSerializer::getTurtleNameForExpElement( $matchElement );
				} else {
					$matchElementName = $matchElement;
				}

				if ( $matchElement instanceof ExpNsResource ) {
					$namespaces[$matchElement->getNamespaceId()] = $matchElement->getNamespace();
				}

				if ( $subCondition->condition === '' ) {
					$filter .= ( $filter ? ' || ' : '' ) . "?$joinVariable = $matchElementName";
				} else {
					$unionCondition .= ( $unionCondition ? ' UNION ' : '' ) .
				                   "{\n" . $subCondition->condition . " FILTER( ?$joinVariable = $matchElementName ) }";
				}
			}
			$namespaces = array_merge( $namespaces, $subCondition->namespaces );
			$weakConditions = array_merge( $weakConditions, $subCondition->weakConditions );
		}

		if ( ( $unionCondition === '' ) && ( $filter === '' ) ) {
			return new FalseCondition();
		} elseif ( $unionCondition === '' ) {
			$result = new FilterCondition( $filter, $namespaces );
		} elseif ( $filter === '' ) {
			$result = new WhereCondition( $unionCondition, $hasSafeSubconditions, $namespaces );
		} else {
			$subJoinVariable = $this->getNextVariable();
			$unionCondition = str_replace( "?$joinVariable ", "?$subJoinVariable ", $unionCondition );
			$filter .= " || ?$joinVariable = ?$subJoinVariable";
			$result = new WhereCondition( "OPTIONAL { $unionCondition }\n FILTER( $filter )\n", false, $namespaces );
		}

		$result->weakConditions = $weakConditions;

		$this->addOrderByDataForProperty( $result, $joinVariable, $orderByProperty );

		return $result;
	}

	/**
	 * Create an Condition from an empty (true) description.
	 * May still require helper conditions for ordering.
	 *
	 * @param $joinVariable string name, see mapDescriptionToCondition()
	 * @param $orderByProperty mixed DIProperty or null, see mapDescriptionToCondition()
	 *
	 * @return Condition
	 */
	public function buildTrueCondition( $joinVariable, $orderByProperty ) {
		$result = new TrueCondition();
		$this->addOrderByDataForProperty( $result, $joinVariable, $orderByProperty );
		return $result;
	}

	/**
	 * Get a fresh unused variable name for building SPARQL conditions.
	 *
	 * @return string
	 */
	public function getNextVariable() {
		return 'v' . ( ++$this->variableCounter );
	}

	/**
	 * Extend the given SPARQL condition by a suitable order by variable,
	 * if an order by property is set.
	 *
	 * @param Condition $sparqlCondition condition to modify
	 * @param string $mainVariable the variable that represents the value to be ordered
	 * @param mixed $orderByProperty DIProperty or null
	 * @param integer $diType DataItem type id if known, or DataItem::TYPE_NOTYPE to determine it from the property
	 */
	public function addOrderByDataForProperty( Condition &$sparqlCondition, $mainVariable, $orderByProperty, $diType = DataItem::TYPE_NOTYPE ) {
		if ( is_null( $orderByProperty ) ) {
			return;
		}

		if ( $diType == DataItem::TYPE_NOTYPE ) {
			$diType = DataTypeRegistry::getInstance()->getDataItemId( $orderByProperty->findPropertyTypeID() );
		}

		$this->addOrderByData( $sparqlCondition, $mainVariable, $diType );
	}

	/**
	 * Extend the given SPARQL condition by a suitable order by variable,
	 * possibly adding conditions if required for the type of data.
	 *
	 * @param Condition $sparqlCondition condition to modify
	 * @param string $mainVariable the variable that represents the value to be ordered
	 * @param integer $diType DataItem type id
	 */
	public function addOrderByData( Condition &$sparqlCondition, $mainVariable, $diType ) {
		if ( $diType == DataItem::TYPE_WIKIPAGE ) {
			$sparqlCondition->orderByVariable = $mainVariable . 'sk';
			$skeyExpElement = Exporter::getSpecialPropertyResource( '_SKEY' );
			$sparqlCondition->weakConditions = array( $sparqlCondition->orderByVariable =>
			      "?$mainVariable " . $skeyExpElement->getQName() . " ?{$sparqlCondition->orderByVariable} .\n" );
		} else {
			$sparqlCondition->orderByVariable = $mainVariable;
		}
	}

	/**
	 * Extend the given Condition with additional conditions to
	 * ensure that it can be ordered by all requested properties. After
	 * this operation, every key in sortkeys is assigned to a query
	 * variable by $sparqlCondition->orderVariables.
	 *
	 * @param Condition $sparqlCondition condition to modify
	 */
	protected function addMissingOrderByConditions( Condition &$sparqlCondition ) {
		foreach ( $this->sortkeys as $propkey => $order ) {

			if ( !is_string( $propkey ) ) {
				throw new RuntimeException( "Expected a string value as sortkey" );
			}

			if ( !array_key_exists( $propkey, $sparqlCondition->orderVariables ) ) { // Find missing property to sort by.

				if ( $propkey === '' ) { // order by result page sortkey
					$this->addOrderByData( $sparqlCondition, $this->resultVariable, DataItem::TYPE_WIKIPAGE );
					$sparqlCondition->orderVariables[$propkey] = $sparqlCondition->orderByVariable;
				} else { // extend query to order by other property values
					$diProperty = new DIProperty( $propkey );
					$auxDescription = new SomeProperty( $diProperty, new ThingDescription() );
					$auxSparqlCondition = $this->mapDescriptionToCondition( $auxDescription, $this->resultVariable, null );
					// orderVariables MUST be set for $propkey -- or there is a bug; let it show!
					$sparqlCondition->orderVariables[$propkey] = $auxSparqlCondition->orderVariables[$propkey];
					$sparqlCondition->weakConditions[$sparqlCondition->orderVariables[$propkey]] = $auxSparqlCondition->getWeakConditionString() . $auxSparqlCondition->getCondition();
					$sparqlCondition->namespaces = array_merge( $sparqlCondition->namespaces, $auxSparqlCondition->namespaces );
				}
			}
		}
	}

	private function findStrategyForDescription( Description $description ) {

		if ( $this->conditionBuilderStrategyFinder === null ) {
			 $this->conditionBuilderStrategyFinder = new ConditionBuilderStrategyFinder( $this );
		}

		return $this->conditionBuilderStrategyFinder->findStrategyForDescription( $description );
	}

}
