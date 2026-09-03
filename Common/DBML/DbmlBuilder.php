<?php
namespace axenox\GenAI\Common\DBML;

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
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Model\MetaRelationInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Throwable;

/**
 * Builds DBML schemas from ExFace metaobjects.
 */
class DbmlBuilder
{
    private WorkbenchInterface $workbench;

    /** @var MetaObjectInterface[] */
    private array $objects;

    /** @var array<string, bool> */
    private array $objectKeys = [];

    /**
     * @param WorkbenchInterface $workbench
     * @param MetaObjectInterface[] $objects
     */
    public function __construct(WorkbenchInterface $workbench, array $objects)
    {
        $this->workbench = $workbench;
        $this->objects = $objects;
        foreach ($objects as $object) {
            $this->objectKeys[$this->getKeyOfTable($object)] = true;
        }
    }

    /**
     * Renders the configured metaobjects as DBML.
     *
     * @return string
     */
    public function buildDBML() : string
    {
        $indent = '  ';
        $dbml = '';
        $array = $this->buildArray();

        foreach ($array['Tables'] as $tableData) {
            $dbml .= 'Table ' . $tableData['Table'] . ' {' . PHP_EOL;
            foreach ($tableData['Columns'] as $columnData) {
                $dbml .= $indent . implode(' ', $columnData) . PHP_EOL;
            }
            $dbml .= '}' . PHP_EOL;
        }
        foreach ($array['Enums'] as $name => $enumValues) {
            $dbml .= 'Enum ' . $name . ' {' . PHP_EOL;
            foreach ($enumValues as $valueData) {
                $dbml .= $indent . implode(' ', $valueData) . PHP_EOL;
            }
            $dbml .= '}' . PHP_EOL;
        }

        return $dbml . PHP_EOL;
    }

    /**
     * Builds the intermediate DBML structure.
     *
     * @return array
     */
    public function buildArray() : array
    {
        $result = [
            'Tables' => [],
            'Enums' => []
        ];
        $tableNames = [];
        foreach ($this->objects as $object) {
            $tableName = $this->buildDbmlTableName($object);
            if ($tableName === null || isset($tableNames[$tableName])) {
                continue;
            }
            $tableNames[$tableName] = true;
            $objectData = $this->buildDbmlArrayForObject($object);
            $result['Tables'] = array_merge($result['Tables'], $objectData['Tables']);
            $result['Enums'] = array_merge($result['Enums'], $objectData['Enums']);
        }
        return $result;
    }

    /**
     * Builds the intermediate DBML structure for one metaobject.
     *
     * @param MetaObjectInterface $object
     * @return array
     */
    protected function buildDbmlArrayForObject(MetaObjectInterface $object) : array
    {
        $tableName = $this->buildDbmlTableName($object);
        if ($tableName === null) {
            return ['Tables' => [], 'Enums' => []];
        }

        $enums = [];
        $columns = [];
        foreach ($object->getAttributes() as $attribute) {
            $columnName = $this->buildDbmlColName($attribute);
            if ($columnName === null || isset($columns[$columnName])) {
                continue;
            }

            $column = [
                'name' => $columnName,
                'type' => $this->buildDbmlColType($attribute->getDataType(), $attribute)
            ];
            $settings = $this->buildDbmlColSettings($attribute);
            if (! empty($settings)) {
                $column['settings'] = '[' . implode(', ', $settings) . ']';
            }
            $columns[$columnName] = $column;

            $dataType = $attribute->getDataType();
            if ($dataType instanceof EnumDataTypeInterface) {
                $enumValues = [];
                foreach ($dataType->getLabels() as $value => $label) {
                    $enumValues[] = [
                        'name' => $value === null || $value === '' ? null : $this->escapeString($value),
                        'settings' => '[note: ' . $this->escapeString($label) . ']'
                    ];
                }
                $enums[$this->getKeyOfEnum($dataType, $attribute)] = $enumValues;
            }
        }

        return [
            'Tables' => [
                $this->getKeyOfTable($object) => [
                    'Table' => $tableName,
                    'Columns' => array_values($columns)
                ]
            ],
            'Enums' => $enums
        ];
    }

    /**
     * Returns the stable key used for a table in the intermediate structure.
     *
     * @param MetaObjectInterface $object
     * @return string
     */
    protected function getKeyOfTable(MetaObjectInterface $object) : string
    {
        return $object->getAliasWithNamespace();
    }

