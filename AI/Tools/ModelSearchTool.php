<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Specialized model search tool with predefined DataSheet defaults.
 *
 * This tool wraps {@see DataSheetReadTool} to query `exface.Core.SEARCH_RESULT`
 * without requiring the model to provide a full DataSheet UXON payload.
 *
 * In practice this means: the AI can enter a search term and gets matching
 * model usages as a result table.
 *
 * Example call:
 * - `model_search("\"exface.Core.USER\"")`
 *
 * Example output rows contain fields like:
 * - `OBJECT_NAME`, `INSTANCE_NAME`, `ATTRIBUTE_NAME`, `APP__ALIAS`,
 *   `INSTANCE_ALIAS`, `OBJECT_TYPE`, `TABLE_NAME`.
 *
 * @author Brooklyn Fraenzschky
 */
class ModelSearchTool extends AbstractAiTool
{
    public const ARG_SEARCH_QUERY = 'search_query';
    public const ARG_OBJECT_TYPE = 'object_type';
    public const ARG_ROWS_LIMIT = 'rows_limit';
    public const ARG_ROWS_OFFSET = 'rows_offset';

    private const DEFAULT_LIMIT = 50;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($query, $objType, $limit, $offset) = $arguments;
        
        if (! $query) {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: search_query');
        }
        
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.SEARCH_RESULT');
        $sheet->getColumns()->addMultiple([
            'OBJECT_NAME',
            'INSTANCE_NAME',
            'ATTRIBUTE_NAME',
            'APP__ALIAS',
            'INSTANCE_ALIAS_WITH_NS',
            'OBJECT_TYPE',
            'COMPONENT'
        ]);
        $sheet->getFilters()->addConditionFromString(
            'UXON', $query
        );
        $sheet->dataRead();

        return new AiToolResultString($this, $arguments, $this->buildMarkdownSearchResult($sheet), $this->getReturnDataType());
    }
    
    protected function buildMarkdownSearchResult(DataSheetInterface $resultSheet) : string
    {
        $rows = [];
        foreach ($resultSheet->getRows() as $row) {
            $rows[] = [
                'Component' => $row['COMPONENT'] ?? '',
                'Selector' => $row['INSTANCE_ALIAS_WITH_NS'],
                'Name' => $row['INSTANCE_NAME'] ?? '',
                'Match in' => $row['ATTRIBUTE_NAME'] ?? ''
            ];
        }
        $md = MarkdownDataType::buildMarkdownTableFromArray($rows);
        return $md;
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
                ->setName(self::ARG_SEARCH_QUERY)
                ->setDescription('Search term used to find model usage in the UXON payload of model entities')
                ->setRequired(true)
                ->setExamples([
                    '"exface.Core.USER"',
                    'AI_AGENT__ALIAS_WITH_NS',
                    'ShowModelSearchDialog'
                ]),
            (new ServiceParameter($self))
                ->setName(self::ARG_OBJECT_TYPE)
                ->setDescription('Optional object type filter (for example `exf_object`, `exf_attribute`, `exf_page`, `exf_object_action`)')
                ->setRequired(false),
            (new ServiceParameter($self))
                ->setName(self::ARG_ROWS_LIMIT)
                ->setDescription('Optional max number of rows to return (defaults to 50, capped by DataSheetReadTool)')
                ->setRequired(false),
            (new ServiceParameter($self))
                ->setName(self::ARG_ROWS_OFFSET)
                ->setDescription('Optional pagination offset (defaults to 0)')
                ->setRequired(false)
        ];
    }

    /**
     * @inheritDoc
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}