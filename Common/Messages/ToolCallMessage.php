<?php
namespace axenox\GenAI\Common\Messages;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;

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
     * Renders the text, every call and its result as a bounded code block for designers
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
        foreach ($this->calls as $index => $call) {
            $text .= '## ' . ($index + 1) . '. ' . $call->getToolName() . "()\n\n";
            $text .= (string) $call . "\n\n";
            if (isset($this->results[$call->getCallId()])) {
                $text .= $this->results[$call->getCallId()]->getValueWithMetadata() . "\n\n";
            }
        }
        $notice = "\n\nPreview truncated; full results are available in the message data.";
        $limit = self::MAX_MARKDOWN_LENGTH;
        $truncated = mb_strlen($text) > $limit - 20;
        $text = mb_substr($text, 0, $truncated ? $limit - 20 - mb_strlen($notice) : $limit - 20);
        do {
            preg_match_all('/`+/', $text, $matches);
            $longest = $matches[0] === [] ? 0 : max(array_map('strlen', $matches[0]));
            $fence = str_repeat('`', max(3, $longest + 1));
            $rendered = $fence . "\n" . $text . "\n" . $fence . ($truncated ? $notice : '');
            $excess = mb_strlen($rendered) - $limit;
            if ($excess > 0) {
                $truncated = true;
                $text = mb_substr($text, 0, max(0, mb_strlen($text) - $excess - mb_strlen($notice)));
            }
        } while ($excess > 0);
        return $rendered;
    }
}
