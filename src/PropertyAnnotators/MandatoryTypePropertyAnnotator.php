<?php

namespace SMW\PropertyAnnotators;

use SMW\DataTypeRegistry;
use SMW\DataValueFactory;
use SMW\DIProperty;

/**
 * @license GNU GPL v2+
 * @since 2.2
 *
 * @author mwjames
 */
class MandatoryTypePropertyAnnotator extends PropertyAnnotatorDecorator {

	/**
	 * Indicates a forced removal for imported type annotation
	 */
	const IMPO_REMOVED_TYPE = 'mandatorytype.propertyannotator.impo.removed.type';

	/**
	 * Indicates a forced removal for subproperty/parent type mismatch
	 */
	const ENFORCED_PARENTTYPE_INHERITANCE = 'mandatorytype.propertyannotator.subproperty.parent.type.inheritance';

	/**
	 * @var boolean
	 */
	private $subpropertyParentTypeInheritance = false;

	/**
	 * @since 3.1
	 *
	 * @param boolean $subpropertyParentTypeInheritance
	 */
	public function setSubpropertyParentTypeInheritance( $subpropertyParentTypeInheritance ) {
		$this->subpropertyParentTypeInheritance = (bool)$subpropertyParentTypeInheritance;
	}

	protected function addPropertyValues() {

		$subject = $this->getSemanticData()->getSubject();

		if ( $subject->getNamespace() !== SMW_NS_PROPERTY ) {
			return;
		}

		$property = DIProperty::newFromUserLabel(
			str_replace( '_', ' ', $subject->getDBKey() )
		);

		if ( !$property->isUserDefined() ) {
			return;
		}

		$this->enforceMandatoryTypeForImportVocabulary();

		// #3528
		$this->enforceMandatoryTypeForSubproperty();
	}

	private function enforceMandatoryTypeForSubproperty() {

		if ( !$this->subpropertyParentTypeInheritance ) {
			return;
		}

		$property = new DIProperty( '_SUBP' );
		$semanticData = $this->getSemanticData();

		if ( !$semanticData->hasProperty( $property ) ) {
			return;
		}

		$dataItems = $semanticData->getPropertyValues(
			$property
		);

		$dataItem = end( $dataItems );

		$parentProperty = new DIProperty( $dataItem->getDBKey() );
		$semanticData->removeProperty( new DIProperty( '_TYPE' ) );

		$dataValue = DataValueFactory::getInstance()->newDataValueByProperty(
			new DIProperty( '_TYPE' ),
			$parentProperty->findPropertyTypeID()
		);

		$semanticData->setOption( self::ENFORCED_PARENTTYPE_INHERITANCE, $dataItem );
		$semanticData->addDataValue( $dataValue );
	}

	private function enforceMandatoryTypeForImportVocabulary() {

		$property = new DIProperty( '_IMPO' );

		$dataItems = $this->getSemanticData()->getPropertyValues(
			$property
		);

		if ( $dataItems === null || $dataItems === [] ) {
			return;
		}

		$this->addTypeFromImportVocabulary( $property, current( $dataItems ) );
	}

	private function addTypeFromImportVocabulary( $property, $dataItem ) {

		$importValue = DataValueFactory::getInstance()->newDataValueByItem(
			$dataItem,
			$property
		);

		if ( strpos( $importValue->getTermType(), ':' ) === false ) {
			return;
		}

		$property = new DIProperty( '_TYPE' );

		list( $ns, $type ) = explode( ':', $importValue->getTermType(), 2 );

		$typeId = DataTypeRegistry::getInstance()->findTypeId( $type );

		if ( $typeId === '' ) {
			return;
		}

		$dataValue = DataValueFactory::getInstance()->newDataValueByProperty(
			$property,
			$typeId
		);

		$this->replaceAnyTypeByImportType( $property, $dataValue );
	}

	private function replaceAnyTypeByImportType( DIProperty $property, $dataValue ) {

		foreach ( $this->getSemanticData()->getPropertyValues( $property ) as $dataItem ) {
			$this->getSemanticData()->setOption( self::IMPO_REMOVED_TYPE, $dataItem );

			$this->getSemanticData()->removePropertyObjectValue(
				$property,
				$dataItem
			);
		}

		$this->getSemanticData()->addDataValue( $dataValue );
	}

}
