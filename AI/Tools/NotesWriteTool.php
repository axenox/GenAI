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
 * Writes a long-term note for the current agent and user.
 *
 * Existing notes with the same topic are replaced. User and agent identifiers
 * are taken from the current request and cannot be supplied by the LLM.
 * 
 * @author Andrej Kabachnik
 */
class NotesWriteTool extends AbstractAiTool
{
    use NotesToolTrait;

    public const ARG_TOPIC = 'topic';
    public const ARG_NOTE = 'note';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $topic = trim((string) ($arguments[0] ?? ''));
        $note = (string) ($arguments[1] ?? '');
        if ($topic === '') {
            throw new AiToolRuntimeError($this, $prompt, 'A topic is required to write a note.');
        }

        $sheet = $this->createScopedNotesSheet($agent);
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->getColumns()->addMultiple(['TOPIC', 'NOTE']);
        $sheet->getFilters()->addConditionFromString('TOPIC', $topic, ComparatorDataType::EQUALS);
        $sheet->dataRead();

        if ($sheet->countRows() > 1) {
            throw new AiToolRuntimeError($this, $prompt, 'Multiple notes exist for the same topic.');
        }

        if ($sheet->countRows() === 1) {
            $sheet->setCellValue('NOTE', 0, $note);
            $sheet->dataUpdate();
        } else {
            $sheet->addRow([
                'USER' => $this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid(),
                'AI_AGENT' => $this->getAgentUid($agent),
                'TOPIC' => $topic,
                'NOTE' => $note
            ]);
            $sheet->dataCreate();
        }

        $uid = $sheet->getUidColumn()->getValue(0);
        return new AiToolResultString($this, $arguments, 'Note saved with UID ' . $uid . '.', $this->getReturnDataType());
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
                ->setName(self::ARG_TOPIC)
                ->setDescription('Short, stable topic identifying the note.')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_NOTE)
                ->setDescription('Complete note body to save. Replaces the existing body for this topic.')
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