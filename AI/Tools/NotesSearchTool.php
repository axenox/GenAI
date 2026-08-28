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
 * Searches notes (long-term memory) for the current agent and user.
 *
 * The tool searches note topics and bodies. Use `excerpt_length` to control how much context each result includes,
 * then use the note UID to load a selected result completely.
 *
 * @author Andrej Kabachnik
 */
class NotesSearchTool extends AbstractAiTool
{
    use NotesToolTrait;

    public const ARG_QUERY = 'query';
    private const DEFAULT_EXCERPT_LENGTH = 300;

    private int $excerptLength = self::DEFAULT_EXCERPT_LENGTH;

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
        $sheet->getColumns()->addMultiple(['TOPIC', 'NOTE']);
        $searchFilters = $sheet->getFilters()->addNestedOR();
        $searchFilters->addConditionFromString('TOPIC', $query, ComparatorDataType::IS);
        $searchFilters->addConditionFromString('NOTE', $query, ComparatorDataType::IS);
        $sheet->dataRead();

        $matches = [];
        foreach ($sheet->getRows() as $rowNumber => $row) {
            $matches[] = MarkdownDataType::buildMarkdownHeader($sheet->getCellValue('TOPIC', $rowNumber), 3) . "\n"
                . 'UID: `' . $sheet->getUidColumn()->getValue($rowNumber) . "`\n\n"
                . MarkdownDataType::escapeCodeBlock($this->createExcerpt((string) $sheet->getCellValue('NOTE', $rowNumber), $query), 'markdown');
        }

        $result = empty($matches) ? 'No matching notes found.' : implode("\n\n", $matches);
        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
    }

    /**
     * Creates a compact, single-line excerpt around the matching query.
     *
     * @param string $note
     * @param string $query
     * @return string
     */
    private function createExcerpt(string $note, string $query) : string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) <= $this->excerptLength) {
            return $note;
        }

        $matchPosition = mb_stripos($note, $query);
        $start = $matchPosition === false ? 0 : max(0, $matchPosition - (int) ($this->excerptLength / 3));
        $excerpt = mb_substr($note, $start, $this->excerptLength);

        return ($start > 0 ? '...' : '') . rtrim($excerpt) . '...';
    }

    /**
     * Maximum number of note-body characters included in each search result.
     *
     * @uxon-property excerpt_length
     * @uxon-type integer
     * @uxon-default 300
     *
     * @param int $length
     * @return NotesSearchTool
     */
    protected function setExcerptLength(int $length) : NotesSearchTool
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('The excerpt_length of NotesSearchTool must be greater than 0.');
        }

        $this->excerptLength = $length;
        return $this;
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
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}