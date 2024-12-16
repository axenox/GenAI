<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptIncompleteError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\RelationCardinalityDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\TimeDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Model\MetaRelationInterface;

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
                throw new AiConceptIncompleteError('Cannot use a DBML concept without `filters` or with empty filter values!');
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
                    if ($filterCallback === null || $filterCallback($obj) === true) {
                        $this->objectCache[] = $obj;
                    }
                } catch (\Throwable $e) {
                    $failedObjects[] = $alias;
                }
                
            }
        }
        return $this->objectCache;
    }

    public function buildDBML() : string
    {
        $indent = '  ';
        $dbml = '';
        $array = $this->buildArray();

        
        foreach ($array['Tables'] as $name => $tblData) {
            $dbml .= 'Table ' . $tblData['Table'] . ' {' . PHP_EOL;
            
            foreach ($tblData['Columns'] as $colData) {
                $dbml .= $indent . implode(' ', $colData) . PHP_EOL;
                
            }
            $dbml .= '}' . PHP_EOL; 
        }
        foreach ($array['Enums'] as $name => $enumVals) 
        {
            $dbml .= 'Enum '. $name . '{';
            foreach ($enumVals as $valData) {
                $dbml .= $indent . implode(' ', $valData) . PHP_EOL;
            }
            $dbml .= '}' . PHP_EOL ;
        }
        $dbml .= PHP_EOL;
        
        return $dbml;
    }


    /**
     * Renders DBML as an array
     * 
     * ```
     * [
     *   "Tables": [
     *     "exf_page" => [
     *       "Table": "exf_page",
     *       "Columns": [
     *          [
     *              "name",
     *              "type",
     *              "settings": "[pk, noe: ..., ref: ...]"
     *          ]
     *       ]
     *     ]
     *   ],
     *   "Enums":[]
     * ]
     * ```
     * 
     * @return array
     */

    public function buildArray() : array{
    $array = [];
    foreach ($this->getObjects() as $obj) {
        $array = array_merge_recursive($array, $this->buildDbmlArrayForObject($obj));
    }
    return $array;
    }

    protected function buildDbmlArrayForObject(MetaObjectInterface $obj) : array
    {
        $tableName = $this->buildDbmlTableName($obj);
        if ($tableName === null) {
            return [];
        }

        $enums = [];
        $table = [
            'Table' => $tableName,
            'Columns' => []
        ];
        foreach ($obj->getAttributes() as $attribute) { 
            $colName = $this->buildDbmlColName($attribute);
            if ($colName !== null) {
                $col = [
                    'name' => $colName,
                    'type' => $this->buildDbmlColType($attribute->getDataType(), $attribute)
                ];
                $settings = $this->buildDbmlColSettings($attribute);
                if (! empty($settings)) {
                    $col['settings'] = '[' . implode(', ', $settings) . ']';
                }
                $table['Columns'][] = $col;

                $datatype = $attribute->getDataType();
                if ($datatype instanceof EnumDataTypeInterface) {
                    $enumVals = [];
                    foreach($datatype->getLabels() as $value => $label){
                        $enumVals[] = [ 
                            'name' => $value,
                            'settings' => '[note: ' . $label . ']'
                        ];
                    }
                    $enums[$this->getKeyOfEnum($datatype, $attribute)] = $enumVals;
                }
            } else {
                // TODO use custom-SQL columns somehow. Maybe create some sort of hints for the AI
                // to use these prebuild SQL statements? But we would need to resolve the placeholders
                // then...
            }
            
            // TODO add complex relations (e.g. over compound attributes) here if they cannot be handled
            // inside the table defs

        }
        return [
            'Tables' => [$this->getKeyOfTable($obj) => $table],
            'Enums'=> $enums
        ];
    }

    protected function getKeyOfTable(MetaObjectInterface $obj) : string
    {
        return $obj->getAliasWithNamespace();
    }

    protected function getKeyOfEnum(DataTypeInterface $datatype, MetaAttributeInterface $attr) : string
    {
        return $datatype->getAliasWithNamespace();
    }

    protected function buildDbmlColName(MetaAttributeInterface $attr) : ?string
    {
        return '"' . $attr->getName() . '"';
    }

    protected function buildDbmlColSettings(MetaAttributeInterface $attr) : array
    {
        $arr = [];
        if ($attr->isUidForObject()) {
            $arr[] = 'pk';
        }
        if ($attr->isLabelForObject()) {
            $arr[] = 'unique';
        }
        if ($attr->isRequired()) {
            $arr[] = 'not null';
        }

        /* TODO skip defaults for now as it is unclear, how to evaluate the expressions properly
        if ($attr->hasDefaultValue()) {
            $arr[] = 'default: ' . $attr->getDefaultValue()->evaluate();
        }
        */

        if ($attr->isRelation()) {
            $arr[] = 'ref: ' . $this->buildDbmlColRelationship($attr->getRelation());
        }

        if (null !== $descr = $this->buildDbmlColDescription($attr)) {
            $arr[] = 'note: ' . StringDataType::stripLineBreaks($descr);
        }

        return $arr;
    }

    protected function buildDbmlColDescription(MetaAttributeInterface $attr) : ?string
    {
        $descr = $attr->getShortDescription();
        return $descr === null || trim($descr) === '' ? null : $descr;
    }

    protected function buildDbmlColType(DataTypeInterface $dataType, MetaAttributeInterface $attribute = null) : string 
    {

        switch (true) {
            case $dataType instanceof IntegerDataType:
            case $dataType instanceof TimeDataType:
                $schema = 'integer';
                break;
            case $dataType instanceof NumberDataType:
                $schema = 'number';
                break;
            case $dataType instanceof BooleanDataType:
                $schema = 'boolean';
                break;
            case $dataType instanceof ArrayDataType:
                $schema = 'array';
                break;
            case $dataType instanceof EnumDataTypeInterface:
                $schema = $this->getKeyOfEnum($dataType, $attribute);
                break;
            case $dataType instanceof DateTimeDataType:
                $schema = 'datetime';
                break;
            case $dataType instanceof DateDataType:
                $schema = 'date';
                break;
            case $dataType instanceof BinaryDataType:
                $schema ='string';
                break;
            case $dataType instanceof StringDataType:
            default:
                $schema = 'string';
                break;
        }
        return $schema;
        
    }

    protected function buildDbmlColRelationship(MetaRelationInterface $rel) : string 
    {
        switch ($rel->getCardinality()) {
            case RelationCardinalityDataType::ONE_TO_ONE: $sign = '-'; break;
            case RelationCardinalityDataType::N_TO_ONE: $sign = '>'; break;
            case RelationCardinalityDataType::ONE_TO_N: $sign = '<'; break;
            default:
                $sign = '>';
        }
        return "$sign {$this->buildDbmlTableName($rel->getRightObject())}";
    }

    protected function buildDbmlTableName(MetaObjectInterface $obj) : ?string
    {
        return '"' . $obj->getName() . '"';
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

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface::resolve()
     */ 
    public function resolve(array $placeholders) : array
    {
        $phVals = [];
        $phVals[$this->getPlaceholder()] = $this->buildDBML();
        return $phVals;
    }
}