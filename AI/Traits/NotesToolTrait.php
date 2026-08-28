<?php
namespace axenox\GenAI\AI\Traits;

use axenox\GenAI\Interfaces\AiAgentInterface;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Provides DataSheets restricted to notes of the current agent and user.
 */
trait NotesToolTrait
{
    private const NOTES_OBJECT_ALIAS = 'axenox.GenAI.AI_NOTE';

    /**
     * Creates a notes DataSheet scoped to the invoking agent and authenticated user.
     *
     * @param AiAgentInterface $agent
     * @return DataSheetInterface
     */
    protected function createScopedNotesSheet(AiAgentInterface $agent) : DataSheetInterface
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), self::NOTES_OBJECT_ALIAS);
        $sheet->getFilters()->addConditionFromString(
            'USER',
            $this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid(),
            ComparatorDataType::EQUALS
        );
        $sheet->getFilters()->addConditionFromString(
            'AI_AGENT',
            $this->getAgentUid($agent),
            ComparatorDataType::EQUALS
        );

        return $sheet;
    }

    /**
     * Resolves the model UID of the invoking agent.
     *
     * @param AiAgentInterface $agent
     * @return string
     */
    protected function getAgentUid(AiAgentInterface $agent) : string
    {
        if (method_exists($agent, 'getUid')) {
            return $agent->getUid();
        }

        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.GenAI.AI_AGENT');
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->getFilters()->addConditionFromString(
            'ALIAS_WITH_NS',
            $agent->getAliasWithNamespace(),
            ComparatorDataType::EQUALS
        );
        $sheet->dataRead();

        if ($sheet->countRows() !== 1) {
            throw new \RuntimeException('Cannot resolve the invoking AI agent in the model.');
        }

        return $sheet->getUidColumn()->getValue(0);
    }
}