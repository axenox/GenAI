<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\AI\Traits\NotesToolTrait;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Searches note topics and bodies for the current agent and user.
 *
 * Only note UIDs are returned. Use NotesReadTool to load a selected result.
 */
class NotesSearchTool extends AbstractAiTool
{
    use NotesToolTrait;

    public const ARG_QUERY = 'query';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $query = trim((string) ($arguments[0] ?? ''));
        if ($query === '') {
            throw new AiToolRuntimeError($this, $prompt, 'A search query is required.');
        }

        $sheet = $this->createScopedNotesSheet($agent);
        $sheet->getColumns()->addFromSystemAttributes();
        $searchFilters = $sheet->getFilters()->addNestedOR();
        $searchFilters->addConditionFromString('TOPIC', $query, ComparatorDataType::IS);
        $searchFilters->addConditionFromString('NOTE', $query, ComparatorDataType::IS);
        $sheet->dataRead();

        $uids = [];
        foreach ($sheet->getRows() as $rowNumber => $row) {
            $uids[] = $sheet->getUidColumn()->getValue($rowNumber);
        }

        $result = empty($uids) ? 'No matching notes found.' : implode("\n", $uids);
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
                ->setName(self::ARG_QUERY)
                ->setDescription('Text to find in note topics or note bodies.')
                ->setRequired(true)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }
}