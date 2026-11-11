<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Common\Messages\AbstractAiMessage;
use axenox\GenAI\Common\Messages\AiMessageSerializer;
use axenox\GenAI\Common\Messages\DiagnosticMessage;
use axenox\GenAI\Common\Messages\InputMessage;
use axenox\GenAI\Common\Messages\InstructionsMessage;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use axenox\GenAI\Common\Messages\UnavailableAttachment;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\DataTypes\AiToolCallStateDataType;
use axenox\GenAI\Exceptions\AiConversationAgentVersionMismatchError;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolCallInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MimeTypeDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Filesystem\FileInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Owns the persisted AI history: the only component, that knows how messages are saved and loaded.
 * 
 * Messages are saved in `axenox.GenAI.AI_MESSAGE`, their attachments in `axenox.GenAI.AI_MESSAGE_FILE`
 * and details of every tool call in `axenox.GenAI.AI_TOOL_CALL`. The `DATA` format of messages
 * including all legacy formats is handled by `AiMessageSerializer`, which is used by the conversation only.
 * 
 * The `MESSAGE` column contains a designer-facing representation (`AiMessageInterface::toMarkdown()`),
 * while the model context is restored from `DATA`. For responses, the `MESSAGE` column shows the answer
 * the way the agent presents it (e.g. extracted via `response_answer_path`).
 * 
 * Usage, model and finish reason of LLM responses are saved from the query in separate columns. They are
 * not part of the messages.
 * 
 * @author Andrej Kabachnik
 */
class AiConversation implements AiConversationInterface
{
    private AiTaskHandlerInterface $handler;
    private WorkbenchInterface $workbench;
    private string $conversationId;
    private ?DataSheetInterface $conversationData = null;
    private int $nextMessageSequence = 0;
    /** @var AbstractAiMessage[] */
    private array $liveMessages = [];
    private array $generatedMessageIds = [];
    /** @var AbstractAiMessage[]|null */
    private ?array $history = null;
    /** @var array<string,array<string,mixed>> persisted DATA of every known message indexed by UID */
    private array $messageData = [];
    private array $messageSystemRows = [];
    private array $callSystemRows = [];
    private ?AiMessageSerializer $serializer = null;

    /**
     * Creates a persisted conversation or authorizes an existing exact-version history.
     * 
     * @param AiTaskHandlerInterface $handler
     * @param string|null $conversationId
     * @param array<string,mixed> $row An axenox.GenAI.AI_CONVERSATION row keyed by attribute alias.
     * New rows require TYPE from AiConversationTypeDataType; omitted DEVMODE defaults to false. DATA is serialized JSON.
     * On restoration only TYPE is used, to verify the caller's expected source.
     * @return void
     */
    public function __construct(AiTaskHandlerInterface $handler, ?string $conversationId = null, array $row = [])
    {
        $this->handler = $handler;
        $this->workbench = $handler->getWorkbench();
        if ($conversationId !== null) {
            $this->conversationId = $conversationId;
            $this->conversationData = $this->getConversationData();
            if (isset($row['TYPE']) && $this->conversationData->getCellValue('TYPE', 0) !== $row['TYPE']) {
                throw new RuntimeException('Conversation source does not match the caller.');
            }
        } else {
            $this->conversationData = $this->saveNewConversation($row);
            $this->conversationId = (string) $this->conversationData->getUidColumn()->getValue(0);
        }
    }

