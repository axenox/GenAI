<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
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
 *              "alias": "axenox.GenAI.DataSheetReadTool",
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

    public const OUTPUT_MARKDOWN_TABLE = 'markdown_table';
    public const OUTPUT_JSON = 'json';
    public const OUTPUT_MARKDOWN = 'markdown';
    
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 1000;
    
    private $outputMode = self::OUTPUT_MARKDOWN_TABLE;
    private $headerLevel = 2;
    private $includeObjectDescription = null;
    private array $warnings = [];

    /**
     * @uxon-property output_mode
     * @uxon-type [markdown_table, markdown, json]
     * @uxon-default markdown_table
     *
     * @param string $mode
     * @return $this
     */
    protected function setOutputMode(string $mode)
    {
        $allowedModes = [
            self::OUTPUT_MARKDOWN_TABLE,
            self::OUTPUT_MARKDOWN,
            self::OUTPUT_JSON
        ];

        if (!in_array($mode, $allowedModes, true)) {
            throw new \InvalidArgumentException('Invalid output_mode "' . $mode . '" for DataSheetReadTool. Allowed values: ' . implode(', ', $allowedModes));
        }

        $this->outputMode = $mode;
        return $this;
    }

    /**
     * @uxon-property header_level
     * @uxon-type integer
     * @uxon-default 2
     *
     * @param int $level
     * @return $this
     */
    protected function setHeaderLevel(int $level)
    {
        if ($level < 1 || $level > 6) {
            $this->warnings[] = 'Invalid header_level "' . $level . '" for DataSheetReadTool. Falling back to default level 2.';
            $level = 2;
        }

        $this->headerLevel = $level;
        return $this;
    }

    /**
     * @uxon-property include_object_description
     * @uxon-type boolean
     * @uxon-default true for markdown_table, false otherwise
     *
     * @param bool $includeObjectDescription
     * @return $this
     */
    protected function setIncludeObjectDescription(bool $includeObjectDescription)
    {
        $this->includeObjectDescription = $includeObjectDescription;
        return $this;
    }

    protected function isObjectDescriptionEnabled() : bool
    {
        if ($this->includeObjectDescription !== null) {
            return $this->includeObjectDescription;
        }

        return $this->outputMode === self::OUTPUT_MARKDOWN_TABLE;
    }


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

        $this->warnings = [];

        $rendered = $this->renderOutput($dataSheet, $prompt);

        $result = new AiToolResultString(
            $this,
            [$dataSheet->exportUxonObject()->toArray()],
            $rendered,
            $this->getReturnDataType()
        );

        foreach ($this->warnings as $warning) {
            if ($warning instanceof \Throwable) {
                $result->addException($warning);
            } else {
                $result->addException(new AiToolRuntimeWarning($this, $prompt, (string)$warning));
            }
        }

        if (empty($dataSheet->getRows())) {
            $warning = new AiToolRuntimeWarning(
                $this,
                $prompt,
                'No rows found for DataSheet query on object ' . $dataSheet->getMetaObject()->__toString() . '.'
            );
            $this->warnings[] = $warning;
            $result->addException($warning);
        }

        $this->warnings = [];

        return $result;
    }
    
    /**
     * Render the current data sheet in the configured output mode and return the final
     * tool output string.
     *
     * The returned value always starts with a short sentence describing the object, then
     * adds the rendered payload (JSON, markdown table or plain markdown), and optionally
     * appends a more detailed object description block if enabled.
     *
     * @param DataSheetInterface $dataSheet
     * @param AiPromptInterface|null $prompt
     * @return string Final text for the tool result. Never returns null.
     */
    protected function renderOutput(DataSheetInterface $dataSheet, ?AiPromptInterface $prompt = null) : string
    {
        $output = 'Read data of object ' . $dataSheet->getMetaObject()->__toString();

        try {
            switch ($this->outputMode) {
                case self::OUTPUT_JSON:
                    $rendered = $dataSheet->exportUxonObject()->toJson(true);
                    break;
                case self::OUTPUT_MARKDOWN_TABLE:
                    $rendered = $this->toMarkdownTable($dataSheet, $prompt);
                    break;
                case self::OUTPUT_MARKDOWN:
                    $rendered = $this->toMarkdown($dataSheet, $prompt);
                    break;
                default:
                    $this->warnings[] = new AiToolRuntimeWarning(
                        $this,
                        $prompt,
                        'Unsupported output mode "' . $this->outputMode . '" for DataSheetReadTool. Falling back to markdown_table.'
                    );
                    $rendered = $this->toMarkdownTable($dataSheet, $prompt);
                    break;
            }
        } catch (\Throwable $e) {
            $warning = new AiToolRuntimeWarning(
                $this,
                $prompt,
                'Failed to render markdown output for DataSheetReadTool. ' . $e->getMessage(),
                null,
                $e
            );
            $this->warnings[] = $warning;
            $rendered = $this->toMarkdownTable($dataSheet, $prompt);
        }

        if (trim((string) $rendered) !== '') {
            $output .= "\n\n" . $rendered;
        }

        if ($this->isObjectDescriptionEnabled()) {
            $infoObject = $this->getInfoObjectMarkdown($dataSheet, null, $prompt);
            if ($infoObject !== '') {
                $output .= "\n\n" . $infoObject;
            }
        }

        return $output;
    }
    
    /**
     * Create a plain markdown representation of the data sheet.
     *
     * Returns a markdown document with a heading and either a single-item bullet list or
     * one section per row. The method is intended for human-readable output and returns
     * the markdown text only; it does not attach warnings or modify the tool result.
     *
     * @param DataSheetInterface $dataSheet
     * @param AiPromptInterface|null $prompt
     * @return string Markdown text generated from the data sheet rows.
     */
    protected function toMarkdown(DataSheetInterface $dataSheet, ?AiPromptInterface $prompt = null) : string
    {
        $rows = $dataSheet->getRows();
        $columns = [];
        foreach ($dataSheet->getColumns() as $column) {
            $columns[] = $column->getExpressionObj()->__toString();
        }

        if (empty($rows)) {
            return <<<MD
## Data

Read data of object {$dataSheet->getMetaObject()->__toString()}.

No rows found.
MD;
        }

        $header = MarkdownDataType::buildMarkdownHeader('Data', $this->headerLevel);

        if (count($rows) === 1) {
            $lines = [$header];
            $lines[] = '';
            foreach ($columns as $columnName) {
                $value = $rows[0][$columnName] ?? null;
                $lines[] = '- **' . $columnName . '**: ' . $this->formatMarkdownValue($value);
            }
            return implode("\n", $lines);
        }

        $sections = [$header];
        $sections[] = '';

        $entryLevel = min(6, max(2, $this->headerLevel + 1));
        foreach ($rows as $index => $row) {
            $title = $this->getMarkdownRowTitle($row, $columns);
            if ($title === null || trim($title) === '') {
                $title = 'Entry ' . ($index + 1);
            }
            $sections[] = MarkdownDataType::buildMarkdownHeader($title, $entryLevel);
            foreach ($columns as $columnName) {
                $value = $row[$columnName] ?? null;
                $sections[] = '- **' . $columnName . '**: ' . $this->formatMarkdownValue($value);
            }
            $sections[] = '';
        }

        return implode("\n", $sections);
    }

    /**
     * Determine a readable title for a markdown row section.
     *
     * The first non-empty, non-null value from the row is used as a display title. If no
     * meaningful value exists, the method returns null and the caller can fall back to a
     * generic entry label such as "Entry 1".
     *
     * @param array $row
     * @param array $columns
     * @return string|null Human readable row title or null if no useful value was found.
     */
    protected function getMarkdownRowTitle(array $row, array $columns) : ?string
    {
        foreach ($columns as $columnName) {
            $value = $row[$columnName] ?? null;
            if ($value === null) {
                continue;
            }

            $formatted = $this->formatMarkdownValue($value);
            if ($formatted === 'null' || trim($formatted) === '') {
                continue;
            }

            return trim((string) $formatted);
        }

        return null;
    }

    /**
     * Try to fetch a markdown description for the meta object behind the data sheet.
     *
     * This method is optional and can be disabled via configuration. When available, it
     * returns a markdown block describing the object; otherwise it returns an empty string.
     * Recoverable failures are captured as warnings and do not break the tool response.
     *
     * @param DataSheetInterface $dataSheet
     * @param string|null $filters
     * @param AiPromptInterface|null $prompt
     * @return string Object description markdown or an empty string if unavailable.
     */
    protected function getInfoObjectMarkdown(DataSheetInterface $dataSheet, ?string $filters = null, ?AiPromptInterface $prompt = null) : string
    {
        if (! $this->isObjectDescriptionEnabled()) {
            return '';
        }

        $baseText = 'Read data of object ' . $dataSheet->getMetaObject()->__toString();
        if ($filters !== null && trim($filters) !== '') {
            $baseText .= ' ' . $filters;
        }

        try {
            $description = (new ObjectMarkdownPrinter($this->getWorkbench(), $dataSheet->getMetaObject()->getId(), 0, 2))->getMarkdown();
            if (! empty(trim((string) $description))) {
                return $baseText . "\n\n" . $description;
            }
            return $baseText;
        } catch (\Throwable $e) {
            $this->warnings[] = new AiToolRuntimeWarning(
                $this,
                $prompt,
                'Failed to render object information for object ' . $dataSheet->getMetaObject()->__toString() . '. ' . $e->getMessage(),
                null,
                $e
            );
            return '';
        }
    }

    /**
     * Normalize a scalar, array or object value into a markdown-safe string.
     *
     * This is used for table and list output so values can be inserted into markdown without
     * causing formatting issues. The method always returns a string; null values are rendered
     * as "null".
     *
     * @param mixed $value
     * @return string String representation safe to embed in markdown output.
     */
    protected function formatMarkdownValue($value) : string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(', ', array_map([$this, 'formatMarkdownValue'], $value));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?? 'null';
    }
    
    /**
     * Create a markdown table from the data sheet rows.
     *
     * Returns a markdown header plus a table body generated from the selected columns. This
     * is the default output mode for the tool and is intended to produce compact, LLM-friendly
     * data output.
     *
     * @param DataSheetInterface $dataSheet
     * @param AiPromptInterface|null $prompt
     * @return string Markdown table string with a heading section.
     */
    protected function toMarkdownTable(DataSheetInterface $dataSheet, ?AiPromptInterface $prompt = null) : string
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
        $header = MarkdownDataType::buildMarkdownHeader('Data', $this->headerLevel);
        $parts = [$header];
        $parts[] = '';
        $parts[] = $table;
        return implode("\n", $parts);

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