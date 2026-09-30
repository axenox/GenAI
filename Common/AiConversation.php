<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiConversationAgentVersionMismatchError;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Widgets\Markdown;

/**
 * Handles all persistence and message bookkeeping for an AI conversation.
 *
 * AiFactory supplies the persisted identity. Mutable runtime state such as the next message
 * sequence number is loaded lazily by the conversation itself.
 */
class AiConversation implements AiConversationInterface
{
    private AiAgentInterface $agent;

    private WorkbenchInterface $workbench;

    private string $conversationId;

    private ?array $conversationData = null;

    private ?int $sequenceNumber = null;

    /**
     * @param AiAgentInterface $agent Owning agent instance.
     * @param string $conversationId Existing conversation ID resolved by the factory.
     */
    public function __construct(
        AiAgentInterface $agent,
        string $conversationId
    )
    {
        $this->agent = $agent;
        $this->workbench = $agent->getWorkbench();
        $this->conversationId = $conversationId;
    }

    /**
     * Returns the persisted conversation ID supplied by the factory.
     */
    public function getConversationId() : string
    {
        return $this->conversationId;
    }

    /**
     * Returns the agent participating in this conversation.
     */
    public function getAgent() : AiAgentInterface
    {
        return $this->agent;
    }

    /**
     * Returns the persisted conversation title.
     */
    public function getTitle() : string
    {
        return (string) ($this->getConversationData()['TITLE'] ?? '');
    }

    /**
     * Overwrites the persisted conversation title.
     */
    public function setTitle(string $title) : AiConversationInterface
    {
        $this->getConversationData();

        $conversation = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $conversation->getFilters()->addConditionFromString('UID', $this->conversationId, ComparatorDataType::EQUALS);
        $conversation->getFilters()->addConditionFromString(
            'USER',
            $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
            ComparatorDataType::EQUALS
        );
        $conversation->getColumns()->addMultiple(['TITLE']);
        $conversation->dataRead();

        if ($conversation->isEmpty()) {
            throw new AiConversationNotFoundError("Ai Conversation '{$this->conversationId}' not found");
        }

        $conversation->setCellValue('TITLE', 0, $title);
        $conversation->dataUpdate(false);
        if ($this->conversationData !== null) {
            $this->conversationData['TITLE'] = $title;
        }

        return $this;
    }

    /**
     * Returns the UID of the exact agent version assigned to this conversation.
     */
    public function getAgentVersionUID() : string
    {
        return $this->getConversationData()['AGENT_VERSION'];
    }

    /**
     * Loads and validates the persisted conversation data on first access.
     */
    protected function getConversationData() : array
    {
        if ($this->conversationData === null) {
            $conversation = DataSheetFactory::createFromObjectIdOrAlias(
                $this->workbench,
                'axenox.GenAI.AI_CONVERSATION'
            );
            $conversation->getColumns()->addMultiple([
                'TITLE',
                'AI_AGENT',
                'AI_AGENT_VERSION_NO',
                'AGENT_VERSION'
            ]);
            $conversation->getFilters()->addConditionFromString('UID', $this->conversationId, ComparatorDataType::EQUALS);
            $conversation->getFilters()->addConditionFromString(
                'USER',
                $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                ComparatorDataType::EQUALS
            );
            $conversation->setRowsLimit(1);
            $conversation->dataRead();

            if ($conversation->isEmpty()) {
                throw new AiConversationNotFoundError("Ai Conversation '{$this->conversationId}' not found");
            }

            $row = $conversation->getRow(0);
            $storedAgentUid = (string) $row['AI_AGENT'];
            $storedVersion = (string) $row['AI_AGENT_VERSION_NO'];
            $agentVersionUid = trim((string) $row['AGENT_VERSION']);
            if (strcasecmp($storedAgentUid, $this->agent->getUid()) !== 0 || $storedVersion !== $this->agent->getVersion()) {
                throw new AiConversationAgentVersionMismatchError(
                    $this->conversationId,
                    $storedAgentUid,
                    $storedVersion,
                    $this->agent->getUid(),
                    $this->agent->getVersion(),
                    $agentVersionUid !== '' ? $agentVersionUid : null
                );
            }
            if ($agentVersionUid === '') {
                throw new AiConversationAgentVersionMismatchError(
                    $this->conversationId,
                    $storedAgentUid,
                    $storedVersion,
                    $this->agent->getUid(),
                    $this->agent->getVersion()
                );
            }

            $row['AGENT_VERSION'] = $agentVersionUid;
            $this->conversationData = $row;
        }

        return $this->conversationData;
    }