    /**
     * Returns the DBML enum name for a data type.
     *
     * @param DataTypeInterface $dataType
     * @param MetaAttributeInterface $attribute
     * @return string
     */
    protected function getKeyOfEnum(DataTypeInterface $dataType, MetaAttributeInterface $attribute) : string
    {
        return StringDataType::convertCaseDelimiterToCamel($dataType->getAliasWithNamespace(), '.', false);
    }

    /**
     * Returns the DBML column name for a meta-attribute.
     *
     * @param MetaAttributeInterface $attribute
     * @return string|null
     */
    protected function buildDbmlColName(MetaAttributeInterface $attribute) : ?string
    {
        return '"' . $attribute->getName() . '"';
    }

    /**
     * Builds DBML settings for a meta-attribute.
     *
     * @param MetaAttributeInterface $attribute
     * @return array
     */
    protected function buildDbmlColSettings(MetaAttributeInterface $attribute) : array
    {
        $settings = [];
        if ($attribute->isUidForObject()) {
            $settings[] = 'pk';
        }
        if ($attribute->isLabelForObject()) {
            $settings[] = 'unique';
        }
        if ($attribute->isRequired()) {
            $settings[] = 'not null';
        }
        if ($attribute->isRelation()) {
            try {
                $relationship = $this->buildDbmlColRelationship($attribute->getRelation());
                if ($relationship !== null) {
                    $settings[] = 'ref: ' . $relationship;
                }
            } catch (Throwable $exception) {
                $this->workbench->getLogger()->logException($exception, LoggerInterface::WARNING);
            }
        }

        $description = $this->buildDbmlColDescription($attribute);
        if ($description !== null) {
            $settings[] = 'note: ' . $this->escapeString(StringDataType::stripLineBreaks($description));
        }
        return $settings;
    }

    /**
     * Escapes a DBML string value.
     *
     * @param string $string
     * @return string
     */
    protected function escapeString(string $string) : string
    {
        return json_encode($string, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns the note for a DBML column.
     *
     * @param MetaAttributeInterface $attribute
     * @return string|null
     */
    protected function buildDbmlColDescription(MetaAttributeInterface $attribute) : ?string
    {
        $description = $attribute->getShortDescription();
        if ($description === null) {
            return null;
        }
        $description = trim(trim($description), '.');
        return $description === '' ? null : $description;
    }

    /**
     * Maps an ExFace data type to a DBML type.
     *
     * @param DataTypeInterface $dataType
     * @param MetaAttributeInterface|null $attribute
     * @return string
     */
    protected function buildDbmlColType(DataTypeInterface $dataType, MetaAttributeInterface $attribute = null) : string
    {
        switch (true) {
            case $dataType instanceof IntegerDataType:
            case $dataType instanceof TimeDataType:
                return 'integer';
            case $dataType instanceof NumberDataType:
                return 'number';
            case $dataType instanceof BooleanDataType:
                return 'boolean';
            case $dataType instanceof ArrayDataType:
                return 'array';
            case $dataType instanceof EnumDataTypeInterface:
                return $this->getKeyOfEnum($dataType, $attribute);
            case $dataType instanceof DateTimeDataType:
                return 'datetime';
            case $dataType instanceof DateDataType:
                return 'date';
            case $dataType instanceof BinaryDataType:
            case $dataType instanceof StringDataType:
            default:
                return 'string';
        }
    }

    /**
     * Builds a DBML reference for a relation within the configured object set.
     *
     * @param MetaRelationInterface $relation
     * @return string|null
     */
    protected function buildDbmlColRelationship(MetaRelationInterface $relation) : ?string
    {
        $rightObject = $relation->getRightObject();
        if (! isset($this->objectKeys[$this->getKeyOfTable($rightObject)])) {
            return null;
        }
        $rightKey = $this->buildDbmlColName($relation->getRightKeyAttribute());
        $rightTable = $this->buildDbmlTableName($rightObject);
        if ($rightKey === null || $rightTable === null) {
            return null;
        }

        switch ($relation->getCardinality()) {
            case RelationCardinalityDataType::ONE_TO_ONE:
                $sign = '-';
                break;
            case RelationCardinalityDataType::ONE_TO_N:
                $sign = '<';
                break;
            case RelationCardinalityDataType::N_TO_ONE:
            default:
                $sign = '>';
        }
        return $sign . ' ' . $rightTable . '.' . $rightKey;
    }

    /**
     * Returns the DBML table name for a metaobject.
     *
     * @param MetaObjectInterface $object
     * @return string|null
     */
    protected function buildDbmlTableName(MetaObjectInterface $object) : ?string
    {
        return '"' . $object->getName() . '"';
    }
}