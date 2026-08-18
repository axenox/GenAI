<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
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
class ModelSearchTool extends DataSheetReadTool
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
        $searchQuery = trim((string) ($arguments[0] ?? ''));
        if ($searchQuery === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: search_query');
        }

        $objectType = trim((string) ($arguments[1] ?? ''));
        $rowsLimit = $this->sanitizeRowsLimit($arguments[2] ?? null);
        $rowsOffset = $this->sanitizeRowsOffset($arguments[3] ?? null);

        $dataSheet = [
            'object_alias' => 'exface.Core.SEARCH_RESULT',
            'columns' => [
                ['attribute_alias' => 'OBJECT_NAME'],
                ['attribute_alias' => 'INSTANCE_NAME'],
                ['attribute_alias' => 'ATTRIBUTE_NAME'],
                ['attribute_alias' => 'APP__ALIAS'],
                ['attribute_alias' => 'INSTANCE_ALIAS'],
                ['attribute_alias' => 'OBJECT_TYPE'],
                ['attribute_alias' => 'TABLE_NAME']
            ],
            'filters' => [
                'operator' => 'AND',
                'conditions' => [
                    [
                        'expression' => 'UXON',
                        'comparator' => ComparatorDataType::IS,
                        'value' => $searchQuery
                    ]
                ]
            ],
            'rows_limit' => $rowsLimit,
            'rows_offset' => $rowsOffset
        ];

        if ($objectType !== '') {
            $dataSheet['filters']['conditions'][] = [
                'expression' => 'OBJECT_TYPE',
                'comparator' => ComparatorDataType::EQUALS,
                'value' => $objectType
            ];
        }

        return parent::invoke($agent, $prompt, [new UxonObject($dataSheet)]);
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

    private function sanitizeRowsLimit($value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (! is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, (int) $value);
    }

    private function sanitizeRowsOffset($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }
}
