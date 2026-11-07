<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Factories\AiToolResultFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * A detached persisted message with reconstructable calls and typed results.
 */
class AiMessage implements AiMessageInterface
{
    private array $data;
    private bool $broken = false;

    /** Captures the stored payload without resolving or executing historical tools. */
    public function __construct(private WorkbenchInterface $workbench, private array $row)
    {
        try {
            $this->data = empty($row['DATA']) ? [] : AiPayload::decode($row['DATA']);
        } catch (\JsonException | \UnexpectedValueException $error) {
            $this->data = ['unavailable' => true, 'reason' => $error->getMessage()];
            $this->broken = true;
            $workbench->getLogger()->logException($error);
        }
    }

    /** {@inheritDoc} */
    public function getUid() : string { return (string) $this->row['UID']; }
    /** {@inheritDoc} */
    public function getConversationId() : string { return (string) $this->row['AI_CONVERSATION']; }
    /** {@inheritDoc} */
    public function getSequenceNumber() : int { return (int) $this->row['SEQUENCE_NUMBER']; }
    /** {@inheritDoc} */
    public function getText() : string { return (string) $this->row['MESSAGE']; }
    /** {@inheritDoc} */
    public function getRole() : string { return (string) $this->row['ROLE']; }
    /** {@inheritDoc} */
    public function getData() : UxonObject
    {
        return new UxonObject(AiPayload::decode(AiPayload::encode($this->data)));
    }

    /** Returns a structural map without converting nested semantic JSON values. */
    private static function map(mixed $value) : array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }
        if (is_array($value)) {
            return $value;
        }
        throw new \UnexpectedValueException('Invalid conversation payload map');
    }

    /** {@inheritDoc} */
    public function getCalls() : array
    {
        if (!in_array($this->getRole(), [AiMessageTypeDataType::TOOL, AiMessageTypeDataType::TOOLCALLING], true)) {
            return [];
        }
        $calls = [];
        $stored = self::map($this->data['calls'] ?? []);
        foreach ($this->data['call_order'] ?? array_keys($stored) as $id) {
            if (!isset($stored[$id])) {
                throw new \UnexpectedValueException('Conversation call order references a missing call');
            }
            $call = self::map($stored[$id]);
            if (!isset($call['call_id'], $call['tool_name']) || (string) $call['call_id'] !== (string) $id) {
                throw new \UnexpectedValueException('Conversation call identity does not match its stored key');
            }
            $calls[] = new AiToolCall(
                (string) $call['tool_name'],
                (string) $call['call_id'],
                self::map($call['arguments']),
                $call['raw_arguments'] ?? null,
                isset($call['execution_arguments']) ? self::map($call['execution_arguments']) : null,
                $call['tool_alias'] ?? null
            );
        }
        if ($calls === [] && $this->getRole() === AiMessageTypeDataType::TOOLCALLING) {
            foreach ($this->data['tool_calls'] ?? [] as $legacy) {
                $legacy = self::map($legacy);
                $function = self::map($legacy['function'] ?? []);
                $raw = $function['arguments'] ?? '{}';
                $arguments = is_string($raw) ? AiPayload::decode($raw) : self::map($raw);
                $calls[] = new AiToolCall($function['name'], $legacy['id'], $arguments, is_string($raw) ? $raw : null);
            }
        }
        return $calls;
    }

    /** {@inheritDoc} */
    public function getToolResults() : array
    {
        if (!in_array($this->getRole(), [AiMessageTypeDataType::TOOL, AiMessageTypeDataType::TOOLCALLING], true)) {
            return [];
        }
        $results = [];
        foreach (self::map($this->data['results'] ?? []) as $id => $result) {
            $results[$id] = AiToolResultFactory::createFromUxon($this->workbench, new UxonObject(self::map($result)));
        }
        return $results;
    }

    /** {@inheritDoc} */
    public function isBroken() : bool
    {
        if ($this->broken || !empty($this->data['unavailable'])) {
            return true;
        }
        try {
            foreach (is_array($this->data['content'] ?? null) ? $this->data['content'] : [] as $block) {
                if (($block instanceof \stdClass && !empty($block->unavailable))
                    || (is_array($block) && !empty($block['unavailable']))) {
                    return true;
                }
            }
            if (in_array($this->getRole(), [AiMessageTypeDataType::TOOL, AiMessageTypeDataType::TOOLCALLING], true)) {
                $calls = $this->getCalls();
                $results = $this->getToolResults();
                if ($calls === [] || count($calls) !== count($results)) {
                    return true;
                }
                foreach ($calls as $call) {
                    if (!array_key_exists($call->getCallId(), $results)) {
                        return true;
                    }
                }
                return empty($this->row['FINISHED_ON']) && empty($this->data['finished_on']);
            }
        } catch (\Throwable $error) {
            $this->workbench->getLogger()->logException($error);
            $this->broken = true;
            return true;
        }
        return false;
    }
}
