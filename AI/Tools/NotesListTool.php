<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\AI\Traits\NotesToolTrait;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Lists a limited number of note types and topics for the current agent and user without exposing note bodies.
 */
class NotesListTool extends AbstractAiTool
{
    use NotesToolTrait;

    public const ARG_MAX_RESULTS = 'max_results';

    private const DEFAULT_MAX_RESULTS = 20;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $prompt, array $arguments): AiToolResultInterface
    {
        $maxResults = (int) ($arguments[self::ARG_MAX_RESULTS] ?? $arguments[0] ?? self::DEFAULT_MAX_RESULTS);
        if ($maxResults < 1) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid max_results. Enter a positive integer.');
        }

        $sheet = $this->createScopedNotesSheet($agent);
        $sheet->getColumns()->addMultiple(['TYPE', 'TOPIC', 'UID', 'MODIFIED_ON']);
        $sheet->getSorters()->addFromString('MODIFIED_ON', SortingDirectionsDataType::DESC);
        $sheet->setRowsLimit($maxResults);
        $sheet->dataRead();

        $rows = [];
        foreach ($sheet->getRows() as $row) {
            $rows[] = [
                'Type' => $row['TYPE'] ?? '',
                'Topic' => $row['TOPIC'] ?? '',
                'UID' => $row['UID'] ?? '',
                'Last modified' => $row['MODIFIED_ON'] ?? ''
            ];
        }

        if (empty($rows)) {
            $markdown = 'No Notes are currently available for this agent and user.';
        } else {
            if (! $sheet->isPaged()) {
                $markdown = "Showing all notes for current user and agent";
            } else {
                $markdown = "Showing most recent {$maxResults} notes of " . $sheet->countRowsInDataSource();
            }
            $markdown .= "\n\n" . MarkdownDataType::buildMarkdownTableFromArray($rows);
        }

        return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
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
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Integer']))
                ->setName(self::ARG_MAX_RESULTS)
                ->setDescription('Maximum number of notes to return, ordered from most to least recently modified.')
                ->setDefaultValue(self::DEFAULT_MAX_RESULTS)
                ->setRequired(false)
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