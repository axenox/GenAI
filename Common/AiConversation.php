<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiConversationAgentVersionMismatchError;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Exceptions\Behaviors\ConcurrentWriteError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;

/**
 * Authorized persistence of typed interaction history, independent of LLM connections.
 */
class AiConversation implements AiConversationInterface
{
    private WorkbenchInterface $workbench;
    private string $conversationId;
    private ?array $conversationData = null;
    private array $writtenMessageIds = [];
    /** @var array<string, DataSheetInterface> System-column snapshots retained until detail completion. */
    private array $toolCallUpdates = [];
    private array $context;

    /** Creates a conversation or validates an existing exact-version owner. */
    public function __construct(private AiTaskHandlerInterface $handler, ?string $conversationId = null, array $context = [], ?DataTransactionInterface $transaction = null)
    {
        $this->workbench = $handler->getWorkbench();
        $this->context = $context;
        $this->context['type'] = $context['type'] ?? 'agent';
        if (!in_array($this->context['type'], ['agent', 'mcp'], true)) {
            throw new \InvalidArgumentException('Unknown AI conversation source');
        }
        $this->conversationId = $conversationId ?? $this->saveNewConversation($transaction);
        $this->getConversationData();
    }

    /** Persists initial owner, source and independent execution context. */
    private function saveNewConversation(?DataTransactionInterface $transaction = null) : string
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $sheet->addRow([
            'AI_AGENT' => $this->handler->getUid(),
            'AI_AGENT_VERSION_NO' => $this->handler->getVersion(),
            'USER' => $this->userId(),
            'TITLE' => $this->context['title'] ?? '',
            'TYPE' => $this->context['type'],
            'TOPIC_ID' => $this->context['topic_id'] ?? null,
            'DEVMODE' => !empty($this->context['devmode']) ? 1 : 0,
            'CONNECTION' => $this->context['connection'] ?? null,
            'NEXT_MESSAGE_SEQUENCE' => 0,
            'MCP_GROUP_KEY' => $this->context['group_key'] ?? null,
            'DATA' => AiPayload::encode($this->context['data'] ?? [])
        ]);
        $sheet->dataCreate(false, $transaction);
        return $sheet->getUidColumn()->getValue(0);
    }

    /** Returns the authenticated principal, never a caller-supplied user. */
    private function userId() : string
    {
        return $this->workbench->getSecurity()->getAuthenticatedUser()->getUid();
    }

    /** {@inheritDoc} */
    public function getConversationId() : string { return $this->conversationId; }
    /** {@inheritDoc} */
    public function getTaskHandler() : AiTaskHandlerInterface { return $this->handler; }
    /** Returns UIDs written by this instance for response-turn isolation. */
    public function getWrittenMessageIds() : array { return $this->writtenMessageIds; }
    /** {@inheritDoc} */
    public function getTitle() : string { return (string) $this->getConversationData()['TITLE']; }

    /** Builds a conversation sheet constrained to the current authenticated owner. */
    private function conversationSheet() : DataSheetInterface
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $sheet->getFilters()->addConditionFromString('UID', $this->conversationId);
        $sheet->getFilters()->addConditionFromString('USER', $this->userId());
        $sheet->getFilters()->addConditionFromString('AI_AGENT', $this->handler->getUid());
        $sheet->getFilters()->addConditionFromString('AI_AGENT_VERSION_NO', $this->handler->getVersion());
        return $sheet;
    }

    /** Loads source and exact-version relations under authenticated-user authorization. */
    private function getConversationData() : array
    {
        if ($this->conversationData !== null) {
            return $this->conversationData;
        }
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $sheet->getFilters()->addConditionFromString('UID', $this->conversationId);
        $sheet->getFilters()->addConditionFromString('USER', $this->userId());
        $sheet->getColumns()->addMultiple(['TITLE', 'AI_AGENT', 'AI_AGENT_VERSION_NO', 'AGENT_VERSION', 'TYPE', 'DEVMODE']);
        $sheet->dataRead();
        if ($sheet->countRows() !== 1) {
            throw new AiConversationNotFoundError("AI conversation '{$this->conversationId}' not found");
        }
        $row = $sheet->getRow(0);
        if (strcasecmp($row['AI_AGENT'], $this->handler->getUid()) !== 0
            || (string) $row['AI_AGENT_VERSION_NO'] !== $this->handler->getVersion()
            || trim((string) $row['AGENT_VERSION']) === '') {
            throw new AiConversationAgentVersionMismatchError(
                $this->conversationId, $row['AI_AGENT'], (string) $row['AI_AGENT_VERSION_NO'],
                $this->handler->getUid(), $this->handler->getVersion(), $row['AGENT_VERSION'] ?: null
            );
        }
        if ($row['TYPE'] !== $this->context['type']) {
            throw new RuntimeException('AI conversation source does not match its task handler');
        }
        if (array_key_exists('devmode', $this->context)
            && (bool) $row['DEVMODE'] !== (bool) $this->context['devmode']) {
            throw new RuntimeException('Cannot change development mode on an existing conversation');
        }
        return $this->conversationData = $row;
    }

    /** {@inheritDoc} */
    public function setTitle(string $title) : AiConversationInterface
    {
        $this->getConversationData();
        $sheet = $this->conversationSheet();
        $sheet->getColumns()->addFromExpression('TITLE');
        $sheet->dataRead();
        if ($sheet->countRows() !== 1) {
            throw new AiConversationNotFoundError('Conversation title could not be updated');
        }
        $sheet = $sheet->copy()->extractSystemColumns();
        $sheet->getColumns()->addFromExpression('TITLE')->setValue(0, StringDataType::truncate($title, 100, false));
        if ($sheet->dataUpdate(false) !== 1) {
            throw new AiConversationNotFoundError('Conversation title could not be updated');
        }
        $this->conversationData['TITLE'] = $title;
        return $this;
    }

    /** Reads typed message snapshots; no live tool or provider state is resolved. */
    public function getMessages(?string $type = null, ?int $afterSequence = null, ?int $throughSequence = null) : array
    {
        $this->getConversationData();
        if ($type !== null && !in_array($type, [
            AiMessageTypeDataType::SYSTEM, AiMessageTypeDataType::USER, AiMessageTypeDataType::ASSISTANT,
            AiMessageTypeDataType::TOOL, AiMessageTypeDataType::TOOLCALLING,
            AiMessageTypeDataType::ERROR, AiMessageTypeDataType::WARNING
        ], true)) {
            throw new \InvalidArgumentException('Unknown AI message type');
        }
        $sheet = $this->messageSheet();
        $sheet->getColumns()->addMultiple([
            'UID', 'AI_CONVERSATION', 'ROLE', 'MESSAGE', 'DATA', 'SEQUENCE_NUMBER',
            'STARTED_ON', 'FINISHED_ON', 'CREATED_ON', 'TOKENS_PROMPT', 'TOKENS_COMPLETION', 'COST', 'MODEL'
        ]);
        if ($type !== null) {
            $sheet->getFilters()->addConditionFromString('ROLE', $type);
        }
        if ($afterSequence !== null) {
            $sheet->getFilters()->addConditionFromString('SEQUENCE_NUMBER', $afterSequence, ComparatorDataType::GREATER_THAN);
        }
        if ($throughSequence !== null) {
            $sheet->getFilters()->addConditionFromString('SEQUENCE_NUMBER', $throughSequence, ComparatorDataType::LESS_THAN_OR_EQUALS);
        }
        $sheet->getSorters()->addFromString('SEQUENCE_NUMBER', 'ASC');
        $sheet->dataRead();
        return array_map(fn(array $row) => new AiMessage($this->workbench, $this->hydrateAttachments($row)), $sheet->getRows());
    }

    /** Loads retained attachment content only through authorized message/file DataSheets. */
    private function hydrateAttachments(array $row) : array
    {
        if (empty($row['DATA'])) { return $row; }
        try {
            $data = AiPayload::decode($row['DATA']);
        } catch (\JsonException | \UnexpectedValueException $error) {
            // The message reader reports malformed payloads and exposes them as broken.
            return $row;
        }
        if (!is_array($data['content'] ?? null)) { return $row; }
        foreach ($data['content'] as $position => $block) {
            if (!is_array($block) && !$block instanceof \stdClass) { continue; }
            $attachment = self::map($block);
            if (($attachment['type'] ?? null) !== 'attachment') { continue; }
            try {
                if (($attachment['message_id'] ?? null) !== $row['UID']) {
                    throw new RuntimeException('Historical attachment message association is invalid');
                }
                $file = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
                $file->getFilters()->addConditionFromString('AI_MESSAGE', $row['UID']);
                $file->getFilters()->addConditionFromString('PATHNAME_RELATIVE', $attachment['path']);
                $file->getColumns()->addMultiple(['CONTENTS']);
                $file->dataRead();
                if ($file->countRows() !== 1) {
                    throw new RuntimeException('Historical attachment is missing or inaccessible');
                }
                $contents = $file->getCellValue('CONTENTS', 0);
                if (!is_string($contents) || !hash_equals($attachment['digest'], hash('sha256', $contents))) {
                    throw new RuntimeException('Historical attachment content changed');
                }
                $attachment['contents_base64'] = base64_encode($contents);
            } catch (\Throwable $error) {
                $attachment['unavailable'] = true;
                $attachment['reason'] = $error->getMessage();
                $this->workbench->getLogger()->logException($error);
            }
            $data['content'][$position] = (object) $attachment;
        }
        $row['DATA'] = AiPayload::encode($data);
        return $row;
    }

    /** Constrains every message read/write to an authorized conversation. */
    private function messageSheet(?string $uid = null) : DataSheetInterface
    {
        $this->getConversationData();
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId);
        if ($uid !== null) {
            $sheet->getFilters()->addConditionFromString('UID', $uid);
        }
        return $sheet;
    }

    /** Atomically reserves sequence and commits a complete initial message. */
    private function saveMessage(string $type, string $text, array $data = [], array $fields = []) : string
    {
        $this->getConversationData();
        $json = AiPayload::encode($data);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $transaction = $this->workbench->data()->startTransaction();
            try {
                $counter = $this->conversationSheet();
                $counter->getColumns()->addFromExpression('NEXT_MESSAGE_SEQUENCE');
                $counter->dataRead();
                if ($counter->countRows() !== 1) {
                    throw new AiConversationNotFoundError('Conversation sequence allocation denied');
                }
                $sequence = (int) $counter->getCellValue('NEXT_MESSAGE_SEQUENCE', 0);
                $reservation = $counter->copy()->extractSystemColumns();
                $reservation->getFilters()->addConditionFromString('NEXT_MESSAGE_SEQUENCE', $sequence);
                $reservation->getColumns()->addFromExpression('NEXT_MESSAGE_SEQUENCE')->setValue(0, $sequence + 1);
                try {
                    $reserved = $reservation->dataUpdate(false, $transaction);
                } catch (ConcurrentWriteError $error) {
                    $transaction->rollback();
                    continue;
                }
                if ($reserved !== 1) {
                    $transaction->rollback();
                    continue;
                }
                $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
                $message->addRow(array_merge($fields, [
                    'AI_CONVERSATION' => $this->conversationId, 'USER' => $this->userId(),
                    'ROLE' => $type, 'MESSAGE' => $text, 'DATA' => $json, 'SEQUENCE_NUMBER' => $sequence
                ]));
                $message->dataCreate(false, $transaction);
                $uid = $message->getUidColumn()->getValue(0);
                $transaction->commit();
                $this->writtenMessageIds[] = $uid;
                return $uid;
            } catch (\Throwable $error) {
                if (!$transaction->isRolledBack()) {
                    $transaction->rollback();
                }
                throw $error;
            }
        }
        throw new RuntimeException('Concurrent AI message sequence allocation exhausted');
    }

    /** {@inheritDoc} */
    public function hasSystemPrompt() : bool
    {
        $messages = $this->getMessages(AiMessageTypeDataType::SYSTEM);
        if (count($messages) > 1) {
            throw new RuntimeException('Conversation has multiple system prompts');
        }
        return count($messages) === 1;
    }

    /** {@inheritDoc} */
    public function getSystemPrompt() : string
    {
        $messages = $this->getMessages(AiMessageTypeDataType::SYSTEM);
        if (count($messages) !== 1) {
            throw new RuntimeException('Conversation must contain exactly one system prompt');
        }
        return $messages[0]->getText();
    }

    /** {@inheritDoc} */
    public function saveSystemPrompt(string $systemPrompt, array $data = []) : string
    {
        if ($this->hasSystemPrompt()) {
            throw new RuntimeException('Conversation system instructions are already persisted');
        }
        $data['content'] = $data['content'] ?? [['type' => 'text', 'text' => $systemPrompt]];
        $this->saveMessage(AiMessageTypeDataType::SYSTEM, $systemPrompt, $data);
        return $this->conversationId;
    }

    /** Persists a user message and immutable file copies with message association. */
    public function saveUserPrompt(string $userPrompt, array $files = [], array $data = []) : string
    {
        if ($this->getTitle() === '' && $this->getMessages(AiMessageTypeDataType::USER) === []) {
            $this->setTitle(StringDataType::truncate($userPrompt, 50, true, true, true));
        }
        $uid = UUIDDataType::generateSqlOptimizedUuid();
        $data['content'] = $data['content'] ?? [['type' => 'text', 'text' => $userPrompt]];
        $created = [];
        try {
            foreach ($files as $position => $file) {
                $contents = $file->read();
                $info = $file->getFileInfo();
                $filename = $info->getFilename();
                $storageName = bin2hex(random_bytes(16)) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
                $path = "data/axenox/GenAI/Conversations/{$uid}/{$storageName}";
                $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
                $sheet->getFilters()->addConditionFromString('AI_MESSAGE', $uid);
                $sheet->addRow(['PATHNAME_RELATIVE' => $path, 'CONTENTS' => $contents]);
                $sheet->dataCreate(false);
                $created[] = $path;
                $data['content'][] = [
                    'type' => 'attachment', 'message_id' => $uid, 'path' => $path, 'filename' => $filename,
                    'mime_type' => $info->getMimeType(), 'position' => $position,
                    'digest' => hash('sha256', $contents)
                ];
            }
            $this->saveMessage(AiMessageTypeDataType::USER, $userPrompt, $data, ['UID' => $uid]);
        } catch (\Throwable $error) {
            foreach ($created as $path) {
                try {
                    $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
                    $sheet->getFilters()->addConditionFromString('AI_MESSAGE', $uid);
                    $sheet->getFilters()->addConditionFromString('PATHNAME_RELATIVE', $path);
                    $sheet->dataDelete();
                } catch (\Throwable $cleanupError) {
                    $this->workbench->getLogger()->logException($cleanupError);
                }
            }
            throw $error;
        }
        return $this->conversationId;
    }

    /** Persists the full requested batch before executing any of its calls. */
    public function saveToolCalls(array $calls, array $data = []) : string
    {
        if ($calls === []) {
            throw new \InvalidArgumentException('A tool batch must contain at least one call');
        }
        $stored = [];
        $order = [];
        foreach ($calls as $call) {
            if (!$call instanceof AiToolCallInterface || $call->getCallId() === '' || isset($stored[$call->getCallId()])) {
                throw new \InvalidArgumentException('Tool batch call identities must be non-empty and unique');
            }
            $stored[$call->getCallId()] = $call instanceof AiToolCall ? $call->jsonSerialize() : [
                'call_id' => $call->getCallId(), 'tool_name' => $call->getToolName(), 'arguments' => (object) $call->getArguments()
            ];
            $order[] = $call->getCallId();
        }
        $data['calls'] = (object) $stored;
        $data['call_order'] = $order;
        $data['results'] = new \stdClass();
        $data['started_on'] = $data['started_on'] ?? date('Y-m-d H:i:s');
        return $this->saveMessage(
            AiMessageTypeDataType::TOOL, $this->renderCalls($calls), $data,
            array_merge($this->usageFields($data), ['STARTED_ON' => $data['started_on'], 'FINISHED_ON' => null])
        );
    }

    /** Normalizes only structural maps, preserving nested semantic values. */
    private static function map(mixed $value) : array
    {
        if ($value instanceof \stdClass) { return get_object_vars($value); }
        if (is_array($value)) { return $value; }
        throw new \UnexpectedValueException('Invalid AI payload structure');
    }

    /** Enriches the original message; never creates a separate result message. */
    public function saveToolResults(string $messageId, array $results, array $data = []) : void
    {
        $sheet = $this->messageSheet($messageId);
        $sheet->getColumns()->addMultiple(['DATA', 'ROLE', 'MESSAGE', 'FINISHED_ON']);
        $sheet->dataRead();
        if ($sheet->countRows() !== 1 || $sheet->getCellValue('ROLE', 0) !== AiMessageTypeDataType::TOOL) {
            throw new AiConversationNotFoundError('Combined tool message not found in the authorized conversation');
        }
        $payload = AiPayload::decode($sheet->getCellValue('DATA', 0));
        $calls = self::map($payload['calls']);
        if (count($results) !== count($calls)) {
            throw new \InvalidArgumentException('Completed tool batch must retain one result for every call');
        }
        $exported = [];
        $text = '**' . count($calls) . " tool calls**\n";
        foreach ($payload['call_order'] as $id) {
            if (!isset($results[$id]) || !$results[$id] instanceof AiToolResultInterface) {
                throw new \InvalidArgumentException('Missing typed result for a requested tool call');
            }
            $exported[$id] = $results[$id]->exportUxonObject()->toArray();
            $call = self::map($calls[$id]);
            $text .= "\n\n" . MarkdownDataType::escapeCodeBlock($call['tool_name'] . ': ' . AiPayload::encode($call['arguments']))
                . "\n\n" . $results[$id]->getValueAsMarkdown(true);
        }
        foreach (self::map($data['execution_calls'] ?? []) as $id => $executionCall) {
            $executionCall = self::map($executionCall);
            if (!isset($calls[$id]) || (string) ($executionCall['call_id'] ?? '') !== (string) $id
                || ($executionCall['tool_name'] ?? null) !== self::map($calls[$id])['tool_name']) {
                throw new \InvalidArgumentException('Normalized execution call is not part of the original batch');
            }
            $calls[$id] = array_replace(self::map($calls[$id]), array_intersect_key(
                $executionCall, array_flip(['execution_arguments', 'tool_alias'])
            ));
        }
        $payload = array_replace($payload, array_intersect_key($data, array_flip([
            'finished_on', 'timing', 'diagnostics', 'duration_ms', 'execution_calls'
        ])));
        foreach (self::map($data['timing'] ?? []) as $id => $timing) {
            if (!array_key_exists($id, $calls)) {
                throw new \InvalidArgumentException('Tool timing references a call outside the batch');
            }
            $calls[$id] = array_replace(self::map($calls[$id]), array_intersect_key(
                self::map($timing), array_flip(['started_on', 'finished_on', 'duration_ms'])
            ));
        }
        $payload['calls'] = (object) $calls;
        $payload['results'] = (object) $exported;
        $payload['finished_on'] = $data['finished_on'] ?? date('Y-m-d H:i:s');
        $sheet = $sheet->copy()->extractSystemColumns();
        $sheet->getColumns()->addFromExpression('DATA')->setValue(0, AiPayload::encode($payload));
        $sheet->getColumns()->addFromExpression('MESSAGE')->setValue(0, $text);
        $sheet->getColumns()->addFromExpression('FINISHED_ON')->setValue(0, $payload['finished_on']);
        if ($sheet->dataUpdate(false) !== 1) {
            throw new RuntimeException('Combined tool completion could not be recorded');
        }
    }

    /** Creates a linked IN_PROGRESS detail record immediately before invocation. */
    public function saveToolCallStart(string $messageId, AiToolCallInterface $call, array $data = []) : string
    {
        $message = $this->messageSheet($messageId);
        $message->getColumns()->addFromExpression('DATA');
        $message->dataRead();
        if ($message->countRows() !== 1) {
            throw new AiConversationNotFoundError('Tool batch is not in this conversation');
        }
        $payload = AiPayload::decode($message->getCellValue('DATA', 0));
        $index = array_search($call->getCallId(), $payload['call_order'], true);
        if ($index === false) {
            throw new \InvalidArgumentException('Individual call is not part of the stored batch');
        }
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_TOOL_CALL');
        $sheet->addRow([
            'AI_CONVERSATION' => $this->conversationId, 'AI_MESSAGE' => $messageId,
            'CALL_ID' => $call->getCallId(), 'CALL_INDEX' => $index,
            'TOOL_NAME' => $call->getToolName(), 'TOOL_ALIAS' => $call instanceof AiToolCall ? $call->getToolAlias() : ($data['tool_alias'] ?? null),
            'CALL_DISPLAY' => (string) $call, 'ARGUMENTS' => AiPayload::encode((object) $call->getArguments()),
            'STATE' => 'IN_PROGRESS', 'STARTED_ON' => $data['started_on'] ?? date('Y-m-d H:i:s')
        ]);
        $sheet->dataCreate(false);
        $uid = $sheet->getUidColumn()->getValue(0);
        $this->toolCallUpdates[$uid] = $sheet->copy()->extractSystemColumns();
        return $uid;
    }

    /** Writes completion by the existing detail UID, with no duplicate-completion lookup. */
    public function saveToolCallEnd(string $recordId, AiToolResultInterface $result, array $data = []) : void
    {
        $this->getConversationData();
        if (!isset($this->toolCallUpdates[$recordId])) {
            throw new RuntimeException('Tool detail completion requires its original system-column snapshot');
        }
        $sheet = $this->toolCallUpdates[$recordId]->copy();
        unset($this->toolCallUpdates[$recordId]);
        $sheet->getFilters()->addConditionFromString('UID', $recordId);
        $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId);
        $text = $result->getValueAsMarkdown(true);
        $updates = [
            'RESULT' => $text, 'RESULT_LENGTH_CHARS' => mb_strlen($text, 'UTF-8'),
            'EXECUTION_TIME_MS' => isset($data['duration_ms']) ? (int) round($data['duration_ms']) : (int) round($result->getDurationMs() ?? 0),
            'STATE' => $result->isFailed() ? 'FAILED' : 'SUCCEEDED',
            'FINISHED_ON' => $data['finished_on'] ?? date('Y-m-d H:i:s')
        ];
        foreach ($updates as $attribute => $value) {
            $sheet->getColumns()->addFromExpression($attribute)->setValue(0, $value);
        }
        if ($sheet->dataUpdate(false) !== 1) {
            throw new RuntimeException('Tool detail completion could not be recorded');
        }
    }

    /** Extracts optional usage facts, never inferring them from owner capabilities. */
    private function usageFields(array $data) : array
    {
        $fields = [];
        foreach (['model' => 'MODEL', 'tokens_prompt' => 'TOKENS_PROMPT', 'tokens_completion' => 'TOKENS_COMPLETION',
            'cost' => 'COST', 'finish_reason' => 'FINISH_REASON'] as $key => $attribute) {
            if (array_key_exists($key, $data)) { $fields[$attribute] = $data[$key]; }
        }
        return $fields;
    }

    /** {@inheritDoc} */
    public function saveResponse(AiQueryInterface $query, string $answer, ?array $fullJsonResponse = null) : void
    {
        $data = $query->getResponseMessage();
        $data['content'] = $data['content'] ?? [['type' => 'text', 'text' => $query->getAnswerRaw()]];
        if ($fullJsonResponse !== null) { $data['answer_json'] = (object) $fullJsonResponse; }
        $data['tokens_prompt'] = $query->getTokensInPrompt();
        $data['tokens_completion'] = $query->getTokensInAnswer();
        $data['cost'] = $query->getCosts();
        $data['finish_reason'] = $query->getFinishReason();
        $this->saveMessage(AiMessageTypeDataType::ASSISTANT, $answer, $data, $this->usageFields($data));
        $this->saveWarnings($query->getWarnings());
    }

    /** Renders inspection text, not provider context. */
    private function renderCalls(array $calls) : string
    {
        $text = '**' . count($calls) . " tool calls**\n";
        foreach ($calls as $call) { $text .= "\n" . MarkdownDataType::escapeCodeBlock((string) $call); }
        return $text;
    }

    /** Logs a real error and writes its sole ERROR_LOG_ID-bearing conversation message. */
    public function saveError(\Throwable $error, array $tools = [], ?array $responseJsonSchema = null, array $data = []) : ExceptionInterface
    {
        if (!$error instanceof ExceptionInterface) {
            $error = new RuntimeException($error->getMessage(), null, $error);
        }
        $this->workbench->getLogger()->logException($error);
        $data = array_replace($data, [
            'class' => get_class($error), 'message' => $error->getMessage(),
            'code' => $error->getCode(), 'responseJsonSchema' => $responseJsonSchema
        ]);
        if ($tools !== []) {
            $data['tools'] = array_map(fn($tool) => $tool->exportUxonObject()->toArray(), $tools);
        }
        $this->saveMessage(AiMessageTypeDataType::ERROR, MarkdownDataType::escapeCodeBlock($error->getMessage()),
            $data, ['ERROR_LOG_ID' => $error->getId()]);
        $sheet = $this->conversationSheet();
        $sheet->getColumns()->addMultiple(['RATING', 'RATING_FEEDBACK']);
        $sheet->dataRead();
        if ($sheet->countRows() === 1) {
            $sheet->setCellValue('RATING_FEEDBACK', 0, trim((string) $sheet->getCellValue('RATING_FEEDBACK', 0)
                . "\nError: " . StringDataType::truncate($error->getMessage(), 500, true)));
            if ($sheet->getCellValue('RATING', 0) === null) { $sheet->setCellValue('RATING', 0, 1); }
            $sheet->dataUpdate(false);
        }
        return $error;
    }

    /** Persists external-to-tool warnings, without ERROR_LOG_ID. */
    public function saveWarnings(array $warnings) : void
    {
        foreach ($warnings as $warning) {
            $message = $warning instanceof \Throwable ? $warning->getMessage() : (string) $warning;
            $this->saveMessage(AiMessageTypeDataType::WARNING, MarkdownDataType::escapeCodeBlock($message), ['message' => $message]);
        }
    }
}