    /**
     * Persists a metamodel row without asking the owner for a connection or model.
     * 
     * @throws \InvalidArgumentException
     * @param array<string,mixed> $row
     * @return DataSheetInterface
     */
    protected function saveNewConversation(array $row) : DataSheetInterface
    {
        if (!in_array($row['TYPE'] ?? null, AiConversationTypeDataType::getValuesStatic(), true)) {
            throw new \InvalidArgumentException('New conversations require TYPE to be agent or mcp.');
        }
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $sheet->getColumns()->addFromSystemAttributes();
        $row += [
            'TITLE' => '',
            'TOPIC_ID' => null,
            'CONNECTION' => null,
            'MCP_GROUP_KEY' => null,
            'DATA' => '{}',
            'RATING' => null,
            'RATING_FEEDBACK' => null
        ];
        $row = array_merge($row, [
            'AI_AGENT' => $this->handler->getUid(),
            'AI_AGENT_VERSION_NO' => $this->handler->getVersion(),
            'USER' => $this->userId()
        ]);
        $sheet->addRow($row);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            $sheet->dataCreate(false, $transaction);
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollback();
            throw $error;
        }
        $sheet->getFilters()->addConditionFromColumnValues($sheet->getUidColumn());
        $sheet->getFilters()->addConditionFromString('USER', $this->userId(), ComparatorDataType::EQUALS);
        return $sheet;
    }

    /**
     * Returns the authenticated owner boundary.
     * 
     * @return string
     */
    private function userId() : string
    {
        return $this->workbench->getSecurity()->getAuthenticatedUser()->getUid();
    }

    /**
     * Loads persisted identity, mutable metadata and system columns for authorized writes.
     * 
     * @throws AiConversationNotFoundError
     * @throws AiConversationAgentVersionMismatchError
     * @throws RuntimeException
     * @return DataSheetInterface
     */
    protected function getConversationData() : DataSheetInterface
    {
        if ($this->conversationData !== null) {
            return $this->conversationData;
        }
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->getColumns()->addMultiple([
            'UID', 'TITLE', 'AI_AGENT', 'AI_AGENT_VERSION_NO', 'AGENT_VERSION', 'TYPE', 'DEVMODE',
            'AI_MESSAGE__SEQUENCE_NUMBER:MAX', 'AI_MESSAGE__UID:COUNT', 'RATING', 'RATING_FEEDBACK'
        ]);
        $sheet->getFilters()->addConditionFromString('UID', $this->conversationId, ComparatorDataType::EQUALS);
        $sheet->getFilters()->addConditionFromString('USER', $this->userId(), ComparatorDataType::EQUALS);
        $sheet->dataRead();
        if ($sheet->countRows() !== 1) {
            throw new AiConversationNotFoundError("AI conversation '{$this->conversationId}' not found.");
        }
        $row = $sheet->getRow(0);
        if (strcasecmp((string) $row['AI_AGENT'], $this->handler->getUid()) !== 0
            || (string) $row['AI_AGENT_VERSION_NO'] !== $this->handler->getVersion()
            || empty($row['AGENT_VERSION'])) {
            throw new AiConversationAgentVersionMismatchError(
                $this->conversationId, (string) $row['AI_AGENT'], (string) $row['AI_AGENT_VERSION_NO'],
                $this->handler->getUid(), $this->handler->getVersion(), $row['AGENT_VERSION'] ?: null
            );
        }
        $maximumSequence = $sheet->getColumns()->getByExpression('AI_MESSAGE__SEQUENCE_NUMBER:MAX')->getValue(0);
        // Reverse numeric aggregates return zero even when no messages exist.
        $messageCount = $sheet->getColumns()->getByExpression('AI_MESSAGE__UID:COUNT')->getValue(0);
        $this->nextMessageSequence = (int) $messageCount === 0 ? 0 : (int) $maximumSequence + 1;
        $this->conversationData = $sheet;
        return $sheet;
    }
    
    /**
     * Writes only changed fields and system columns, then retains behavior-generated values.
     * 
     * @param array<string,mixed> $values
     * @return DataSheetInterface
     */
    protected function updateConversationData(array $values) : DataSheetInterface
    {
        $conversationData = $this->getConversationData();
        $updateSheet = $conversationData->extractSystemColumns();
        $updateSheet->setRowValues(0, $values, true, true);
        if ($updateSheet->dataUpdate(false) !== 1) {
            throw new AiConversationNotFoundError('Conversation no longer available.');
        }
        $conversationData->setRowValues(0, $updateSheet->getRow(0), true, true);
        return $updateSheet;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::getConversationId()
     */
    public function getConversationId() : string
    {
        return $this->conversationId;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::getTaskHandler()
     */
    public function getTaskHandler() : AiTaskHandlerInterface
    {
        return $this->handler;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::getTitle()
     */
    public function getTitle() : string
    {
        return (string) $this->getConversationData()->getCellValue('TITLE', 0);
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::setTitle()
     */
    public function setTitle(string $title) : AiConversationInterface
    {
        if ($title !== $this->getTitle()) {
            $this->updateConversationData(['TITLE' => $title]);
        }
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::getMessages()
     */
    public function getMessages(?string $type = null) : array
    {
        $this->getConversationData();
        if ($type !== null && !in_array($type, AiMessageTypeDataType::getValuesStatic(), true)) {
            throw new \InvalidArgumentException('Unknown AI message type: ' . $type);
        }
        if ($this->history === null) {
            $this->loadHistory();
        }
        $messages = [];
        foreach (array_replace($this->history, $this->liveMessages) as $id => $message) {
            if ($type === null || $message->getType() === $type) {
                $messages[$id] = $message;
            }
        }
        $messages = array_values($messages);
        usort($messages, static fn(AiMessageInterface $a, AiMessageInterface $b) =>
            ($a->getSequenceNumber() <=> $b->getSequenceNumber()) ?: strcasecmp($a->getUid(), $b->getUid()));
        return $messages;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::getMessagesActive()
     */
    public function getMessagesActive() : array
    {
        // No message type supersedes other messages yet. Future types like summaries will refine this.
        return $this->getMessages();
    }

    /**
     * Reads all messages of the conversation and restores them including their attachments
     * 
     * @return void
     */
    private function loadHistory() : void
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->getColumns()->addMultiple(['UID', 'AI_CONVERSATION', 'ROLE', 'MESSAGE', 'DATA', 'SEQUENCE_NUMBER', 'STARTED_ON', 'FINISHED_ON']);
        $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId, ComparatorDataType::EQUALS);
        $sheet->getSorters()->addFromString('SEQUENCE_NUMBER', 'ASC');
        $sheet->getSorters()->addFromString('UID', 'ASC');
        $sheet->dataRead();
        $this->history = [];
        $systemRows = $sheet->extractSystemColumns()->getRows();
        $serializer = $this->getSerializer();
        foreach ($sheet->getRows() as $index => $row) {
            $data = $serializer->decode($row['DATA'] ?? null);
            $message = $serializer->fromRow($row, $data, $this->loadAttachments($row, $data));
            $this->history[$message->getUid()] = $message;
            $this->messageData[$message->getUid()] = $data;
            $this->messageSystemRows[$message->getUid()] = $systemRows[$index];
        }
    }

    /**
     * Returns the serializer, that knows the persisted DATA format of messages
     * 
     * @return AiMessageSerializer
     */
    private function getSerializer() : AiMessageSerializer
    {
        if ($this->serializer === null) {
            $this->serializer = new AiMessageSerializer($this->workbench);
        }
        return $this->serializer;
    }

    /**
     * Returns only identities generated by this runtime turn, excluding concurrent turns.
     * 
     * @return AiMessageInterface[]
     */
    public function getGeneratedMessages() : array
    {
        return array_values(array_filter($this->getMessages(), fn(AiMessageInterface $message) => isset($this->generatedMessageIds[$message->getUid()])));
    }

    /**
     * Commits a message with instance-local order; concurrent requests may share a sequence.
     * 
     * The message is kept in memory together with its DATA, so that later updates (e.g. tool results)
     * do not lose conversation metadata.
     * 
     * @throws AiConversationNotFoundError
     * @throws RuntimeException
     * @param AbstractAiMessage $message
     * @param array<string,mixed> $extraData Conversation metadata to save in DATA in addition to the message itself
     * @param array<string,mixed> $fields Additional AI_MESSAGE columns
     * @param string|null $display Designer-facing MESSAGE column - defaults to the markdown of the message
     * @return AbstractAiMessage The saved message with identity and sequence
     */
    private function saveMessage(AbstractAiMessage $message, array $extraData = [], array $fields = [], ?string $display = null) : AbstractAiMessage
    {
        $this->getConversationData();
        $data = array_replace($extraData, $this->getSerializer()->toData($message));
        $row = $fields + [
            'AI_CONVERSATION' => $this->conversationId, 'USER' => $this->userId(),
            'ROLE' => $message->getType(), 'MESSAGE' => $display ?? $message->toMarkdown(),
            'SEQUENCE_NUMBER' => $this->nextMessageSequence
        ];
        $row['DATA'] = $this->getSerializer()->encode($data);
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->addRow($row);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            $sheet->dataCreate(false, $transaction);
            $id = (string) $sheet->getUidColumn()->getValue(0);
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollback();
            throw $error;
        }
        $saved = $message->withIdentity($id, $this->conversationId, (int) $row['SEQUENCE_NUMBER']);
        $this->nextMessageSequence++;
        $this->generatedMessageIds[$id] = true;
        $this->liveMessages[$id] = $saved;
        $this->messageData[$id] = $data;
        $this->messageSystemRows[$id] = $sheet->extractSystemColumns()->getRow(0);
        return $saved;
    }

    /**
     * Saves the attachments of a message first and then the message itself
     * 
     * Attachments are saved in AI_MESSAGE_FILE independently of the context role of the message - user
     * attachments and files generated by the model are treated the same way. If saving the message fails,
     * the already saved files are removed again.
     * 
     * @param AbstractAiMessage $message
     * @param array<string,mixed> $extraData
     * @param array<string,mixed> $fields
     * @param string|null $display
     * @return AbstractAiMessage
     */
    private function saveMessageWithAttachments(AbstractAiMessage $message, array $extraData = [], array $fields = [], ?string $display = null) : AbstractAiMessage
    {
        $files = $message->getAttachments();
        if ($files === []) {
            return $this->saveMessage($message, $extraData, $fields, $display);
        }
        $messageId = UUIDDataType::generateSqlOptimizedUuid();
        $paths = [];
        $refs = [];
        $attachments = [];
        try {
            foreach ($files as $file) {
                if (! $file->exists()) {
                    $this->saveWarnings(['Attachment "' . $file->getFilename() . '" could not be saved because it is not available.']);
                    continue;
                }
                $contents = $file instanceof FileInterface ? $file->read() : $file->openFile()->read();
                $filename = $file->getFilename();
                $mimeType = $file->getMimetype() ?? 'application/octet-stream';
                $identity = bin2hex(random_bytes(16));
                $extension = $file->getExtension();
                $path = "data/axenox/GenAI/Conversations/{$messageId}/{$identity}" . ($extension === '' ? '' : '.' . $extension);
                $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
                $sheet->getColumns()->addMultiple(['PATHNAME_RELATIVE', 'CONTENTS']);
                $paths[] = $path;
                $sheet->addRow([
                    'AI_MESSAGE' => $messageId,
                    'PATHNAME_RELATIVE' => $path,
                    'CONTENTS' => $contents
                ]);
                $sheet->dataCreate(false);
                $refs[] = [
                    'uid' => $identity, 'path' => $path, 'filename' => $filename,
                    'mime_type' => $mimeType, 'sha256' => hash('sha256', $contents)
                ];
                $attachments[] = new InMemoryFile($contents, $filename, $mimeType);
            }
            $extraData['attachments'] = $refs;
            return $this->saveMessage($message->withAttachments($attachments), $extraData, ['UID' => $messageId] + $fields, $display);
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                try {
                    $cleanup = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
                    $cleanup->getFilters()->addConditionFromString('PATHNAME_RELATIVE', $path, ComparatorDataType::EQUALS);
                    $cleanup->dataDelete();
                } catch (\Throwable $cleanupError) {
                    $this->reportOperationalError($cleanupError);
                }
            }
            throw $error;
        }
    }

    /**
     * Converts messages of other implementations into own message classes, so they can be saved
     * 
     * Opaque adapter data of foreign implementations cannot be enumerated and is dropped - this is
     * allowed because adapter data is never required.
     * 
     * @param AiMessageInterface $message
     * @return AbstractAiMessage
     */
    private function toOwnMessage(AiMessageInterface $message) : AbstractAiMessage
    {
        if ($message instanceof AbstractAiMessage) {
            return $message;
        }
        if ($message instanceof AiToolCallMessageInterface) {
            return new ToolCallMessage($message->getText(), $message->getCalls(), $message->getToolResults());
        }
        return AiFactory::createMessage($message->getType(), $message->getText(), $message->getAttachments());
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::hasSystemPrompt()
     */
    public function hasSystemPrompt() : bool
    {
        return $this->getMessages(AiMessageTypeDataType::SYSTEM) !== [];
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::getSystemPrompt()
     */
    public function getSystemPrompt() : string
    {
        $messages = $this->getMessages(AiMessageTypeDataType::SYSTEM);
        if ($messages === []) {
            throw new RuntimeException('No system prompt retained in conversation "' . $this->conversationId . '".');
        }
        // Legacy conversations may contain additional instructions (e.g. former `developer` messages).
        // The first one is the system prompt of the agent.
        return $messages[0]->getText();
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveSystemPrompt()
     */
    public function saveSystemPrompt(string $systemPrompt, array $data = []) : string
    {
        if (!$this->hasSystemPrompt()) {
            $this->saveMessage(new InstructionsMessage($systemPrompt), $data);
        }
        return $this->conversationId;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveUserPrompt()
     */
    public function saveUserPrompt(string $userPrompt, array $files = [], array $data = []) : string
    {
        if ($this->getTitle() === '' && $this->getMessages(AiMessageTypeDataType::USER) === []) {
            $this->setTitle(StringDataType::truncate($userPrompt, 50, true, true, true));
        }
        $attachments = [];
        foreach ($files as $file) {
            $attachments[] = $file instanceof FileInterface ? $file->getFileInfo() : $file;
        }
        $this->saveMessageWithAttachments(new InputMessage($userPrompt, $attachments), $data);
        return $this->conversationId;
    }

    /**
     * Loads the stored attachments of a message - unavailable files are kept as UnavailableAttachment
     * 
     * @param array<string,mixed> $row
     * @param array<string,mixed> $data
     * @return \exface\Core\Interfaces\Filesystem\FileInfoInterface[]
     */
    private function loadAttachments(array $row, array $data) : array
    {
        $serializer = $this->getSerializer();
        $refs = $serializer->getAttachmentReferences($data);
        // Very old user prompts did not reference their files - load all files of the message then
        $loadAll = $serializer->getType($row) === AiMessageTypeDataType::USER && $serializer->isMissingAttachmentInfo($data);
        if ($refs === [] && ! $loadAll) {
            return [];
        }
        $contents = [];
        try {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
            $sheet->getColumns()->addMultiple(['PATHNAME_RELATIVE', 'CONTENTS']);
            $sheet->getFilters()->addConditionFromString('AI_MESSAGE', $row['UID'], ComparatorDataType::EQUALS);
            $sheet->dataRead();
            foreach ($sheet->getRows() as $file) {
                $contents[$file['PATHNAME_RELATIVE']] = (string) $file['CONTENTS'];
            }
        } catch (\Throwable $error) {
            $this->logException($error);
        }
        if ($loadAll) {
            foreach (array_keys($contents) as $path) {
                $refs[] = ['path' => $path, 'filename' => basename($path), 'mime_type' => null, 'sha256' => null];
            }
        }
        $attachments = [];
        foreach ($refs as $ref) {
            $mimeType = $ref['mime_type'] ?? MimeTypeDataType::guessMimeTypeOfExtension(pathinfo($ref['filename'], PATHINFO_EXTENSION));
            switch (true) {
                case ! array_key_exists($ref['path'], $contents):
                    $attachments[] = new UnavailableAttachment($ref['filename'], $mimeType, 'file is missing or inaccessible');
                    break;
                case $ref['sha256'] !== null && ! hash_equals((string) $ref['sha256'], hash('sha256', $contents[$ref['path']])):
                    $attachments[] = new UnavailableAttachment($ref['filename'], $mimeType, 'file content has changed');
                    break;
                default:
                    $attachments[] = new InMemoryFile($contents[$ref['path']], $ref['filename'], $mimeType);
            }
        }
        return $attachments;
    }

    /**
     * Extracts generic usage facts of an LLM exchange
     * 
     * @param AiQueryInterface $query
     * @return array<string,mixed>
     */
    private function getUsageData(AiQueryInterface $query) : array
    {
        return [
            'model' => $query->getModelName(),
            'tokens_prompt' => $query->getTokensInPrompt(),
            'tokens_completion' => $query->getTokensInAnswer(),
            'cost' => $query->getCosts(),
            'finish_reason' => $query->getFinishReason()
        ];
    }

    /**
     * Converts generic usage facts into nullable database columns.
     * 
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    private function getUsageFields(array $usage) : array
    {
        $fields = [];
        foreach (['tokens_prompt' => 'TOKENS_PROMPT', 'tokens_completion' => 'TOKENS_COMPLETION', 'cost' => 'COST', 'finish_reason' => 'FINISH_REASON', 'model' => 'MODEL'] as $key => $alias) {
            if (array_key_exists($key, $usage)) {
                $fields[$alias] = $usage[$key];
            }
        }
        return $fields;
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveToolCalls()
     */
    public function saveToolCalls(AiToolCallMessageInterface $message, ?AiQueryInterface $query = null, array $data = []) : AiToolCallMessageInterface
    {
        if ($message->getCalls() === []) {
            throw new \InvalidArgumentException('A tool batch must contain calls.');
        }
        $ids = [];
        foreach ($message->getCalls() as $call) {
            if ($call->getCallId() === '' || isset($ids[$call->getCallId()])) {
                throw new \InvalidArgumentException('Tool batches require unique non-empty call IDs.');
            }
            $ids[$call->getCallId()] = true;
        }
        $fields = [];
        if ($query !== null) {
            $this->saveWarnings($query->getWarnings());
            $data['usage'] = $this->getUsageData($query);
            $fields = $this->getUsageFields($data['usage']);
        }
        $data['started_on'] = $data['started_on'] ?? date('Y-m-d H:i:s');
        $fields['STARTED_ON'] = $data['started_on'];
        return $this->saveMessage($this->toOwnMessage($message), $data, $fields);
    }

    /**
     * Loads an authorized existing batch by identity.
     * 
     * @throws RuntimeException
     * @param string $id
     * @return ToolCallMessage
     */
    private function getToolMessage(string $id) : ToolCallMessage
    {
        foreach ($this->getMessages(AiMessageTypeDataType::TOOL) as $message) {
            if (strcasecmp($message->getUid(), $id) === 0 && $message instanceof ToolCallMessage) {
                return $message;
            }
        }
        throw new RuntimeException('Tool batch ' . $id . ' not found in this conversation.');
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveToolResults()
     */
    public function saveToolResults(string $messageId, array $results, array $data = []) : void
    {
        $message = $this->getToolMessage($messageId);
        $messageId = $message->getUid();
        $expected = array_map(static fn(AiToolCallInterface $call) => $call->getCallId(), $message->getCalls());
        if (count($results) !== count($expected) || array_diff($expected, array_map('strval', array_keys($results))) !== []) {
            throw new \InvalidArgumentException('Batch completion requires exactly one result per stored call ID.');
        }
        if (array_key_exists('calls', $data) || array_key_exists('results', $data)) {
            throw new \InvalidArgumentException('Batch completion cannot replace retained call or result identities.');
        }
        foreach ($results as $result) {
            if (!$result instanceof AiToolResultInterface) {
                throw new \InvalidArgumentException('Batch completion accepts typed tool results only.');
            }
        }

        // Keep the completed message in memory first, so a storage problem does not change the model context
        $completed = $message->withToolResults($results);
        $this->liveMessages[$messageId] = $completed;

        $diagnostics = [];
        foreach ($results as $callId => $result) {
            $freshErrors = array_values(array_filter($result->getExceptions(), static fn(ExceptionInterface $error) =>
                LogLevelDataType::compareLogLevels($error->getLogLevel(), LoggerInterface::ERROR) >= 0
            ));
            foreach (['error' => $result->getErrors(), 'warning' => $result->getWarnings()] as $severity => $messages) {
                foreach ($messages as $index => $text) {
                    $diagnostic = ['id' => $messageId . ':' . $callId . ':' . $severity . ':' . $index, 'source' => 'tool', 'severity' => $severity, 'call_id' => (string) $callId];
                    if ($severity === 'error') {
                        $error = $freshErrors[$index] ?? new RuntimeException($text);
                        try {
                            $this->logException($error);
                            $diagnostic['error_message_uid'] = $this->saveMessage(
                                new DiagnosticMessage(AiMessageTypeDataType::ERROR, $text),
                                ['diagnostic_id' => $diagnostic['id']],
                                ['ERROR_LOG_ID' => $error->getId()]
                            )->getUid();
                            $this->saveErrorFeedback($text);
                        } catch (\Throwable $loggingError) {
                            $this->reportOperationalError($loggingError);
                        }
                    } else {
                        try {
                            $diagnostic['warning_message_uid'] = $this->saveMessage(
                                new DiagnosticMessage(AiMessageTypeDataType::WARNING, $text),
                                ['diagnostic_id' => $diagnostic['id']]
                            )->getUid();
                        } catch (\Throwable $loggingError) {
                            $this->reportOperationalError($loggingError);
                        }
                    }
                    $diagnostics[] = $diagnostic;
                }
            }
        }

        $finishedOn = $data['finished_on'] ?? date('Y-m-d H:i:s');
        $timing = $data['call_timing'] ?? [];
        unset($data['call_timing']);
        $stored = array_replace($this->messageData[$messageId] ?? [], $data, $this->getSerializer()->toData($completed));
        foreach ($stored['calls'] as $index => $entry) {
            $stored['calls'][$index] = array_replace($entry, (array) ($timing[$entry['call_id']] ?? []));
        }
        $stored['diagnostics'] = $diagnostics;
        $stored['finished_on'] = $finishedOn;
        $this->messageData[$messageId] = $stored;

        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId, ComparatorDataType::EQUALS);
        $sheet->getFilters()->addConditionFromString('UID', $messageId, ComparatorDataType::EQUALS);
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->addRow(($this->messageSystemRows[$messageId] ?? []) + [
            'DATA' => $this->getSerializer()->encode($stored), 
            'MESSAGE' => $completed->toMarkdown(), 
            'FINISHED_ON' => $finishedOn
        ]);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            if ($sheet->dataUpdate(false, $transaction) !== 1) {
                throw new RuntimeException('Tool message completion did not update its existing record.');
            }
            $transaction->commit();
            $this->messageSystemRows[$messageId] = $sheet->extractSystemColumns()->getRow(0);
        } catch (\Throwable $error) {
            $transaction->rollback();
            throw $error;
        }
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveToolCallStart()
     */
    public function saveToolCallStart(string $messageId, AiToolCallInterface $call, array $data = []) : string
    {
        $message = $this->getToolMessage($messageId);
        $index = null;
        foreach ($message->getCalls() as $i => $storedCall) {
            if ($storedCall->getCallId() === $call->getCallId()) {
                $index = $i + 1;
                break;
            }
        }
        if ($index === null) {
            throw new \InvalidArgumentException('Detail call does not belong to the batch.');
        }
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_TOOL_CALL');
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->addRow([
            'AI_CONVERSATION' => $this->conversationId, 'AI_MESSAGE' => $message->getUid(),
            'CALL_ID' => $call->getCallId(), 'CALL_INDEX' => $index,
            'TOOL_NAME' => $call->getToolName(), 'TOOL_ALIAS' => $call->getToolAlias(),
            'CALL_DISPLAY' => (string) $call,
            'ARGUMENTS' => json_encode($call->getOriginalArguments(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'STATE' => AiToolCallStateDataType::IN_PROGRESS, 'STARTED_ON' => $data['started_on'] ?? date('Y-m-d H:i:s')
        ]);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            $sheet->dataCreate(false, $transaction);
            $transaction->commit();
            $id = (string) $sheet->getUidColumn()->getValue(0);
            $this->callSystemRows[$id] = $sheet->extractSystemColumns()->getRow(0);
            return $id;
        } catch (\Throwable $error) {
            $transaction->rollback();
            throw $error;
        }
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveToolCallEnd()
     */
    public function saveToolCallEnd(string $recordId, AiToolResultInterface $result, array $data = []) : void
    {
        $this->getConversationData();
        $text = $result->getValueWithMetadata();
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_TOOL_CALL');
        $sheet->getColumns()->addFromSystemAttributes();
        $sheet->getFilters()->addConditionFromString('UID', $recordId, ComparatorDataType::EQUALS);
        $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId, ComparatorDataType::EQUALS);
        if (!isset($this->callSystemRows[$recordId])) {
            throw new RuntimeException('Cannot complete a call without its initial system-column snapshot.');
        }
        $sheet->addRow($this->callSystemRows[$recordId] + [
            'STATE' => $result->isFailed() ? AiToolCallStateDataType::FAILED : AiToolCallStateDataType::SUCCEEDED,
            'FINISHED_ON' => $data['finished_on'] ?? date('Y-m-d H:i:s'),
            'RESULT' => $text, 'RESULT_LENGTH_CHARS' => mb_strlen($text),
            'EXECUTION_TIME_MS' => $result->getDurationMs()
        ]);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            if ($sheet->dataUpdate(false, $transaction) !== 1) {
                throw new RuntimeException('Tool detail completion did not update its existing record.');
            }
            $transaction->commit();
            $this->callSystemRows[$recordId] = $sheet->extractSystemColumns()->getRow(0);
        } catch (\Throwable $error) {
            $transaction->rollback();
            throw $error;
        }
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveResponse()
     */
    public function saveResponse(AiQueryInterface $query) : AiMessageInterface
    {
        $message = $query->getResponseMessage();
        if ($message instanceof AiToolCallMessageInterface) {
            return $this->saveToolCalls($message, $query);
        }
        $this->saveWarnings($query->getWarnings());
        $usage = $this->getUsageData($query);
        // Show designers the answer as the agent presents it - e.g. extracted via response_answer_path
        try {
            $display = $query->getAnswerMarkdown();
        } catch (\Throwable $e) {
            $display = null;
        }
        return $this->saveMessageWithAttachments($this->toOwnMessage($message), ['usage' => $usage], $this->getUsageFields($usage), $display);
    }

    /**
     * Logs at the one reporting boundary; logger failure cannot hide conversation evidence.
     * 
     * @param \Throwable $error
     * @return void
     */
    private function logException(\Throwable $error) : void
    {
        try {
            $this->workbench->getLogger()->logException($error);
        } catch (\Throwable $loggingError) {
            error_log('AI platform logger failed: ' . $loggingError->getMessage() . '; original: ' . $error->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveError()
     */
    public function saveError(\Throwable $error, array $tools = [], ?array $responseJsonSchema = null) : ExceptionInterface
    {
        if (!$error instanceof ExceptionInterface) {
            $error = new RuntimeException('AI interaction failed. ' . $error->getMessage(), null, $error);
        }
        $this->logException($error);
        $this->saveMessage(new DiagnosticMessage(AiMessageTypeDataType::ERROR, $error->getMessage()), [], ['ERROR_LOG_ID' => $error->getId()]);
        $this->saveErrorFeedback($error->getMessage());
        return $error;
    }

    /**
     * Preserves automatic feedback for real errors without overwriting an existing rating.
     * 
     * @throws AiConversationNotFoundError
     * @param string $message
     * @return void
     */
    private function saveErrorFeedback(string $message) : void
    {
        $sheet = $this->getConversationData();
        $feedback = trim((string) $sheet->getCellValue('RATING_FEEDBACK', 0));
        $feedback .= ($feedback === '' ? '' : "\n\n") . 'Error: ' . StringDataType::truncate($message, 500, true, false, true);
        $values = ['RATING_FEEDBACK' => $feedback];
        if ($sheet->getCellValue('RATING', 0) === null || $sheet->getCellValue('RATING', 0) === '') {
            $values['RATING'] = 1;
        }
        $this->updateConversationData($values);
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::reportOperationalError()
     */
    public function reportOperationalError(\Throwable $error) : void
    {
        try {
            $this->saveError($error);
        } catch (\Throwable $persistenceError) {
            $this->logException($persistenceError);
        }
    }

    /**
     * {@inheritDoc}
     * @see AiConversationInterface::saveWarnings()
     */
    public function saveWarnings(array $warnings) : void
    {
        foreach ($warnings as $warning) {
            $text = $warning instanceof \Throwable ? $warning->getMessage() : (string) $warning;
            $this->saveMessage(new DiagnosticMessage(AiMessageTypeDataType::WARNING, $text));
        }
    }
}
