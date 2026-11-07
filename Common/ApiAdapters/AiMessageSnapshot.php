<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Factories\AiToolResultFactory;
use axenox\GenAI\Interfaces\AiMessageInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Immutable exchange input retaining message identity and completeness without storage access.
 */
final class AiMessageSnapshot implements AiMessageInterface
{
    private string $uid;
    private string $conversationId;
    private int $sequence;
    private string $text;
    private string $role;
    private bool $broken;
    private string $data;
    private array $calls = [];
    private array $results = [];

    /** Captures full typed values without sharing mutable JSON objects with a live message. */
    public function __construct(private WorkbenchInterface $workbench, AiMessageInterface $message, array $toolResults = [])
    {
        $this->uid = $message->getUid();
        $this->conversationId = $message->getConversationId();
        $this->sequence = $message->getSequenceNumber();
        $this->text = $message->getText();
        $this->role = $message->getRole();
        $this->broken = $message->isBroken();
        $this->data = AiPayload::encode($message->getData()->toArray());
        try {
            foreach ($message->getCalls() as $call) {
                $this->calls[] = AiPayload::encode([
                    'call_id' => $call->getCallId(), 'tool_name' => $call->getToolName(),
                    'arguments' => (object) $call->getArguments(),
                    'raw_arguments' => method_exists($call, 'getRawArguments') ? $call->getRawArguments() : null,
                    'execution_arguments' => method_exists($call, 'getExecutionArguments') ? $call->getExecutionArguments() : null,
                    'tool_alias' => method_exists($call, 'getToolAlias') ? $call->getToolAlias() : null,
                ]);
            }
            foreach ($message->getToolResults() as $id => $result) {
                $this->results[$id] = AiPayload::encode($result->exportUxonObject()->toArray());
            }
        } catch (\Throwable $error) {
            if (!$this->broken) {
                throw $error;
            }
            $this->calls = [];
            $this->results = [];
        }
        $data = AiPayload::decode($this->data);
        foreach ($this->calls as $callPayload) {
            $id = AiPayload::decode($callPayload)['call_id'];
            if (isset($toolResults[$id])) {
                $this->results[$id] = AiPayload::encode($toolResults[$id]->exportUxonObject()->toArray());
                $retained = (array) ($data['results'] ?? []);
                $retained[$id] = (object) AiPayload::decode($this->results[$id]);
                $data['results'] = (object) $retained;
            }
        }
        $this->data = AiPayload::encode($data);
    }

    /** {@inheritDoc} */
    public function getUid() : string { return $this->uid; }
    /** {@inheritDoc} */
    public function getConversationId() : string { return $this->conversationId; }
    /** {@inheritDoc} */
    public function getSequenceNumber() : int { return $this->sequence; }
    /** {@inheritDoc} */
    public function getText() : string { return $this->text; }
    /** {@inheritDoc} */
    public function getRole() : string { return $this->role; }
    /** {@inheritDoc} */
    public function isBroken() : bool { return $this->broken; }
    /** {@inheritDoc} */
    public function getData() : UxonObject { return new UxonObject(AiPayload::decode($this->data)); }

    /** {@inheritDoc} */
    public function getCalls() : array
    {
        $calls = [];
        foreach ($this->calls as $payload) {
            $call = AiPayload::decode($payload);
            $calls[] = new AiToolCall(
                $call['tool_name'], $call['call_id'], (array) $call['arguments'],
                $call['raw_arguments'], $call['execution_arguments'] === null ? null : (array) $call['execution_arguments'],
                $call['tool_alias']
            );
        }
        return $calls;
    }

    /** {@inheritDoc} */
    public function getToolResults() : array
    {
        $results = [];
        foreach ($this->results as $id => $payload) {
            $results[$id] = AiToolResultFactory::createFromUxon($this->workbench, new UxonObject(AiPayload::decode($payload)));
        }
        return $results;
    }
}
