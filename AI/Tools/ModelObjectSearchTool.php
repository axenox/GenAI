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
class ModelObjectSearchTool extends AbstractAiTool
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
     * Meta object to search in.
     */
    private string $objectAlias = 'exface.Core.OBJECT';

    /**
     * Columns to print in the result table.
     *
     * These are DataTable-style `columns` names, not AI-supplied attribute aliases.
     *
     * @var string[]
     */
    private array $columns = self::DEFAULT_RESULT_COLUMNS;

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
            $resultColumns = $this->normalizeColumns($this->columns);
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $this->getObjectAlias());
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
        $result = <<<MD
# Object search result

Filter: `NAME IS "{$objectName}"`

{$table}
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
     * @uxon-property object_alias
     * @uxon-type metamodel:object
     * @uxon-default exface.Core.OBJECT
     *
     * @param string $objectAlias
     * @return ModelObjectSearchTool
     */
    protected function setObjectAlias(string $objectAlias): ModelObjectSearchTool
    {
        $this->objectAlias = trim($objectAlias) !== '' ? trim($objectAlias) : 'exface.Core.OBJECT';
        return $this;
    }

    /**
     * @return string
     */
    protected function getObjectAlias(): string
    {
        return $this->objectAlias;
    }

    //TODO
    //setDataSheet

    /**
     * Columns to include in the result table.
     *
     * This follows the DataTable widget style with a plain array of attribute
     * aliases or column names, for example ["UID","NAME","APP__ALIAS"].
     * The Power UI can therefore offer the same auto-suggest and prefill behavior
     * as a DataTable column selector.
     *
     * @uxon-property columns
     * @uxon-type metamodel:attribute[]
     * @uxon-default ["UID","NAME","ALIAS","ALIAS_WITH_NS","LABEL","SHORT_DESCRIPTION","APP","READABLE_FLAG","WRITABLE_FLAG","DATA_SOURCE","PARENT_OBJECT","HAS_DEFAULT_EDITOR","INHERIT_DATA_SOURCE_BASE_OBJECT"]
     * @uxon-template ["UID","NAME","ALIAS_WITH_NS","APP","DATA_SOURCE"]
     *
     * @param string[]|array[] $columns
     * @return ModelObjectSearchTool
     */
    protected function setColumns(array $columns): ModelObjectSearchTool
    {
        $this->columns = $this->normalizeColumns($columns);
        return $this;
    }

    /**
     * @return string[]
     */
    protected function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @param mixed $columns
     * @return string[]
     */
    protected function normalizeColumns($columns): array
    {
        if (! is_array($columns)) {
            $columns = [$columns];
        }

        $sanitized = [];
        foreach ($columns as $column) {
            $column = trim((string) $column);
            if ($column === '') {
                continue;
            }

            $column = mb_strtoupper($column);
            $sanitized[$column] = $column;
        }

        if (empty($sanitized)) {
            return self::DEFAULT_RESULT_COLUMNS;
        }

        return array_values($sanitized);
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