<?php

namespace axenox\GenAI\Common;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Model\MetaRelationInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use InvalidArgumentException;

/**
 * UXON model to define the structure of a single data sheet
 * 
 * This can be used to force the AI to produce a valid DataSheet UXON.
 * 
 * TODO probably need a read-mode in addition to the current write mode. Will see, when we will start
 * giving the AI the ability to read data sheets
 * 
 */
class DataSheetSchema implements ICanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;

    private ?string $objectAlias = null;

    /**
     * Property name under which this schema is embedded as subsheet in a parent schema.
     */
    private ?string $name = null;

    /**
     * @var string[]
     */
    private array $requiredAttributes = [];
    
    private bool $requiredAll = true;

    /**
     * @var DataSheetSchema[]|null
     */
    private ?array $subsheets = null;
    
    private ?DataSheetSchema $parentSheet = null;

    private ?UxonObject $subsheetsUxon = null;

    private ?MetaObjectInterface $metaObject = null;

    public function __construct(WorkbenchInterface $workbench, ?UxonObject $uxon = null, ?DataSheetSchema $parentSheet = null)
    {
        $this->workbench = $workbench;

        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
        if ($parentSheet !== null) {
            $this->parentSheet = $parentSheet;
        }
    }

    public function getObjectAlias(): ?string
    {
        return $this->objectAlias;
    }

    public function getName(): ?string
    {
        return $this->name ?? $this->getMetaObject()->getAlias();
    }

    public function getEmptyDataSheet(): DataSheetInterface
    {
        return DataSheetFactory::createFromObjectIdOrAlias($this->workbench, $this->getObjectAlias());
    }
    

    /**
     * @return string[]
     */
    public function getRequiredAttributes(): array
    {
        return $this->requiredAttributes;
    }

    /**
     * @return DataSheetSchema[]
     */
    public function getSubsheets(): array
    {
        if ($this->subsheets === null) {
            $this->subsheets = [];

            if ($this->subsheetsUxon !== null) {
                foreach ($this->subsheetsUxon as $subsheetName => $subsheetUxon) {
                    if (! $subsheetUxon instanceof UxonObject) {
                        throw new \InvalidArgumentException(
                            'Invalid subsheet definition for "' . $subsheetName . '" in DataSheetSchema.'
                        );
                    }

                    $subsheet = new self($this->workbench, $subsheetUxon, $this);

                    if ($subsheet->getName() === null || trim((string) $subsheet->getName()) === '') {
                        $subsheet->setName((string) $subsheetName);
                    }

                    $this->subsheets[] = $subsheet;
                }
            }
        }

        return $this->subsheets;
    }

    public function getMetaObject(): MetaObjectInterface
    {
        if ($this->metaObject === null) {
            if ($this->objectAlias === null || $this->objectAlias === '') {
                throw new RuntimeException('DataSheetSchema requires a valid "object_alias".');
            }

            $this->metaObject = MetaObjectFactory::createFromString($this->workbench, $this->objectAlias);
        }

        return $this->metaObject;
    }

    /**
     * @return string[]
     */
    public function getAttributes(): array
    {
        $list = [];

        foreach ($this->getMetaObject()->getAttributes() as $attribute) {
            if (! $attribute instanceof MetaAttributeInterface) {
                continue;
            }

            if (! $this->shouldIncludeAttribute($attribute)) {
                continue;
            }

            $list[] = $attribute->getAlias();
        }

        return $list;
    }

    /**
     * Returns all attributes of the current schema object that represent a relation.
     *
     * If $rightObject is provided, only attributes whose relation points to that target object
     * are returned.
     *
     * @param MetaObjectInterface|null $rightObject
     * @return MetaAttributeInterface[]
     */
    public function getAttributesWithRelation(?MetaObjectInterface $rightObject = null): array
    {
        $attributes = [];
        $rightObjectAlias = $rightObject?->getAlias();

        foreach ($this->getMetaObject()->getAttributes() as $attribute) {
            if (! $attribute instanceof MetaAttributeInterface) {
                continue;
            }

            if (! $attribute->isRelation()) {
                continue;
            }

            $relation = $attribute->getRelation();
            if (! $relation instanceof MetaRelationInterface) {
                continue;
            }

            if ($rightObjectAlias !== null) {
                $targetAlias = $relation->getRightObject()->getAlias();

                if (strcasecmp($targetAlias, $rightObjectAlias) !== 0) {
                    continue;
                }
            }

            $attributes[] = $attribute;
        }

        return $attributes;
    }

    /**
     * Returns a single relation attribute of the current schema object.
     *
     * If $rightObject is provided, only attributes whose relation points to that target object
     * are considered.
     *
     * @param MetaObjectInterface|null $rightObject
     * @return MetaAttributeInterface
     */
    public function getAttributeWithRelation(?MetaObjectInterface $rightObject = null): MetaAttributeInterface
    {
        $attributes = $this->getAttributesWithRelation($rightObject);

        if (empty($attributes)) {
            throw new \RuntimeException(
                'No relation attribute found'
                . ($rightObject ? ' for target object "' . $rightObject->getAlias() . '"' : '')
                . '.'
            );
        }

        return $attributes[0];
    }

    /**
     * Returns all relations of the current schema object.
     *
     * If $rightObject is provided, only relations pointing to that target object
     * are returned.
     *
     * Multiple attributes may theoretically reference the same relation, therefore
     * duplicate relations are removed.
     *
     * @param MetaObjectInterface|null $rightObject
     * @return MetaRelationInterface[]
     */
    public function getRelations(?MetaObjectInterface $rightObject = null): array
    {
        $relations = [];
        $seen = [];

        foreach ($this->getAttributesWithRelation($rightObject) as $attribute) {
            $relation = $attribute->getRelation();

            if (! $relation instanceof MetaRelationInterface) {
                continue;
            }

            $key = $relation->getLeftObject()->getAlias() . '::' . $relation->getAliasWithModifier();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $relations[] = $relation;
        }

        return $relations;
    }

    /**
     * Returns a single relation of the current schema object.
     *
     * If $rightObject is provided, only relations pointing to that target object
     * are considered.
     *
     * @param MetaObjectInterface|null $rightObject
     * @return MetaRelationInterface
     */
    public function getRelation(?MetaObjectInterface $rightObject = null): MetaRelationInterface
    {
        $relations = $this->getRelations($rightObject);

        if (empty($relations)) {
            throw new \RuntimeException(
                'No relation found'
                . ($rightObject ? ' for target object "' . $rightObject->getAlias() . '"' : '')
                . '.'
            );
        }

        return $relations[0];
    }


    /**
     * Returns TRUE if this schema should be rendered as an array when embedded
     * under the given parent object.
     *
     * Rules:
     * - If no relation to the given parent object exists, FALSE is returned.
     * - If at least one relation exists and the reversed relation is a reverse relation,
     *   this schema is multi-valued from the parent perspective and must be rendered as an array.
     * - Otherwise it is rendered as a single object.
     *
     * @param MetaObjectInterface $parentObject
     * @return bool
     */
    public function isArrayForObject(MetaObjectInterface $parentObject): bool
    {
        $relations = $this->getRelations($parentObject);

        if ($relations === []) {
            return false;
        }

        foreach ($relations as $relation) {
            $reversedRelation = $relation->getReversedRelation();

            if ($reversedRelation->isReverseRelation()) {
                return true;
            }
        }

        return false;
    }

    public function getUidObject(): MetaAttributeInterface
    {
        foreach ($this->getMetaObject()->getAttributes() as $attribute) {
            if (! $attribute instanceof MetaAttributeInterface) {
                continue;
            }

            if ($attribute->isUidForObject()) {
                return $attribute;
            }
        }

        throw new \RuntimeException(
            'No UID attribute found for object "' . ($this->getObjectAlias() ?? 'unknown') . '".'
        );
    }


    /**
     * @return array<string, mixed>
     */
    public function generateJsonSchema(): array
    {
        return $this->generateDataSheetShema();
    }

    /**
     * Generates a JSON schema describing the UXON for the given DataSheet instance
     * 
     * ```
     *   {
     *       "object_alias": "my.App.REPORT",
     *       "rows": [
     *           {
     *               "NO": "",
     *               "DATE": "",
     *               "TITLE": "",
     *               "TOPIC": { // There is a relation from my.App.TOPIC to REPORT. So one report can contain multipl topics as subsheets
     *                   {
     *                       "object_alias": "my.App.TOPIC":
     *                       "rows": [
     *                           {"TITLE": "", "DESCRIPTION": ""} // Attributes of the topic object
     *                       ]
     *                   }
     *               }
     *           }
     *       ]
     *   }
     *
     * ```
     *
     * The JSON schema would be:
     *
     * ```
     *  {
     *   "$schema": "https://json-schema.org/draft/2020-12/schema",
     *   "type": "object",
     *   "required": ["object_alias", "rows"],
     *   "additionalProperties": false,
     *   "properties": {
     *       "object_alias": { // allowed object aliases
     *           "type": "enum",
     *           "values": [
     *               "my.App.MAIN_OBJ",
     *               "my.App.OTHER_OBJ"
     *           ]
     *       },
     *       "rows": {
     *           "type": "array",
     *           "items": {
     *               "type": "object",
     *               "required": ["NO", "DATE", "TITLE"], // All required attributes: $obj->getAttributes()->getRequired()
     *               "additionalProperties": false,
     *               "properties": { // All columns of the given data sheet
     *                   "NO": {
     *                       "type": "string" // see JsonDataType::convertDataTypeToJsonSchema()
     *                   },
     *                   "DATE": {
     *                       "type": "string"
     *                   },
     *                   "TITLE": {
     *                       "type": "string"
     *                   },
     *                   "TOPIC": { // Subsheet for topics
     *                       "type": "object",
     *                           "required": ["object_alias", "rows"],
     *                           "additionalProperties": false,
     *                           "properties": {},
     *                           "rows": {}
     *                       }
     *                   }
     *               }
     *           }
     *       }
     *   }
     *  }
     * 
     * ```
     * 
     * @return array
     */
    protected function generateDataSheetShema(): array
    {
        $properties = $this->generatePropertiesSchema();
        return [
            'type' => 'object',
            'required' => [
                'object_alias',
                // 'columns',
                'rows'
            ],
            'additionalProperties' => false,
            'properties' => [
                'object_alias' => [
                    'type' => 'string',
                    'const' => $this->getMetaObject()->getAliasWithNamespace()
                ],
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => array_keys($properties),
                        'additionalProperties' => false,
                        'properties' => $properties
                    ]
                ]
            ],
        ];
    }

    /**
     * TODO remove in favor of generateDataSheetSchema()
     * 
     * @return array<string, mixed>
     */
    protected function generateObjectSchema(): array
    {
        $properties = $this->generatePropertiesSchema();

        return [
            'type' => 'object',
            'required' => array_keys($properties),
            'additionalProperties' => false,
            'properties' => $properties,
        ];
    }

    /**
     * Generates all properties for this schema, including nested subsheets.
     *
     * For subsheets, the relation of the parent object determines whether the
     * property is generated as a single nested object or as an array of objects.
     *
     * @return array<string, mixed>
     */
    protected function generatePropertiesSchema(): array
    {
        $properties = [];
        $requiredLookup = array_flip($this->resolveRequiredAttributes());

        foreach ($this->getMetaObject()->getAttributes() as $attribute) {
            if (! $attribute instanceof MetaAttributeInterface) {
                continue;
            }

            if (! $this->shouldIncludeAttribute($attribute)) {
                continue;
            }

            $alias = $attribute->getAliasWithRelationPath();
                
            try {
                if ($attribute->isRelation()) {
                    if($this->isRelationToParent($attribute->getRelation())) {
                        // This is a relation to the parent object. Don't include it as property, because the parent will include this schema as subsheet and handle the relation from that side.
                        continue;
                    }
                    $rawSchema = $this->getRelationEnumType($attribute->getRelation());
                } else {
                    $dataType = $attribute->getDataType();
                    $rawSchema = JsonDataType::convertDataTypeToJsonSchemaType($dataType);
                }
                $properties[$alias] = $this->sanitizeSchemaForStructuredOutputs($rawSchema, $attribute);
            } catch (\Throwable $e) {
                $properties[$alias] = [
                    'type' => 'string',
                    'description' => 'Text value.',
                ];
            }

            if (! $this->isPropertyRequired($alias, $requiredLookup)) {
                $properties[$alias] = $this->makeSchemaNullable($properties[$alias]);
            }
        }

        foreach ($this->getSubsheets() as $subsheet) {
            $propertyName = $subsheet->getName();

            if (array_key_exists($propertyName, $properties)) {
                throw new RuntimeException(
                    'Subsheet property name "' . $propertyName . '" conflicts with an existing attribute in object "' .
                    ($this->getObjectAlias() ?? 'unknown') . '".'
                );
            }
            
            $properties[$propertyName] = $subsheet->generateDataSheetShema();
            

            if (! $this->isPropertyRequired($propertyName, $requiredLookup)) {
                $properties[$propertyName] = $this->makeSchemaNullable($properties[$propertyName]);
            }
        }

        return $properties;
    }
    
    protected function isRelationToParent(MetaRelationInterface $relation) : bool
    {
        if(!$this->parentSheet) return false;
        return $relation->getRightObject() === $this->parentSheet->getMetaObject();
    }
    
    protected function getRelationEnumType(MetaRelationInterface $relation): array
    {
        $dataSheet = DataSheetFactory::createFromObject($relation->getRightObject());
        $keyCol = $dataSheet->getColumns()->addFromAttribute($relation->getRightKeyAttribute());
        if ($dataSheet->getMetaObject()->hasLabelAttribute()) {
            $labelCol = $dataSheet->getColumns()->addFromLabelAttribute();
        }
        $dataSheet->dataRead(1000);
        /* TODO
        $type = JsonDataType::convertDataTypeToJsonSchemaType($keyCol->getDataType());
        $type['enum'] = $keyCol->getValues();
         */
        $type = [
            'type' => 'string',
            'values' => $keyCol->getValues()
        ];
        if ($labelCol) {
            $descr = 'Allowed values: ';
            foreach ($dataSheet->getRows() as $i => $row) {
                $descr .= $row[$keyCol->getName()] . ': ' . $row[$labelCol->getName()] . '; ';
            }
            $descr = rtrim($descr, '; ');
            $type['description'] = $descr;
        }
        return $type;
    }

    /**
     * @return string[]
     */
    protected function resolveRequiredAttributes(): array
    {
        if ($this->requiredAll) {
            return $this->getAttributes();
        }

        $required = $this->getRequiredAttributes();

        if (! empty($required)) {
            $required = array_values(array_unique(array_map('strval', $required)));
            $availableAttributes = array_flip($this->getAttributes());
            $invalid = [];

            foreach ($required as $alias) {
                if (! array_key_exists($alias, $availableAttributes)) {
                    $invalid[] = $alias;
                }
            }

            if (! empty($invalid)) {
                throw new \InvalidArgumentException(
                    'Unknown required attribute(s) in DataSheetSchema for object "' .
                    ($this->getObjectAlias() ?? 'unknown') .
                    '": ' . implode(', ', $invalid)
                );
            }

            return $required;
        }

        return [];
    }

    /**
     * Resolves which generated property names must appear in the top-level `required` list.
     *
     * Why: The object schema may include both regular attributes and subsheet properties.
     * This method maps the configured required attributes (`required_all` or
     * `require_attributes`) to the actually generated property set, so the final JSON
     * schema stays consistent with what is emitted in `properties`.
     *
     * @param array<string, mixed> $properties
     * @return string[]
     */
    protected function resolveRequiredPropertyNames(array $properties): array
    {
        if ($this->requiredAll) {
            return array_keys($properties);
        }

        $requiredLookup = array_flip($this->resolveRequiredAttributes());

        return array_values(
            array_filter(
                array_keys($properties),
                static fn(string $propertyName): bool => array_key_exists($propertyName, $requiredLookup)
            )
        );
    }

    /**
     * Checks whether a single property should be treated as required for this schema.
     *
     * Why: `generatePropertiesSchema()` needs a fast, centralized decision to determine
     * whether a property may be nullable. Keeping the decision in one helper avoids
     * duplicating requiredness rules across attribute and subsheet handling.
     *
     * @param array<string, bool|int> $requiredLookup
     */
    protected function isPropertyRequired(string $propertyName, array $requiredLookup): bool
    {
        if ($this->requiredAll) {
            return true;
        }

        return array_key_exists($propertyName, $requiredLookup);
    }

    /**
     * Ensures a property schema also accepts `null` in addition to its original type.
     *
     * Why: Non-required properties should explicitly allow null values so callers can
     * provide an empty value while still conforming to strict structured-output schemas.
     * The method preserves existing schema shape (`anyOf`, scalar `type`, or type arrays)
     * and only adds `null` when missing.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function makeSchemaNullable(array $schema): array
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $variant) {
                if (is_array($variant) && ($variant['type'] ?? null) === 'null') {
                    return $schema;
                }
            }

            $schema['anyOf'][] = ['type' => 'null'];
            return $schema;
        }

        if (isset($schema['type']) && is_string($schema['type'])) {
            if ($schema['type'] !== 'null') {
                $schema['type'] = [$schema['type'], 'null'];
            }

            return $schema;
        }

        if (isset($schema['type']) && is_array($schema['type'])) {
            if (! in_array('null', $schema['type'], true)) {
                $schema['type'][] = 'null';
            }

            return $schema;
        }

        $schema['type'] = ['string', 'null'];

        return $schema;
    }

    protected function shouldIncludeAttribute(MetaAttributeInterface $attribute): bool
    {
        if ($attribute->isUidForObject()) {
            return false;
        }

        if ($attribute->isSystem()) {
            return false;
        }

        if (! $attribute->isWritable()) {
            return false;
        }

        if (! $attribute->isEditable()) {
            return false;
        }

        if ($attribute->hasCalculation()) {
            return false;
        }

        if ($attribute->hasFixedValue()) {
            return false;
        }

        if ($attribute->isHidden()) {
            return false;
        }

        // Relation attributes are excluded from the flat property schema.
        // Related objects must be modeled explicitly as subsheets.
        if ($attribute->isRelated()) {
            return false;
        }

        return true;
    }

    /**
     * Normalizes and sanitizes a JSON schema for usage with LLM structured outputs.
     *
     * This method reduces an arbitrary JSON schema to a strict, OpenAI-compatible subset by:
     * - Filtering unsupported or irrelevant schema keys
     * - Recursively sanitizing nested structures
     * - Enforcing required defaults
     * - Making object schemas strict
     * - Ensuring arrays always define an items schema
     * - Augmenting descriptions with human-readable type hints
     *
     * @param array<string, mixed> $schema
     * @param MetaAttributeInterface|null $attribute
     * @return array<string, mixed>
     */
    protected function sanitizeSchemaForStructuredOutputs(array $schema, ?MetaAttributeInterface $attribute = null): array
    {
        $clean = [];
        $allowedKeys = [
            'type',
            'properties',
            'items',
            'required',
            'enum',
            'anyOf',
            'description',
            'additionalProperties',
        ];
        
        if (! $schema['description']) {
            $schema['description'] = $this->getattributeDescription($attribute);
        }

        foreach ($schema as $key => $value) {
            if (! in_array($key, $allowedKeys, true)) {
                continue;
            }

            if ($key === 'properties' && is_array($value)) {
                $clean[$key] = [];
                foreach ($value as $propName => $propSchema) {
                    if (is_array($propSchema)) {
                        $clean[$key][$propName] = $this->sanitizeSchemaForStructuredOutputs($propSchema);
                    }
                }
                continue;
            }

            if ($key === 'items' && is_array($value)) {
                $clean[$key] = $this->sanitizeSchemaForStructuredOutputs($value);
                continue;
            }

            if ($key === 'anyOf' && is_array($value)) {
                $clean[$key] = [];
                foreach ($value as $part) {
                    if (is_array($part)) {
                        $clean[$key][] = $this->sanitizeSchemaForStructuredOutputs($part);
                    }
                }
                continue;
            }

            $clean[$key] = $value;
        }

        if (! isset($clean['type'])) {
            $clean['type'] = 'string';
        }

        if (($clean['type'] ?? null) === 'object') {
            $clean['additionalProperties'] = false;
            $clean['properties'] = $clean['properties'] ?? [];
            $clean['required'] = $clean['required'] ?? array_keys($clean['properties']);
        }

        if (($clean['type'] ?? null) === 'array' && (! isset($clean['items']) || ! is_array($clean['items']))) {
            $clean['items'] = ['type' => 'string'];
        }

        $hint = $this->buildHumanReadableTypeHint($schema, $attribute);
        if ($hint !== null && $hint !== '') {
            $existing = isset($clean['description']) && is_string($clean['description'])
                ? trim($clean['description'])
                : '';

            $clean['description'] = $existing !== ''
                ? $existing . ' ' . $hint
                : $hint;
        }

        return $clean;
    }
    
    protected function getattributeDescription(metaattributeInterface $attribute): string
    {
        $name = $attribute->getName();
        $descr = $attribute->getShortDescription();
        if ($descr && $descr !== $name) {
            return $name . ': ' . $descr;
        }
        return $name;
    }

    protected function buildHumanReadableTypeHint(array $originalSchema, ?MetaAttributeInterface $attribute = null): ?string
    {
        $type = $originalSchema['type'] ?? null;
        $format = $originalSchema['format'] ?? null;

        if ($format === 'date-time' || $format === 'datetime') {
            return 'Expected format: ISO 8601 date-time, e.g. 2026-04-07T14:30:00Z.';
        }

        if ($format === 'date') {
            return 'Expected format: date as YYYY-MM-DD, e.g. 2026-04-07.';
        }

        if ($format === 'email') {
            return 'Expected format: valid email address, e.g. max@example.com.';
        }

        if ($format === 'uri' || $format === 'url') {
            return 'Expected format: absolute URL, e.g. https://example.com/path.';
        }

        if (isset($originalSchema['enum']) && is_array($originalSchema['enum']) && ! empty($originalSchema['enum'])) {
            return 'Allowed values: ' . implode(', ', array_map('strval', $originalSchema['enum'])) . '.';
        }

        if ($type === 'integer') {
            return 'Expected value: whole number.';
        }

        if ($type === 'number') {
            return 'Expected value: numeric value.';
        }

        if ($type === 'boolean') {
            return 'Expected value: true or false.';
        }

        if ($type === 'array') {
            return 'Expected value: list of entries.';
        }

        if ($type === 'object') {
            return 'Expected value: nested object.';
        }

        if ($attribute !== null) {
            try {
                $dataType = $attribute->getDataType();
                $class = is_object($dataType) ? get_class($dataType) : (string) $dataType;
                $classUpper = strtoupper($class);

                if (str_contains($classUpper, 'DATETIME')) {
                    return 'Expected format: ISO 8601 date-time, e.g. 2026-04-07T14:30:00Z.';
                }

                if (str_contains($classUpper, 'DATE') && ! str_contains($classUpper, 'DATETIME')) {
                    return 'Expected format: date as YYYY-MM-DD, e.g. 2026-04-07.';
                }

                if (str_contains($classUpper, 'EMAIL')) {
                    return 'Expected format: valid email address, e.g. max@example.com.';
                }

                if (str_contains($classUpper, 'HEXADECIMAL')) {
                    return 'Expected format: hexadecimal string, e.g. 1A2B3C or ff00aa.';
                }

                if (str_contains($classUpper, 'BOOLEAN')) {
                    return 'Expected value: true or false.';
                }

                if (str_contains($classUpper, 'INTEGER')) {
                    return 'Expected value: whole number.';
                }

                if (str_contains($classUpper, 'NUMBER') || str_contains($classUpper, 'DECIMAL') || str_contains($classUpper, 'FLOAT')) {
                    return 'Expected value: numeric value.';
                }

                if (str_contains($classUpper, 'STRING')) {
                    return 'Expected value: plain text.';
                }
            } catch (\Throwable $e) {
                // TODO Error handling if needed
            }
        }

        return null;
    }

    /**
     * @uxon-property object_alias
     * @uxon-type metamodel:object
     * @uxon-template "my.App.REPORT"
     */
    protected function setObjectAlias(string $objectAlias): DataSheetSchema
    {
        $this->objectAlias = $objectAlias;
        $this->metaObject = null;

        return $this;
    }

    /**
     * @uxon-property name
     * @uxon-type string
     * @uxon-template "address"
     */
    protected function setName(string $name): DataSheetSchema
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('DataSheetSchema "name" must not be empty.');
        }

        $this->name = $name;

        return $this;
    }

    /**
     * @uxon-property required_all
     * @uxon-type boolean
     * @uxon-default true
     */
    protected function setRequiredAll(bool $requiredAll): DataSheetSchema
    {
        $this->requiredAll = $requiredAll;

        if ($requiredAll) {
            $this->requiredAttributes = [];
        }

        return $this;
    }

    /**
     * @uxon-property require_attributes
     * @uxon-type string[]
     * @uxon-template ["email", "name"]
     *
     * @param UxonObject|array<string|int, mixed>|mixed $requiredAttributes
     */
    public function setRequireAttributes($requiredAttributes): DataSheetSchema
    {
        $this->requiredAll = false;
        if ($requiredAttributes instanceof UxonObject) {
            $requiredAttributes = $requiredAttributes->getPropertiesAll();
        }

        if (! is_array($requiredAttributes)) {
            $requiredAttributes = [$requiredAttributes];
        }

        $this->requiredAttributes = array_values(
            array_unique(
                array_filter(
                    array_map('strval', $requiredAttributes),
                    static fn(string $value): bool => trim($value) !== ''
                )
            )
        );

        return $this;
    }

    /**
     * Nested schema definitions added as nested properties to this schema.
     *
     * @uxon-property subsheets
     * @uxon-type \axenox\GenAI\Common\DataSheetSchema[]
     * @uxon-template {"address": {"object_alias": "my.App.ADDRESS"}}
     */
    protected function setSubsheets(UxonObject $subsheets): DataSheetSchema
    {
        $this->subsheetsUxon = $subsheets;
        $this->subsheets = null;

        return $this;
    }
    
    public function addSubsheet(DataSheetSchema $subsheet): DataSheetSchema
    {
        $this->subsheets[] = $subsheet;
        return $this;
        
    }
}