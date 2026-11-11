<?php
namespace axenox\GenAI\Common\Messages;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;

/**
 * Tool calls requested by the LLM together with their results - an output message
 * 
 * The calls are created by the response adapter. The results are added by the conversation
 * via `withToolResults()` after the agent ran the tools.
 * 
 * @author Andrej Kabachnik
 */
class ToolCallMessage extends OutputMessage implements AiToolCallMessageInterface
{
    const MAX_MARKDOWN_LENGTH = 16000;

    private array $calls;
    private array $results = [];

    /**
     * @param string $text What the LLM said together with the tool request (may be empty)
     * @param AiToolCallInterface[] $calls In the order requested by the LLM
     * @param array<string,AiToolResultInterface> $results Indexed by call ID
     * @param array<string,array<array-key,mixed>> $adapterData
     * @param string $type AiMessageTypeDataType value for subclasses with other tool call types
     * @throws \InvalidArgumentException
     */
    public function __construct(string $text, array $calls, array $results = [], array $adapterData = [], string $type = AiMessageTypeDataType::TOOL)
    {
        parent::__construct($text, [], $adapterData, $type);
        foreach ($calls as $call) {
            if (! $call instanceof AiToolCallInterface) {
                throw new \InvalidArgumentException('Tool call messages accept instances of AiToolCallInterface only.');
            }
        }
        $this->calls = array_values($calls);
        $this->results = $this->validateResults($results);
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallMessageInterface::getCalls()
     */
    public function getCalls() : array
    {
        return $this->calls;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallMessageInterface::getToolResults()
     */
    public function getToolResults() : array
    {
        return $this->results;
    }

    /**
     * {@inheritDoc}
     * @see AiToolCallMessageInterface::isComplete()
     */
    public function isComplete() : bool
    {
        if ($this->calls === []) {
            return false;
        }
        foreach ($this->calls as $call) {
            if (! isset($this->results[$call->getCallId()])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns a copy with additional results for calls of this message
     * 
     * @internal
     * @param array<string,AiToolResultInterface> $results Indexed by call ID
     * @throws \InvalidArgumentException If a result does not belong to a call of this message
     * @return static
     */
    public function withToolResults(array $results) : static
    {
        $clone = clone $this;
        $clone->results = array_replace($this->results, $this->validateResults($results));
        return $clone;
    }

    /**
     * Makes sure, every result belongs to a call of this message
     * 
     * @param array<string,AiToolResultInterface> $results
     * @throws \InvalidArgumentException
     * @return array<string,AiToolResultInterface>
     */
    private function validateResults(array $results) : array
    {
        $ids = [];
        foreach ($this->calls as $call) {
            $ids[$call->getCallId()] = true;
        }
        $validated = [];
        foreach ($results as $id => $result) {
            if (! $result instanceof AiToolResultInterface || ! isset($ids[(string) $id])) {
                throw new \InvalidArgumentException('Tool results must reference a call of the same message.');
            }
            $validated[(string) $id] = $result;
        }
        return $validated;
    }

    /**
     * Renders a bounded designer preview with a call summary and typed results
     * 
     * {@inheritDoc}
     * @see OutputMessage::toMarkdown()
     */
    public function toMarkdown() : string
    {
        $text = $this->isComplete() ? '' : "Results of some calls were not recorded.\n\n";
        if ($this->getText() !== '') {
            $text .= $this->getText() . "\n\n";
        }
        $text .= '> **' . count($this->calls) . "** tool calls:\n";
        foreach ($this->calls as $index => $call) {
            $summary = StringDataType::truncate((string) $call, 120, false, true, true, true);
            $text .= '> ' . ($index + 1) . '. ' . MarkdownDataType::escapeCodeInline($summary) . "\n";
        }
        $text .= "\n";
        foreach ($this->calls as $index => $call) {
            $text .= '## ' . ($index + 1) . '. ' . MarkdownDataType::escapeString($call->getToolName()) . "()\n\n";
            $text .= StringDataType::indent((string) $call, '    ') . "\n\n";
            if (isset($this->results[$call->getCallId()])) {
                $text .= MarkdownDataType::makeHorizontalLine();
                $text .= $this->results[$call->getCallId()]->getValueAsMarkdown(true) . "\n\n";
            }
        }
        if (mb_strlen($text) <= self::MAX_MARKDOWN_LENGTH) {
            return $text;
        }
        $notice = "\n\nPreview truncated; full results are available in the message data.";
        $text = mb_substr($text, 0, self::MAX_MARKDOWN_LENGTH - mb_strlen($notice));
        do {
            // Close a fenced result if the preview ends inside it, keeping the notice visible.
            $fence = '';
            preg_match_all('/^ {0,3}(`{3,}|~{3,})([^\r\n]*)$/m', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if ($fence === '') {
                    $fence = $match[1];
                } elseif ($match[1][0] === $fence[0] && strlen($match[1]) >= strlen($fence) && trim($match[2]) === '') {
                    $fence = '';
                }
            }
            $rendered = $text . ($fence !== '' ? "\n" . $fence : '') . $notice;
            $excess = mb_strlen($rendered) - self::MAX_MARKDOWN_LENGTH;
            if ($excess > 0) {
                $text = mb_substr($text, 0, max(0, mb_strlen($text) - $excess));
            }
        } while ($excess > 0);
        return $rendered;
    }
}
