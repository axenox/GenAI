<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\AI\Agents\GenericAssistant;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
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
 * The constructor initializes the conversation context and ensures a valid
 * conversation ID exists before any save operation is executed.
 */
class AiConversation
{
    private GenericAssistant $assistant;

    private AiPromptInterface $prompt;

    private WorkbenchInterface $workbench;

    private ?string $conversationId = null;

    private int $sequenceNumber = 0;

    /**
     * @param GenericAssistant $assistant Owning assistant instance.
     * @param AiPromptInterface $prompt Prompt currently processed.
     * @param string|null $conversationId Optional existing conversation ID.
     */
    public function __construct(GenericAssistant $assistant, AiPromptInterface $prompt, ?string $conversationId = null, ?AiQueryInterface $query = null)
    {
        $this->assistant = $assistant;
        $this->prompt = $prompt;
        $this->workbench = $assistant->getWorkbench();

        $this->conversationId = $conversationId ?? $this->prompt->getConversationUid();
        if ($this->conversationId !== null) {
            $this->prompt->setConversationUid($this->conversationId);
        } else {
            $this->createConversation($query);
        }
    }

    /**
     * Returns a guaranteed conversation ID.
     *
     * If the internal ID is unexpectedly missing, a new conversation is
     * created and its ID is returned.
     */
    public function getConversationId() : string
    {
        if ($this->conversationId === null|| $this->conversationId === '') {
            return $this->createConversation();
        }

        return $this->conversationId;
    }

    /**
     * Creates a new conversation row and stores the generated UID.
     *
     * If a conversation ID is already set, it is returned unchanged.
     *
     * @param AiQueryInterface|null $query Optional query used for model/title metadata.
     */
    protected function createConversation(?AiQueryInterface $query = null) : string
    {
        if ($this->conversationId !== null) {
            return $this->conversationId;
        }

        $transaction = $this->workbench->data()->startTransaction();
        $conversation = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');

        $modelName = null;
        $connectionId = null;

        try {
            $connection = $this->assistant->getConnection();
            $connectionId = $connection->getId();

            if ($query !== null) {
                $modelName = $connection->getModelName($query);
            }
        } catch (\Throwable $e) {
            // TODO possible Errorhandling
        }

        $title = $query !== null
            ? $this->assistant->getTitle($query)
            : 'Unknown topic';

        $dataUxon = $this->prompt->getInputData()->exportUxonObject();

        $row = [
            'AI_AGENT' => $this->assistant->getUid(),
            'AI_AGENT_VERSION_NO' => $this->assistant->getVersion(),
            'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
            'TITLE' => $title,
            'DATA' => $dataUxon->toJson(),
            'DEVMODE' => $this->assistant->getDevmode() ? 1 : 0,
            'MODEL' => $modelName,
            'CONNECTION' => $connectionId
        ];
        if ($this->prompt->hasMetaObject()) {
            $row['META_OBJECT'] = $this->prompt->getMetaObject()->getId();
        }
        if ($this->prompt->isTriggeredOnPage()) {
            $row['PAGE'] = $this->prompt->getPageTriggeredOn()->getUid();
        }
        $conversation->addRow($row);
        $conversation->dataCreate(false, $transaction);
        $this->conversationId = $conversation->getUidColumn()->getValue(0);
        $this->prompt->setConversationUid($this->conversationId);
        $transaction->commit();

        return $this->conversationId;
    }

