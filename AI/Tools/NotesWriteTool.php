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
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Writes a long-term note for the current agent and user.
 *
 * Set `uid` to explicitly overwrite a known note. Without a UID, an existing note with the exact same topic is
 * replaced. User and agent identifiers are taken from the current request and cannot be supplied by the LLM.
 *
 * @author Andrej Kabachnik
 */
class NotesWriteTool extends AbstractAiTool
{
    use NotesToolTrait;

    public const ARG_TOPIC = 'topic';
    public const ARG_NOTE = 'note';
    public const ARG_UID = 'uid';
    public const ARG_TYPE = 'type';

    private const TYPE_MEMORY = 'memory';
    private const TYPE_SUGGESTION = 'suggestion';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $topic = trim((string) ($arguments[0] ?? ''));
        $note = (string) ($arguments[1] ?? '');
        $uid = trim((string) ($arguments[2] ?? ''));
        $type = (string) ($arguments[3] ?? self::TYPE_MEMORY);
        if ($topic === '') {
            throw new AiToolRuntimeError($this, $prompt, 'A topic is required to write a note.');
        }

        $sheet = $this->createScopedNotesSheet($agent);
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->getColumns()->addMultiple(['USER', 'AI_AGENT', 'TYPE']);
        $sheet->getFilters()->addConditionFromString(
            $uid === '' ? 'TOPIC' : 'UID',
            $uid === '' ? $topic : $uid,
            ComparatorDataType::EQUALS
        );
        $sheet->dataRead();

        if ($sheet->countRows() > 1) {
            throw new AiToolRuntimeError($this, $prompt, 'Multiple notes match the requested note identifier.');
        }
        if ($uid !== '' && $sheet->countRows() === 0) {
            throw new AiToolRuntimeError($this, $prompt, 'No note with this UID exists for the current agent and user.');
        }

        if ($sheet->countRows() === 1) {
            $sheet->getColumns()->addMultiple(['TOPIC', 'NOTE', 'TYPE']);
            $sheet->setCellValue('TOPIC', 0, $topic);
            $sheet->setCellValue('NOTE', 0, $note);
            $sheet->setCellValue('TYPE', 0, $type);
            $sheet->dataUpdate();
        } else {
            $sheet->addRow([
                'USER' => $this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid(),
                'AI_AGENT' => $this->getAgentUid($agent),
                'TOPIC' => $topic,
                'NOTE' => $note,
                'TYPE' => $type
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
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_UID)
                ->setDescription('Optional UID of an already existing note to overwrite explicitly. The note must belong to the current agent and user.')
                ->setRequired(false),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject([
                    'alias' => 'exface.Core.GenericStringEnum',
                    'values' => [
                        self::TYPE_MEMORY => self::TYPE_MEMORY,
                        self::TYPE_SUGGESTION => self::TYPE_SUGGESTION
                    ]
                ]))
                ->setName(self::ARG_TYPE)
                ->setDescription('Type of note to save. Use `memory` for reusable facts and `suggestion` for potential improvements or missing capabilities.')
                ->setDefaultValue(self::TYPE_MEMORY)
                ->setRequired(false)
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