    /**
     * Returns the next sequence number, loading it from persistence on first access.
     */
    public function getSequenceNumber() : int
    {
        if ($this->sequenceNumber === null) {
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
            $message->getColumns()->addFromExpression('SEQUENCE_NUMBER');
            $message->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId);
            $message->getSorters()->addFromString('SEQUENCE_NUMBER', 'DESC');
            $message->setRowsLimit(1);
            $message->dataRead();

            $this->sequenceNumber = $message->isEmpty()
                ? 0
                : (int) $message->getColumns()->getByExpression('SEQUENCE_NUMBER')->getValue(0) + 1;
        }

        return $this->sequenceNumber;
    }

    /**
     * Returns the current sequence number and advances the local counter.
     */
    protected function incrementSequenceNumber() : int
    {
        $sequenceNumber = $this->getSequenceNumber();
        $this->sequenceNumber++;
        return $sequenceNumber;
    }

    /**
     * Saves the system prompt message.
     *
     * Ignores the request if a system prompt has already been saved for this conversation.
     *
    * @param string $systemPrompt Rendered system prompt text.
    * @param array $data Normalized message metadata and payload.
     *
     * @return string Conversation ID used for the stored message.
     */
    public function saveSystemPrompt(string $systemPrompt, array $data = []) : string
    {
        // Return early if a system prompt already exists for this conversation
        if ($this->hasSystemPrompt()) {
            return $this->conversationId;
        }

        $transaction = $this->workbench->data()->startTransaction();
        try {
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');

            $message->addRow([
                'AI_CONVERSATION' => $this->getConversationId(),
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::SYSTEM,
                'MESSAGE' => $systemPrompt,
                'DATA' => $this->serializePayload($data),
                'MODEL' => $data['model'] ?? $this->agent->getConnection()->getModelName(),
                'SEQUENCE_NUMBER' => $this->incrementSequenceNumber()
            ]);

            $message->dataCreate(false, $transaction);
            $transaction->commit();
        } catch (\Throwable $e) {
            $this->workbench->getLogger()->logException($e);
            $transaction->rollback();
            throw $e;
        }

        return $this->conversationId;
    }

    /**
     * Checks whether a system prompt has already been saved for this conversation.
     *
     * @return bool True if at least one system message exists, false otherwise.
     */
    public function hasSystemPrompt() : bool
    {
        $systemPromptCount = count($this->getMessagesByType(AiMessageTypeDataType::SYSTEM));
        if ($systemPromptCount > 1) {
            throw new RuntimeException(
                "AI Conversation '{$this->conversationId}' contains multiple system prompts"
            );
        }

        return $systemPromptCount === 1;
    }

    /**
     * Returns the conversation's single persisted system prompt.
     */
    public function getSystemPrompt() : string
    {
        $systemPrompts = $this->getMessagesByType(AiMessageTypeDataType::SYSTEM);
        if (count($systemPrompts) !== 1) {
            throw new RuntimeException(
                "AI Conversation '{$this->conversationId}' must contain exactly one system prompt"
            );
        }

        return $systemPrompts[0];
    }

    /**
     * Saves the user prompt message.
     *
      * @param string $userPrompt User message text.
      * @param array $files Files attached to the message.
      * @param array $data Additional message information.
     *
     * @return string Conversation ID used for the stored message.
     */
     public function saveUserPrompt(string $userPrompt, array $files = [], array $data = []) : string
    {
        if ($this->getTitle() === '' && empty($this->getUserMessages())) {
            $this->setTitle(StringDataType::truncate($userPrompt, 50, true, true, true));
        }

        $transaction = $this->workbench->data()->startTransaction();

        try {
            $messageSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
            $messageSheet->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::USER,
                'MESSAGE' => $userPrompt,
                'DATA' => $this->serializePayload($data),
                'MODEL' => $this->agent->getConnection()->getModelName(),
                'SEQUENCE_NUMBER' => $this->incrementSequenceNumber()
            ]);
            $messageSheet->dataCreate(false, $transaction);
            $msgUID = $messageSheet->getUidColumn()->getValue(0);
            if (! empty($files)) {
                $filesSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE_FILE');
                $filesSheet->getFilters()->addConditionFromString('AI_MESSAGE', $msgUID);
                foreach ($files as $file) {
                    $filesSheet->addRow([
                        'PATHNAME_RELATIVE' => "data/axenox/GenAI/Conversations/{$messageSheet->getUidColumn()->getValue(0)}/{$file->getFileInfo()->getFilename()}",
                        'CONTENTS' => $file->read()
                    ]);
                }
                $filesSheet->dataCreate(false, $transaction);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
            throw $e;
        }

        return $this->conversationId;
    }

    /**
     * Saves an assistant tool-call request message.
     *
     * @param AiQueryInterface $query Query containing tool calls and token/cost metadata.
     */
    public function saveToolCallRequest(AiQueryInterface $query) : void
    {
        $transaction = $this->workbench->data()->startTransaction();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $toolCalls = $query->getToolCalls();
        $markdown = '**' . count($toolCalls) . "** tool calls:\n\n";

        foreach ($toolCalls as $i => $toolCall) {
            $markdown .= ($i + 1) . '. `' . StringDataType::truncate($toolCall->__toString(), 120, false, true, true, true) . "`\n";
        }

        foreach ($toolCalls as $i => $toolCall) {
            $no = $i + 1;
            $markdown .= "\n## {$no}. " . $toolCall->getToolName() . "()";
            $markdown .= "\n\n" . MarkdownDataType::escapeCodeBlock($toolCall->__toString());
        }

        try {
            $cost = $query->getCosts();
            $this->saveWarnings($query->getWarnings());

            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::TOOLCALLING,
                'MESSAGE' => $markdown,
                'DATA' => UxonObject::fromArray($query->getResponseMessage())->toJson(true),
                'MODEL' => $this->agent->getConnection()->getModelName(),
                'SEQUENCE_NUMBER' => $this->incrementSequenceNumber(),
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST' => $cost,
                'FINISH_REASON' => $query->getFinishReason()
            ]);

            $message->dataCreate(false, $transaction);
            $messageUid = $message->getUidColumn()->getValue(0);
            $transaction->commit();
            $this->saveToolCallRecords($toolCalls, $messageUid);
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * Saves the final assistant response message.
     *
        * @param AiQueryInterface $query Query containing completion metadata.
        * @param string $answer Resolved assistant answer to display.
        * @param array|null $fullJsonResponse Optional raw JSON response payload.
     */
        public function saveResponse(AiQueryInterface $query, string $answer, ?array $fullJsonResponse = null) : void
    {
        $transaction = $this->workbench->data()->startTransaction();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');

        try {
            $cost = $query->getCosts();
            $dataUxon = new UxonObject();

            if ($fullJsonResponse !== null) {
                $dataUxon->setProperty('fullJsonResponse', $fullJsonResponse);
            }
            $this->saveWarnings($query->getWarnings());

            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::ASSISTANT,
                'MESSAGE' => $answer,
                'MODEL' => $this->agent->getConnection()->getModelName(),
                'SEQUENCE_NUMBER' => $this->incrementSequenceNumber(),
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST' => $cost,
                'FINISH_REASON' => $query->getFinishReason(),
                'DATA' => $dataUxon->toJson(true)
            ]);

            $message->dataCreate(false, $transaction);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * Saves the tool execution response batch.
     *
     * @param AiQueryInterface $query Query that triggered tool execution.
     * @param AiToolCallResponse[] $responses
     * @return AiToolCallResponse[]|null
     */
    public function saveToolResponses(AiQueryInterface $query, array $responses) : ?array
    {
        $transaction = $this->workbench->data()->startTransaction();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $toolCalls = $query->getToolCalls();

        $markdown = '> **' . count($toolCalls) . "** tool calls:\n";
        foreach ($toolCalls as $i => $toolCall) {
            $markdown .= '> ' . ($i + 1) . '. `' . StringDataType::truncate($toolCall->__toString(), 120, false, true, true, true) . "`\n";
        }
        $markdown .= "\n";

        $no = 0;
        foreach ($responses as $response) {
            $no++;
            $markdown .= "\n## {$no}. {$response->getToolName()}()";
            $markdown .= "\n\n" . MarkdownDataType::escapeCodeBlock($toolCalls[$no - 1]?->__toString() ?? '< no response >');
            $markdown .= MarkdownDataType::makeHorizontalLine();
            $markdown .= "\n\n" . $response->getToolResult()->getValueAsMarkdown();
        }

        try {
            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::TOOL,
                'DATA' => UxonObject::fromArray($responses)->toJson(true),
                'MESSAGE' => $markdown,
                'MODEL' => $this->agent->getConnection()->getModelName(),
                'SEQUENCE_NUMBER' => $this->incrementSequenceNumber()
            ]);

            $message->dataCreate(false, $transaction);
            $transaction->commit();
            $this->saveToolCallResults($responses);
            return null;
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
            return $responses;
        }
    }

    /**
     * Serializes the public payload portion of normalized message data.
     */
    protected function serializePayload(array $data) : string
    {
        $payload = $data['payload'] ?? [];
        $dataUxon = $payload instanceof UxonObject
            ? $payload
            : UxonObject::fromArray($payload);

        return $dataUxon->toJson(true);
    }

    /**
     * Saves individual tool-call request records without affecting message persistence.
     *
     * @param array $toolCalls
     * @param string $messageUid
     * @return void
     */
    protected function saveToolCallRecords(array $toolCalls, string $messageUid) : void
    {
        try {
            $transaction = $this->workbench->data()->startTransaction();
            $toolCallSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_TOOL_CALL');
            foreach ($toolCalls as $index => $toolCall) {
                $toolCallSheet->addRow([
                    'AI_CONVERSATION' => $this->conversationId,
                    'AI_MESSAGE' => $messageUid,
                    'CALL_INDEX' => $index + 1,
                    'CALL_ID' => $toolCall->getCallId(),
                    'TOOL_NAME' => $toolCall->getToolName(),
                    'TOOL_ALIAS' => $this->agent->getTool($toolCall->getToolName())->getAliasWithNamespace(),
                    'CALL_DISPLAY' => $toolCall->__toString(),
                    'ARGUMENTS' => UxonObject::fromArray($toolCall->getArguments())->toJson(true)
                ]);
            }
            $toolCallSheet->dataCreate(false, $transaction);
            $transaction->commit();
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback();
            }
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * Saves tool-call results without affecting message persistence.
     *
     * @param AiToolCallResponse[] $responses
     * @return void
     */
    protected function saveToolCallResults(array $responses) : void
    {
        try {
            $transaction = $this->workbench->data()->startTransaction();
            foreach ($responses as $response) {
                $toolCallSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_TOOL_CALL');
                $toolCallSheet->getColumns()->addFromSystemAttributes();
                $toolCallSheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->conversationId);
                $toolCallSheet->getFilters()->addConditionFromString('CALL_ID', $response->getCallId());
                $toolCallSheet->dataRead();

                if ($toolCallSheet->countRows() === 1) {
                    $toolCallSheet->getColumns()->addMultiple(['RESULT', 'RESULT_LENGTH_CHARS', 'EXECUTION_TIME_MS', 'FAILED']);
                    $toolResult = $response->getToolResult();
                    $result = $toolResult->getValue();
                    if ($toolResult->isFailed() && ($exception = $toolResult->getExceptions()[0] ?? null) instanceof \Throwable) {
                        $result = $exception->getMessage();
                    }
                    $resultLengthChars = mb_strlen((string)$result, 'UTF-8');
                    $executionTimeMs = method_exists($toolResult, 'getDurationMs')
                        ? $toolResult->getDurationMs()
                        : null;
                    $toolCallSheet->setCellValue('RESULT', 0, $result);
                    $toolCallSheet->setCellValue('RESULT_LENGTH_CHARS', 0, $resultLengthChars);
                    $toolCallSheet->setCellValue('EXECUTION_TIME_MS', 0, $executionTimeMs);
                    $toolCallSheet->setCellValue('FAILED', 0, $toolResult->isFailed() ? 1 : 0);
                    $toolCallSheet->dataUpdate(false, $transaction);
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback();
            }
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * Splits tool exceptions by severity and saves them as warnings/errors.
     *
     * @param array $exceptions Exception payloads attached by tools.
     */
    public function saveExceptions(array $exceptions) : void
    {
        $errors = [];
        $warnings = [];

        foreach ($exceptions as $exception) {
            if ($exception instanceof ExceptionInterface) {
                if ($this->isWarningException($exception)) {
                    $warnings[] = $exception;
                } else {
                    $errors[] = $exception;
                }
            }
        }

        $this->saveWarnings($warnings);
        $this->saveErrorMessages($errors);
    }

    /**
     * Saves a fatal conversation error as an ERROR message.
     *
     * @param \Throwable $error Original error.
     * @param array $tools Tool metadata to include in payload.
     * @param array|null $responseJsonSchema Optional JSON schema metadata.
     * @return ExceptionInterface Normalized platform exception.
     */
    public function saveError(\Throwable $error, array $tools = [], ?array $responseJsonSchema = null) : ExceptionInterface
    {
        $transaction = $this->workbench->data()->startTransaction();
        $messageData = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');

        if (! $error instanceof ExceptionInterface) {
            $error = new RuntimeException('AI prompt failed. ' . $error->getMessage(), null, $error);
        }

        $markdown = '';
        $errorWidget = $error->createWidget(UiPageFactory::createEmpty($this->agent->getWorkbench()));
        foreach ($errorWidget->getTab(0)->getWidgets() as $widget) {
            if ($widget instanceof Markdown) {
                $markdown .= "\n" . $widget->getMarkdown() . "\n";
            }
        }

        $errorID = $error->getId();
        $errorData = [
            'class' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'ID' => $errorID
        ];
        if ($error instanceof AiPromptError) {
            $errorData['User Prompt'] = $error->getPrompt()->getUserPrompt();
        }
        $dataUxon = UxonObject::fromArray($errorData);
        $this->enrichUxonWithTools($dataUxon, $tools);
        $this->enrichUxonWithJsonSchema($dataUxon, $responseJsonSchema);

        try {
            $this->saveErrorFeedback($this->conversationId, $error->getMessage(), $transaction);
            $messageData->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::ERROR,
                'DATA' => $dataUxon->toJson(true),
                'MESSAGE' => $markdown,
                'MODEL' => $this->agent->getConnection()->getModelName(),
                'SEQUENCE_NUMBER' => $this->incrementSequenceNumber(),
                'ERROR_LOG_ID' => $errorID
            ]);
            $messageData->dataCreate(false, $transaction);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }

        return $error;
    }

    /**
     * Saves warning payloads as WARNING messages.
     *
     * @param array $warnings Warning payloads from connector/tools.
     */
    public function saveWarnings(array $warnings) : void
    {
        if (empty($warnings)) {
            return;
        }

        $transaction = $this->workbench->data()->startTransaction();
        $messageData = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $hasRows = false;

        try {
            foreach ($warnings as $warning) {
                if ($warning instanceof ExceptionInterface) {
                    $warningException = $warning;
                } elseif ($warning instanceof \Throwable) {
                    $warningException = new RuntimeException(
                        'Unrecognized payload while saving warning: ' . $warning->getMessage(),
                        null,
                        $warning
                    );
                } else {
                    $warningMessage = is_scalar($warning) || $warning === null
                        ? trim((string) $warning)
                        : trim(json_encode($warning, JSON_UNESCAPED_UNICODE) ?: '');
                    $warningException = new RuntimeException(
                        'Non-standard warning payload mapped during warning persistence: '
                        . ($warningMessage !== '' ? $warningMessage : gettype($warning))
                    );
                    $this->workbench->getLogger()->logException($warningException);
                }

                $warningText = trim($warningException->getMessage());
                if ($warningText === '') {
                    continue;
                }

                $hasRows = true;
                $row = [
                    'AI_CONVERSATION' => $this->conversationId,
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'ROLE' => AiMessageTypeDataType::WARNING,
                    'MESSAGE' => $warningText,
                    'MODEL' => $this->agent->getConnection()->getModelName(),
                    'SEQUENCE_NUMBER' => $this->incrementSequenceNumber()
                ];
                if ($warningException->getId() !== null && $warningException->getId() !== '') {
                    $row['ERROR_LOG_ID'] = $warningException->getId();
                }
                $messageData->addRow($row);
            }

            if ($hasRows) {
                $messageData->dataCreate(false, $transaction);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * Saves error payloads as ERROR messages.
     *
     * @param ExceptionInterface[] $errors
     */
    protected function saveErrorMessages(array $errors) : void
    {
        foreach ($errors as $error) {
            $this->saveError($error);
        }
    }

    /**
     * Writes automatic feedback text for an error case.
     *
     * @param string $conversationId Target conversation ID.
     * @param string $errorMessage Error text to include in feedback.
     * @param DataTransactionInterface|null $transaction Optional transaction.
     */
    protected function saveErrorFeedback(string $conversationId, string $errorMessage, ?DataTransactionInterface $transaction = null) : void
    {
        $this->saveFeedback(
            $conversationId,
            "Error: " . StringDataType::truncate($errorMessage, 500, true, false, true),
            1,
            $transaction
        );
    }

    /**
     * Saves or appends feedback and optional default rating on a conversation.
     *
     * @param string $conversationId Target conversation ID.
     * @param string $feedback Feedback text to append.
     * @param int|null $defaultRating Optional default rating when missing.
     * @param DataTransactionInterface|null $transaction Optional transaction.
     */
    protected function saveFeedback(string $conversationId, string $feedback, ?int $defaultRating = null, ?DataTransactionInterface $transaction = null) : void
    {
        $conversationData = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $conversationData->getFilters()->addConditionFromAttribute(
            $conversationData->getMetaObject()->getUidAttribute(),
            $conversationId,
            ComparatorDataType::EQUALS
        );
        $conversationData->getColumns()->addFromAttributeGroup($conversationData->getMetaObject()->getAttributes());
        $conversationData->dataRead();

        if ($conversationData->isEmpty()) {
            throw new AiConversationNotFoundError("Ai Conversation '$conversationId' not found");
        }

        $existingRating = $conversationData->getCellValue('RATING', 0);
        $existingFeedback = $conversationData->getCellValue('RATING_FEEDBACK', 0);

        if ($defaultRating !== null && ($existingRating === null || $existingRating === '')) {
            $conversationData->setCellValue('RATING', 0, $defaultRating);
        }

        if ($existingFeedback === null || $existingFeedback === '') {
            $conversationData->setCellValue('RATING_FEEDBACK', 0, $feedback);
        } else {
            $conversationData->setCellValue('RATING_FEEDBACK', 0, rtrim($existingFeedback) . "\n\n" . $feedback);
        }

        $conversationData->dataUpdate(false, $transaction);
    }

    /**
     * Determines whether an exception should be handled as warning.
     *
     * @param ExceptionInterface $exception Exception to classify.
     */
    protected function isWarningException(ExceptionInterface $exception) : bool
    {
        try {
            $levelCmp = LogLevelDataType::compareLogLevels($exception->getLogLevel(), LoggerInterface::WARNING);
            return $levelCmp <= 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Enriches a UXON payload with serialized tool definitions.
     *
     * @param AiToolInterface[] $tools
     * @param UxonObject|null $uxon Existing payload object.
     */
    protected function enrichUxonWithTools(?UxonObject $uxon, array $tools) : UxonObject
    {
        if ($uxon === null) {
            $dataUxon = new UxonObject([
                'tools' => []
            ]);
        } else {
            $dataUxon = $uxon;
            $dataUxon->setProperty('tools', []);
        }

        foreach ($tools as $tool) {
            $dataUxon->appendToProperty('tools', $tool->exportUxonObject());
        }

        return $dataUxon;
    }

    /**
     * Enriches a UXON payload with response JSON schema metadata.
     *
     * @param UxonObject|null $uxon Existing payload object.
     * @param array|null $responseJsonSchema Optional schema payload.
     */
    protected function enrichUxonWithJsonSchema(?UxonObject $uxon, ?array $responseJsonSchema) : UxonObject
    {
        if ($uxon === null) {
            $dataUxon = new UxonObject();
        } else {
            $dataUxon = $uxon;
        }

        if ($responseJsonSchema !== null) {
            $dataUxon->setProperty('responseJsonSchema', $responseJsonSchema);
        }
        return $dataUxon;
    }

    /**
     * Retrieves messages of a specific type from the conversation.
     *
     * @param string $messageType The message type filter (e.g., SYSTEM, USER, ASSISTANT, etc.).
     *
     * @return array Array of message strings sorted by sequence number.
     */
    protected function getMessagesByType(string $messageType) : array
    {
        $messageSheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $messageSheet->getColumns()->addFromExpression('MESSAGE');
        $messageSheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->getConversationId());
        $messageSheet->getFilters()->addConditionFromString('ROLE', $messageType);
        $messageSheet->getSorters()->addFromString('SEQUENCE_NUMBER', 'ASC');
        $messageSheet->dataRead();

        $messages = [];
        foreach ($messageSheet->getRows() as $row) {
            $messages[] = isset($row['MESSAGE']) ? (string) $row['MESSAGE'] : '';
        }

        return $messages;
    }

    /**
     * Retrieves all user messages from the conversation.
     *
     * @return array Array of user message strings.
     */
    public function getUserMessages() : array
    {
        return $this->getMessagesByType(AiMessageTypeDataType::USER);
    }

    /**
     * Retrieves all assistant messages from the conversation.
     *
     * @return array Array of assistant message strings.
     */
    public function getAssistantMessages() : array
    {
        return $this->getMessagesByType(AiMessageTypeDataType::ASSISTANT);
    }

    /**
     * Retrieves all tool messages from the conversation.
     *
     * @return array Array of tool message strings.
     */
    public function getToolMessages() : array
    {
        return $this->getMessagesByType(AiMessageTypeDataType::TOOL);
    }

    /**
     * Retrieves all tool calling messages from the conversation.
     *
     * @return array Array of tool calling message strings.
     */
    public function getToolCallingMessages() : array
    {
        return $this->getMessagesByType(AiMessageTypeDataType::TOOLCALLING);
    }

    /**
     * Retrieves all warning messages from the conversation.
     *
     * @return array Array of warning message strings.
     */
    public function getWarningMessages() : array
    {
        return $this->getMessagesByType(AiMessageTypeDataType::WARNING);
    }

    /**
     * Retrieves all error messages from the conversation.
     *
     * @return array Array of error message strings.
     */
    public function getErrorMessages() : array
    {
        return $this->getMessagesByType(AiMessageTypeDataType::ERROR);
    }
}