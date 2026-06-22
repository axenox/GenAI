<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonSchemaDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\DataSheets\DataSheetReadError;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\ObjectMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to read data from any metaobject using DataSheets.
 * 
 * The tool supports columns, filters (with nested condition groups), sorters, aggregators,
 * pagination via limit/offset. The data is returned as a JSON array of rows.
 * 
 * ## Example tool
 * 
 * ```
 *  {
 *      "instructions": "You help users analyze business data",
 *      "tools": {
 *          "read_data": {
 *              "alias": "axenox.GenAI.ReadDataSheetTool",
 *              "description": "Read data from a business object",
 *              "arguments": [
 *                  {
 *                      "name": "DataSheet",
 *                      "description": "UXON model of an empty data sheet"
 *                  }
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 * 
 * ### Example tool call
 * 
 *  ```
 *   read_data('{
 *       "object_alias": "exface.Core.WIDGET_SETUP",
 *       "columns": [
 *           {"name": "NAME", "attribute_alias": "NAME"},
 *           {"name": "VISIBILITY", "attribute_alias": "VISIBILITY"},
 *       ],
 *       "filters": {
 *           "operator": "AND",
 *           "conditions": [
 *               {
 *                   "expression": "PAGE",
 *                   "comparator": "==",
 *                   "value": "0x11ef9d7a355bec329d7a005056bef75d",
 *               }
 *           ],
 *       },
 *       "rows_limit": null,
 *       "rows_offset": 0
 *   }')
 * 
 *  ```
 * 
 * ### Example result
 * 
 * ```json
 *  {
 *      "object_alias": "exface.Core.WIDGET_SETUP",
 *      "columns": [
 *          {"name": "NAME", "attribute_alias": "NAME"},
 *          {"name": "VISIBILITY", "attribute_alias": "VISIBILITY"},
 *      ],
 *      "rows": [
 *          {
 *              "NAME": "Andrejs Share-Test",
 *              "VISIBILITY": "PRIVATE",
 *          }, {
 *              "NAME": "Andrejs Share-Test",
 *              "VISIBILITY": "PRIVATE",
 *          }
 *      ],
 *      "totals_rows": [],
 *      "filters": {
 *          "operator": "AND",
 *          "conditions": [
 *              {
 *                  "expression": "PAGE",
 *                  "comparator": "==",
 *                  "value": "0x11ef9d7a355bec329d7a005056bef75d",
 *              },
 *          ],
 *      },
 *      "rows_limit": null,
 *      "rows_offset": 0
 *  }
 * 
 * ```
 * 
 * ## Filters
 * 
 * Filters use the ConditionGroup UXON format, which supports nested condition groups
 * for complex filtering logic:
 * 
 * ```
 *  {
 *      "operator": "AND",
 *      "conditions": [
 *          {"expression": "NAME", "comparator": "[", "value": "test"},
 *          {"expression": "ACTIVE", "comparator": "==", "value": true}
 *      ],
 *      "nested_groups": [
 *          {
 *              "operator": "OR",
 *              "conditions": [
 *                  {"expression": "STATUS", "comparator": "==", "value": "open"},
 *                  {"expression": "STATUS", "comparator": "==", "value": "pending"}
 *              ]
 *          }
 *      ]
 *  }
 * 
 * ```
 *
 *  ### Scalar (single value) comparators
 *
 *  - `=` - universal comparator similar to SQL's `LIKE` with % on both sides. Can compare different
 *  data types. If the left value is a string, becomes TRUE if it contains the right value. Case
 *  insensitive for strings
 *  - `!=` - yields TRUE if `IS` would result in FALSE
 *  - `==` - compares two single values of the same type. Case-sensitive for stings. Normalizes the
 *  values before comparison though, so the date `-1 == 21.09.2020` will yield TRUE on the 22.09.2020.
 *  - `!==` - the inverse of `EQUALS`
 *  - `<` - yields TRUE if the left value is less than the right one. Both values must be of
 *  comparable types: e.g. numbers or dates.
 *  - `<=` - yields TRUE if the left value is less than or equal to the right one.
 *  Both values must be of comparable types: e.g. numbers or dates.
 *  - `>` - yields TRUE if the left value is greater than the right one. Both values must be of
 *  comparable types: e.g. numbers or dates.
 *  - `>=` - yields TRUE if the left value is greater than or equal to the right one.
 *  Both values must be of comparable types: e.g. numbers or dates.
 *
 *  ### List comparators
 *
 *  #### Comparing a scalar value to a list (IN, NOT IN)
 *
 *  - `[` - IN-comparator - compares a value with each item in a list via EQUALS. Becomes true if the left
 *  value equals at least on of the values in the list within the right value. The list on the
 *  right side must consist of numbers or strings separated by commas or the attribute's value
 *  list delimiter if filtering over an attribute. The right side can also be another type of
 *  expression (e.g. a formula or widget link), that yields such a list.
 *  - `![` - the inverse von `[` . Becomes true if the left value equals none of the values in the
 *  list within the right value. The list on the right side must consist of numbers or strings separated
 *  by commas or the attribute's value list delimiter if filtering over an attribute. The right side can
 *  also be another type of expression (e.g. a formula or widget link), that yields such a list.
 *
 *  Additionally, you can also use the **EACH** and **ANY** comparators below if with a scalar value on one side.
 *
 *  #### Comparing two lists
 *
 *  - `][` - intersection - compares two lists with each other. Becomes TRUE when there is at least
 *  one element, that is present in both lists.
 *  - `!][` - the inverse of `][`. Becomes TRUE if no element is part of both lists.
 *  - `[[` - subset - compares two lists with each other. Becomes true when all elements of the left list
 *  are in the right list too
 *  - `![[` - the inverse of `][`. Becomes true when at least one element of the left list is NOT in
 *  the right list.
 *
 *  #### EACH comparators
 *
 *  The following comparators yield TRUE if **EACH** of the values of the left list yields TRUE
 *  when compared to at least one value of the right list using the respective scalar comparator.
 *
 *  - `[=` - each value left is at least one value on the right
 *  - `[!=` - at least one value on the left does not match any value on the right
 *  - `[==` - each value left equals at least one value on the right exactly
 *  - `[!==` - at least one value on the left does not exactly equal any value on the right
 *  - `[<` - each value left is less than any value on the right
 *  - `[<=` - each value left is less than or equals any value on the right
 *  - `[>` - each value left is greater than any value on the right
 *  - `[>=` - each value left is greater than or equals value on the right
 *
 *  #### ANY comparators
 *
 *  Similarly, the following comparators will yield TRUE if **ANY** of the values of the left list yields TRUE
 *  when compared to at least one value of the right list using the respective scalar comparator.
 *
 *  - `]=` - at least one value left is at least one value on the right
 *  - `]!=` - none of the left values match any value on the right
 *  - `]==` - at least one value left equals at least one value on the right exactly
 *  - `]!==` - none of the left values equals exactly any value on the right
 *  - `]<` - at least one value left is less than any value on the right
 *  - `]<=` - at least one value left is less than or equals any value on the right
 *  - `]>` - at least one value left is greater than any value on the right
 *  - `]>=` - at least one value left is greater than or equals value on the right
 *
 *   ### Range comparators
 *
 *   - `..` - range between two values - e.g. `1 .. 5`
 * 
 * @author Andrej Kabachnik
 */
class DataSheetReadTool extends AbstractAiTool
{
    public const ARG_DATA_SHEET = 'data_sheet';

    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 1000;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $dataSheetArg = $arguments[0] ?? null;
        if ($dataSheetArg === null || $dataSheetArg === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: data_sheet');
        }

        try {
            $dataSheet = DataSheetFactory::createFromAnything($this->getWorkbench(), $dataSheetArg);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid data_sheet UXON: ' . $e->getMessage(), null, $e);
        }

        if ($dataSheet->getColumns()->isEmpty()) {
            foreach ($dataSheet->getMetaObject()->getAttributes()->getReadable() as $attr) {
                $dataSheet->getColumns()->addFromAttribute($attr);
            }
        }

        $limit = $dataSheet->getRowsLimit();
        if ($limit === null) {
            $dataSheet->setRowsLimit(self::DEFAULT_LIMIT);
        } elseif ($limit > self::MAX_LIMIT) {
            $dataSheet->setRowsLimit(self::MAX_LIMIT);
        }

        try {
            $dataSheet->dataRead();
        } catch (DataSheetReadError $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read data: ' . $e->getMessage(), null, $e);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Unexpected error reading data: ' . $e->getMessage(), null, $e);
        }

        return new AiToolResultString(
            $this, 
            $arguments,
            $this->toMarkdown($dataSheet),
            $this->getReturnDataType()
        );
    }
    
    protected function toMarkdown(DataSheetInterface $dataSheet) : string
    {
        $colNames = [];
        foreach ($dataSheet->getColumns() as $column) {
            $colNames[] = $column->getExpressionObj()->__toString();
        }
        $table = MarkdownDataType::buildMarkdownTableFromArray($dataSheet->getRows(), $colNames);
        if ($dataSheet->getFilters()->isEmpty(true)) {
            $filters = 'without filters';
        } else {
            $filters = 'filtered by `' . $dataSheet->getFilters()->__toString() . '`';
        }
        $description = (new ObjectMarkdownPrinter($this->getWorkbench(), $dataSheet->getMetaObject()->getId(), 0, 2))->getMarkdown();
        return <<<MD
## Data

Read data of object {$dataSheet->getMetaObject()->__toString()} {$filters}

{$table}

{$description}
MD;

    }

    /**
     * Returns the JSON schema definitions for complex arguments.
     * 
     * @return array Associative array of argument name => JSON schema
     */
    public static function getArgumentsJsonSchema(): array
    {
        return [
            self::ARG_DATA_SHEET => self::buildSchemaForDataSheet()
        ];
    }

    /**
     * Builds the JSON schema for the DataSheet UXON argument.
     *
     * @return array
     */
    protected static function buildSchemaForDataSheet(bool $requireAllProperties = true): array
    {
        $schema = [
            'type' => 'object',
            'description' => 'UXON model of the DataSheet to read',
            'required' => ['object_alias', 'columns'],
            'additionalProperties' => false,
            'properties' => [
                'object_alias' => [
                    'type' => 'string',
                    'description' => 'Fully qualified alias (with namespace) of the meta object to read data from'
                ],
                'columns' => [
                    'type' => 'array',
                    'description' => 'Array of columns to read',
                    'items' => JsonSchemaDataType::buildSchemaForDataSheetColumn()
                ],
                'filters' => JsonSchemaDataType::buildSchemaForConditionGroup(),
                'sorters' => [
                    'type' => 'array',
                    'description' => 'Array of sorter definitions',
                    'items' => JsonSchemaDataType::buildSchemaForDataSheetSorter()
                ],
                'aggregators' => JsonSchemaDataType::buildSchemaForDataSheetAggregators(),
                'rows_limit' => [
                    'description' => 'Maximum number of rows to return',
                    'anyOf' => [
                        ['type' => 'integer'],
                        ['type' => 'null']
                    ]
                ],
                'rows_offset' => [
                    'type' => 'integer',
                    'description' => 'Number of rows to skip (for pagination)',
                    'minimum' => 0
                ]
            ]
        ];
        if ($requireAllProperties) {
            $schema['required'] = array_keys($schema['properties']);
        }
        return $schema;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        $schemas = self::getArgumentsJsonSchema();

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_DATA_SHEET)
                ->setDescription('UXON model of the DataSheet to read. Include object_alias and optional columns, filters, sorters, aggregators, rows_limit and rows_offset.')
                ->setRequired(true)
                ->setExample('{"object_alias":"exface.Core.PAGE","columns":[{"attribute_alias":"NAME"}],"rows_limit":50,"rows_offset":0}')
                ->setCustomProperties(new UxonObject(['json_schema' => json_encode($schemas[self::ARG_DATA_SHEET])]))
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}