    /**
     * Saves the system prompt message.
     *
     * @param AiQueryInterface $query Query used to initialize message sequence number.
     * @param string $systemPrompt Rendered system prompt text.
     * @param AiToolInterface[] $tools Tool definitions to include in message metadata.
     * @param array|null $responseJsonSchema Optional JSON response schema metadata.
     *
     * @return string Conversation ID used for the stored message.
     */
    public function saveSystemPrompt(AiQueryInterface $query, string $systemPrompt, array $tools = [], ?array $responseJsonSchema = null) : string
    {
        $transaction = $this->workbench->data()->startTransaction();
        $this->sequenceNumber = $query->getSequenceNumber();

        try {
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');

            $dataUxon = new UxonObject();
            $this->enrichUxonWithTools($dataUxon, $tools);
            $this->enrichUxonWithJsonSchema($dataUxon, $responseJsonSchema);

            $concepts = [];
            if (!empty($concepts)) {
                $dataUxon->setProperty('concepts', new UxonObject($concepts));
            }

            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::SYSTEM,
                'MESSAGE' => $systemPrompt,
                'DATA' => $dataUxon->toJson(true),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++
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
     * Saves the user prompt message.
     *
     * @param AiQueryInterface $query Query containing the current user prompt.
     *
     * @return string Conversation ID used for the stored message.
     */
    public function saveUserPrompt(AiQueryInterface $query) : string
    {
        $transaction = $this->workbench->data()->startTransaction();

        try {
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');

            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::USER,
                'MESSAGE' => $query->getUserPrompt(),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++
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
            $this->saveWarning($query->getWarnings());

            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::TOOLCALLING,
                'MESSAGE' => $markdown,
                'DATA' => UxonObject::fromArray($query->getResponseMessage())->toJson(true),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++,
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST' => $cost,
                'FINISH_REASON' => $query->getFinishReason()
            ]);

            $message->dataCreate(false, $transaction);
            $transaction->commit();
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
            $this->saveWarning($query->getWarnings());

            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::ASSISTANT,
                'MESSAGE' => $answer,
                'SEQUENCE_NUMBER' => $this->sequenceNumber,
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
            $markdown .= "\n\n" . MarkdownDataType::escapeCodeBlock($toolCalls[$no - 1]?->__toString());
            $markdown .= MarkdownDataType::makeHorizontalLine();
            $markdown .= "\n\n" . MarkdownDataType::convertFrontMatterToMarkdown($response->getToolResult()->getValueAsMarkdown());
        }

        try {
            $message->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::TOOL,
                'DATA' => UxonObject::fromArray($responses)->toJson(true),
                'MESSAGE' => $markdown,
                'SEQUENCE_NUMBER' => $this->sequenceNumber++
            ]);

            $message->dataCreate(false, $transaction);
            $transaction->commit();
            return null;
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
            return $responses;
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

