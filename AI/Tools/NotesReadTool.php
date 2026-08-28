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
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Reads one long-term note by UID for the current agent and user.
 *
 * The UID is always combined with hidden user and agent filters to prevent
 * notes from another scope being disclosed.
 * 
 * @author Andrej Kabachnik
 */
class NotesReadTool extends AbstractAiTool
{
    use NotesToolTrait;

    public const ARG_NOTE_UID = 'note_uid';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $noteUid = trim((string) ($arguments[0] ?? ''));
        if ($noteUid === '') {
            throw new AiToolRuntimeError($this, $prompt, 'A note UID is required.');
        }

        $sheet = $this->createScopedNotesSheet($agent);
        $sheet->getColumns()->addMultiple(['TOPIC', 'NOTE']);
        $sheet->getFilters()->addConditionFromString('UID', $noteUid, ComparatorDataType::EQUALS);
        $sheet->dataRead();

        if ($sheet->countRows() !== 1) {
            throw new AiToolRuntimeError($this, $prompt, 'No note with this UID exists for the current agent and user.');
        }

        $result = '## ' . $sheet->getCellValue('TOPIC', 0) . "\n\n" . $sheet->getCellValue('NOTE', 0);
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
                ->setName(self::ARG_NOTE_UID)
                ->setDescription('UID of the note returned by the note search or write tool.')
                ->setRequired(true)
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