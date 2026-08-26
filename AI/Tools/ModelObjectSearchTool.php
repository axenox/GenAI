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
use exface\Core\CommonLogic\UxonObject;
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

    private ?UxonObject $dataSheetUxon = null;

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
            $ds = DataSheetFactory::createFromUxon($this->getWorkbench(), $this->getDataSheet());
            if ($ds->getColumns()->isEmpty()) {
                $ds->getColumns()->addMultiple(self::DEFAULT_RESULT_COLUMNS);
            }
            $ds->getFilters()->addConditionFromString('NAME', $objectName, ComparatorDataType::IS);
            if ($ds->getRowsLimit() === null || $ds->getRowsLimit() > 100) {
                $ds->setRowsLimit(100);
            }
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

        $resultColumns = [];
        foreach ($ds->getColumns() as $column) {
            $resultColumns[] = $column->getName();
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
     * DataSheet configuration used to search and render model objects.
     *
     * The configured object and columns are imported by the standard DataSheet
     * prototype. The tool adds its `NAME` filter and limits reads to 100 rows.
     *
     * @uxon-property data_sheet
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataSheet
     * @uxon-template {"object_alias":"exface.Core.OBJECT","columns":[{"attribute_alias":"UID"},{"attribute_alias":"NAME"},{"attribute_alias":"ALIAS_WITH_NS"},{"attribute_alias":"APP"},{"attribute_alias":"DATA_SOURCE"}]}
     *
     * @param UxonObject $dataSheetUxon
     * @return ModelObjectSearchTool
     */
    protected function setDataSheet(UxonObject $dataSheetUxon): ModelObjectSearchTool
    {
        $this->dataSheetUxon = $dataSheetUxon;
        return $this;
    }

    /**
     * @return UxonObject
     */
    protected function getDataSheet(): UxonObject
    {
        if ($this->dataSheetUxon === null) {
            $columns = [];
            foreach (self::DEFAULT_RESULT_COLUMNS as $column) {
                $columns[] = ['attribute_alias' => $column];
            }
            $this->dataSheetUxon = new UxonObject([
                'object_alias' => 'exface.Core.OBJECT',
                'columns' => $columns
            ]);
        }

        return $this->dataSheetUxon;
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