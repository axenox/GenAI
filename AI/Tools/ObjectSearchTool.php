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
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Reads entries from `exface.Core.OBJECT` filtered by object name.
 *
 * This is a lightweight helper to find objects by their `NAME` value using
 * a DataSheet filter and return a compact markdown table.
 */
class ObjectSearchTool extends AbstractAiTool
{
    public const ARG_OBJECT_NAME = 'object_name';

    /**
     * Default columns returned for object search results.
     */
    private const DEFAULT_RESULT_COLUMNS = [
        'UID',
        'NAME',
        'ALIAS',
        'ALIAS_WITH_NS',
        'LABEL',
        'SHORT_DESCRIPTION',
        'APP',
        'READABLE_FLAG',
        'WRITABLE_FLAG',
        'DATA_SOURCE',
        'PARENT_OBJECT',
        'HAS_DEFAULT_EDITOR',
        'INHERIT_DATA_SOURCE_BASE_OBJECT'
    ];

    /**
     * Available result columns and their meaning.
     */
    private const AVAILABLE_RESULT_COLUMNS = [
        'UID' => 'Technical object UID.',
        'ALIAS_WITH_NS' => 'Object alias including namespace.',
        'HAS_DEFAULT_EDITOR' => 'Flag indicating if an editor is configured.',
        'INHERIT_DATA_SOURCE_BASE_OBJECT' => 'Flag indicating data source inheritance from base object.',
        'NAME' => 'Human-readable object name.',
        'DOCS' => 'Object documentation text.',
        'COMMENTS' => 'Internal comments.',
        'READABLE_FLAG' => 'Flag indicating whether object is readable.',
        'WRITABLE_FLAG' => 'Flag indicating whether object is writable.',
        'ALIAS' => 'Object alias without namespace.',
        'APP' => 'Owning app alias.',
        'DATA_ADDRESS' => 'Data address string.',
        'DATA_ADDRESS_PROPS' => 'Data source settings.',
        'DATA_SOURCE' => 'Configured data source reference.',
        'DEFAULT_EDITOR_UXON' => 'Default editor UXON configuration.',
        'LABEL' => 'Display label of the object.',
        'PARENT_OBJECT' => 'Parent object reference.',
        'SHORT_DESCRIPTION' => 'Short object description.'
    ];

    /**
     * @var string[]
     */
    private array $resultColumns = self::DEFAULT_RESULT_COLUMNS;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $objectName = trim((string) ($arguments[0] ?? ''));
        if ($objectName === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: object_name');
        }

        try {
            $resultColumns = $this->getResultColumns();
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.OBJECT');
            $ds->getColumns()->addMultiple($resultColumns);
            $ds->getFilters()->addConditionFromString('NAME', $objectName, ComparatorDataType::IS);
            $ds->setRowsLimit(100);
            $ds->dataRead();
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read objects: ' . $e->getMessage(), null, $e);
        }

        $rows = $ds->getRows();
        if (empty($rows)) {
            $msg = 'No objects found for object_name "' . $objectName . '".';
            $warning = new AiToolRuntimeWarning($this, $prompt, $msg);
            return new AiToolResultString($this, $arguments, $msg, $this->getReturnDataType(), [], [$warning]);
        }

        $table = MarkdownDataType::buildMarkdownTableFromArray($rows, $resultColumns);
        $columnDescriptions = $this->buildColumnDescriptionsMarkdown($resultColumns);
        $result = <<<MD
# Object search result

Filter: `NAME IS "{$objectName}"`

{$table}

    ## Column descriptions

    {$columnDescriptions}
MD;

        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT_NAME)
                ->setDescription('Object name filter for exface.Core.OBJECT.NAME')
                ->setRequired(true)
                ->setExamples([
                    'User',
                    'Role',
                    'Page'
                ])
        ];
    }

    /**
     * Columns to include in the result table.
     *
     * Configure this to reduce output or include additional object attributes.
     * Unknown column names are ignored.
     *
     * Supported columns:
     * `UID`, `ALIAS_WITH_NS`, `HAS_DEFAULT_EDITOR`, `INHERIT_DATA_SOURCE_BASE_OBJECT`,
     * `NAME`, `DOCS`, `COMMENTS`, `READABLE_FLAG`, `WRITABLE_FLAG`, `ALIAS`, `APP`,
     * `DATA_ADDRESS`, `DATA_ADDRESS_PROPS`, `DATA_SOURCE`, `DEFAULT_EDITOR_UXON`,
     * `LABEL`, `PARENT_OBJECT`, `SHORT_DESCRIPTION`.
     *
     * @uxon-property result_columns
     * @uxon-type array
     * @uxon-default ["UID","NAME","ALIAS","ALIAS_WITH_NS","LABEL","SHORT_DESCRIPTION","APP","READABLE_FLAG","WRITABLE_FLAG","DATA_SOURCE","PARENT_OBJECT","HAS_DEFAULT_EDITOR","INHERIT_DATA_SOURCE_BASE_OBJECT"]
     * @uxon-template ["UID","NAME","ALIAS_WITH_NS","APP","DATA_SOURCE"]
     *
     * @param string[] $columns
     * @return ObjectSearchTool
     */
    protected function setResultColumns(array $columns): ObjectSearchTool
    {
        $sanitized = [];
        foreach ($columns as $column) {
            $column = trim((string) $column);
            if ($column === '') {
                continue;
            }
            $column = mb_strtoupper($column);
            if (array_key_exists($column, self::AVAILABLE_RESULT_COLUMNS)) {
                $sanitized[$column] = $column;
            }
        }

        if (! empty($sanitized)) {
            $this->resultColumns = array_values($sanitized);
        }

        return $this;
    }

    /**
     * @return string[]
     */
    protected function getResultColumns(): array
    {
        return $this->resultColumns;
    }

    /**
     * @param string[] $columns
     * @return string
     */
    protected function buildColumnDescriptionsMarkdown(array $columns): string
    {
        $lines = [];
        foreach ($columns as $column) {
            $description = self::AVAILABLE_RESULT_COLUMNS[$column] ?? 'No description available.';
            $lines[] = '- `' . $column . '` - ' . $description;
        }
        return implode("\n", $lines);
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