        $this->saveWarning($warnings);
        $this->saveErrorMessages($errors);
    }

    /**
     * Saves a fatal conversation error as an ERROR message.
     *
     * @param \Throwable $error Original error.
     * @param string|null $systemPrompt Optional system prompt snapshot.
     * @param array $tools Tool metadata to include in payload.
     * @param array|null $responseJsonSchema Optional JSON schema metadata.
     *
     * @return ExceptionInterface Normalized platform exception.
     */
    public function saveError(\Throwable $error, ?string $systemPrompt = null, array $tools = [], ?array $responseJsonSchema = null) : ExceptionInterface
    {
        $transaction = $this->workbench->data()->startTransaction();
        $messageData = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');

        if (!$error instanceof ExceptionInterface) {
            $error = new AiPromptError($this->assistant, $this->prompt, 'AI prompt failed. ' . $error->getMessage(), null, $error);
        }

        $markdown = '';
        $errorWidget = $error->createWidget(UiPageFactory::createEmpty($this->assistant->getWorkbench()));
        foreach ($errorWidget->getTab(0)->getWidgets() as $widget) {
            if ($widget instanceof Markdown) {
                $markdown .= "\n" . $widget->getMarkdown() . "\n";
            }
        }

        $errorID = $error->getId();

        $errorPayload = [
            'class' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
        $dataUxon = UxonObject::fromArray($errorPayload);
        $dataUxon->setProperty('ID', $errorID);

        $this->enrichUxonWithTools($dataUxon, $tools);
        $this->enrichUxonWithJsonSchema($dataUxon, $responseJsonSchema);
        $dataUxon->setProperty('User Prompt', $this->prompt->getUserPrompt());
        try {
            $dataUxon->setProperty('System Prompt', $systemPrompt);
        } catch (\Throwable $e) {
            $this->workbench->getLogger()->logException(
                new AiPromptError($this->assistant, $this->prompt, 'Failed to log AI system prompt. ' . $e->getMessage(), null, $e)
            );
            $dataUxon->setProperty('System Prompt causes an error', true);
        }

        try {
            $this->saveErrorFeedback($this->conversationId, $error->getMessage(), $transaction);

            $messageData->addRow([
                'AI_CONVERSATION' => $this->conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::ERROR,
                'DATA' => $dataUxon->toJson(true),
                'MESSAGE' => $markdown,
                'SEQUENCE_NUMBER' => $this->sequenceNumber++,
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
    public function saveWarning(array $warnings) : void
    {
        if (empty($warnings)) {
            return;
        }

        $transaction = $this->workbench->data()->startTransaction();
        $messageData = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $hasRows = false;

        try {
            foreach ($warnings as $warning) {
                $warningException = null;

                if ($warning instanceof ExceptionInterface) {
                    $warningException = $warning;
                } else {
                    if ($warning instanceof \Throwable) {
                        $warningException = new AiPromptError(
                            $this->assistant,
                            $this->prompt,
                            'Unrecognized payload while saving warning: ' . $warning->getMessage(),
                            null,
                            $warning
                        );
                    } else {
                        $warningMessage = is_scalar($warning) || $warning === null
                            ? trim((string) $warning)
                            : trim(json_encode($warning, JSON_UNESCAPED_UNICODE) ?: '');

                        if ($warningMessage === '') {
                            $warningMessage = gettype($warning);
                        }

                        $warningException = new AiPromptError(
                            $this->assistant,
                            $this->prompt,
                            'Non-standard warning payload mapped during warning persistence: ' . $warningMessage
                        );

                        $this->workbench->getLogger()->logException($warningException);
                    }
                }

                $warningText = trim($warningException->getMessage());
                $warningLogId = $warningException->getId();

                if ($warningText === '') {
                    continue;
                }

                $hasRows = true;

                $row = [
                    'AI_CONVERSATION' => $this->conversationId,
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'ROLE' => AiMessageTypeDataType::WARNING,
                    'MESSAGE' => $warningText,
                    'SEQUENCE_NUMBER' => $this->sequenceNumber++
                ];

                if ($warningLogId !== null && $warningLogId !== '') {
                    $row['ERROR_LOG_ID'] = $warningLogId;
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
     * @param array $errors Error payloads from connector/tools.
     */
    protected function saveErrorMessages(array $errors) : void
    {
        if (empty($errors)) {
            return;
        }

        $transaction = $this->workbench->data()->startTransaction();
        $messageData = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $hasRows = false;

        try {
            foreach ($errors as $error) {
                $errorException = null;

                if ($error instanceof ExceptionInterface) {
                    $errorException = $error;
                } else {
                    if ($error instanceof \Throwable) {
                        $errorException = new AiPromptError(
                            $this->assistant,
                            $this->prompt,
                            'Unrecognized payload while saving error: ' . $error->getMessage(),
                            null,
                            $error
                        );
                    } else {
                        $errorMessage = is_scalar($error) || $error === null
                            ? trim((string) $error)
                            : trim(json_encode($error, JSON_UNESCAPED_UNICODE) ?: '');

                        if ($errorMessage === '') {
                            $errorMessage = gettype($error);
                        }

                        $errorException = new AiPromptError(
                            $this->assistant,
                            $this->prompt,
                            'Non-standard error payload mapped during error persistence: ' . $errorMessage
                        );

                        $this->workbench->getLogger()->logException($errorException);
                    }
                }

                $errorText = trim($errorException->getMessage());
                $errorLogId = $errorException->getId();

                if ($errorText === '') {
                    continue;
                }

                $hasRows = true;

                $row = [
                    'AI_CONVERSATION' => $this->conversationId,
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'ROLE' => AiMessageTypeDataType::ERROR,
                    'MESSAGE' => $errorText,
                    'SEQUENCE_NUMBER' => $this->sequenceNumber++
                ];

                if ($errorLogId !== null && $errorLogId !== '') {
                    $row['ERROR_LOG_ID'] = $errorLogId;
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
            "Auto generated error message:\n" . substr($errorMessage, 0, 500),
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
     * Verifies that a conversation exists in persistence.
     *
     * @param string $conversationId Conversation ID to validate.
     */
    protected function assertConversationExists(string $conversationId) : void
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        $ds->getFilters()->addConditionFromAttribute($ds->getMetaObject()->getUidAttribute(), $conversationId, ComparatorDataType::EQUALS);
        $ds->getColumns()->addFromAttributeGroup($ds->getMetaObject()->getAttributes());
        $ds->dataRead();

        if ($ds->isEmpty()) {
            throw new AiConversationNotFoundError("Ai Conversation '$conversationId' not found");
        }
    }
}