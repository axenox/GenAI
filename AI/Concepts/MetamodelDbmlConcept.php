<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Common\DBML\DbmlBuilder;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * Adds selected ExFace metaobjects to AI instructions as a conceptual DBML schema.
 * 
 * Use `object_filters` to select a small, relevant set of objects. The generated schema uses
 * designer-facing object and attribute names and includes data types, enums, and relationships.
 * 
 * ## Example
 * 
 * ```json
 * {
 *   "alias": "axenox.GenAI.MetamodelDbmlConcept",
 *   "object_filters": {
 *     "operator": "AND",
 *     "conditions": [
 *       {"expression": "APP__ALIAS", "comparator": "==", "value": "my.App"}
 *     ]
 *   }
 * }
 * 
 * ```
 */
class MetamodelDbmlConcept extends AbstractConcept
{

    private $objectFilterCallback = null;

    private $objectFilterUxon = null;

    private $objectCache = null;
    

    public function setObjectFilterCallback(callable $objectFilter) : AiConceptInterface
    {
        $this->objectFilterCallback = $objectFilter;
        $this->objectCache = null;
        return $this;
    }

    /**
     * Condition group to filter meta objects
     * 
     * @uxon-property object_filters
     * @uxon-type \exface\Core\CommonLogic\Model\ConditionGroup
     * @uxon-template {"operator": "AND","conditions":[{"expression": "","comparator": "==","value": ""}]}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxonConditionGroup
     * @return \axenox\GenAI\AI\Concepts\MetamodelDbmlConcept
     */
    protected function setObjectFilters(UxonObject $uxonConditionGroup) : MetamodelDbmlConcept
    {
        $this->objectFilterUxon = $uxonConditionGroup;
        return $this;
    }

    /**
     * 
     * @return UxonObject|null
     */
    protected function getObjectFiltersUxon() : ?UxonObject
    {
        return $this->objectFilterUxon;
    }

    protected function getObjectAliases() : array
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.OBJECT');
        $aliasCol = $ds->getColumns()->addFromExpression('ALIAS_WITH_NS');
        if (null !== $filtersUxon = $this->getObjectFiltersUxon()) {
            $ds->setFilters(ConditionGroupFactory::createFromUxon($this->getWorkbench(), $filtersUxon, $ds->getMetaObject()));
            if ($ds->getFilters()->isEmpty(true)) {
                throw new AiConceptConfigurationError($this, 'Cannot use a DBML concept without `filters` or with empty filter values!');
            }
        }
        $ds->dataRead();
        return $aliasCol->getValues();
    }

    protected function getObjects() : array
    {
        if (null === $this->objectCache) {
            $this->objectCache = [];
            $failedObjects = [];
            $filterCallback = $this->objectFilterCallback;
            foreach ($this->getObjectAliases() as $alias) {
                try {
                    $obj = MetaObjectFactory::createFromString($this->getWorkbench(), $alias);
                    if ($this->includesObject($obj) && ($filterCallback === null || $filterCallback($obj) === true)) {
                        $this->objectCache[] = $obj;
                    }
                } catch (\Throwable $e) {
                    $failedObjects[] = $alias;
                }
                
            }
        }
        return $this->objectCache;
    }

    /**
     * Renders the selected metaobjects as DBML.
     *
     * @return string
     */
    public function buildDBML() : string
    {
        return $this->createDbmlBuilder()->buildDBML();
    }

    /**
     * Builds the intermediate DBML structure for compatibility with existing callers.
     *
     * @return array
     */
    public function buildArray() : array
    {
        return $this->createDbmlBuilder()->buildArray();
    }

    /**
     * Creates the reusable renderer for the selected metaobjects.
     *
     * @return DbmlBuilder
     */
    protected function createDbmlBuilder() : DbmlBuilder
    {
        return new DbmlBuilder($this->getWorkbench(), $this->getObjects());
    }

    /**
     * 
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = parent::exportUxonObject();
        // TODO
        return $uxon;
    }

    protected function getOutput(): string
    {
        return $this->buildDBML();
    }

    protected function includesObject(MetaObjectInterface $obj) : bool
    {
        return true;
    }
}