<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\AI\Traits\NotesToolTrait;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Lists note types and topics for the current agent and user without exposing note bodies.
 */
class NotesListTool extends AbstractAiTool
{
    use NotesToolTrait;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $sheet = $this->createScopedNotesSheet($agent);
        $sheet->getColumns()->addMultiple(['TYPE', 'TOPIC']);
        $sheet->getSorters()->addFromString('TYPE', SortingDirectionsDataType::ASC);
        $sheet->getSorters()->addFromString('TOPIC', SortingDirectionsDataType::ASC);
        $sheet->dataRead();

        $rows = [];
        foreach ($sheet->getRows() as $row) {
            $rows[] = [
                'Type' => $row['TYPE'] ?? '',
                'Topic' => $row['TOPIC'] ?? ''
            ];
        }

        if (empty($rows)) {
            $markdown = 'No Notes are currently available for this agent and user.';
        } else {
            $markdown = MarkdownDataType::buildMarkdownTableFromArray($rows, ['Type', 'Topic']);
        }

        return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        return [];
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