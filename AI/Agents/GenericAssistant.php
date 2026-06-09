<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiToolCallResponse;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiAgentNotFoundError;
use axenox\GenAI\Exceptions\AiAgentRuntimeError;
use axenox\GenAI\Exceptions\AiConceptRenderingError;
use axenox\GenAI\Exceptions\AiConnectionNotFoundError;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiConceptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Uxon\AiAgentUxonSchema;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\LogLevelDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\AppPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;
use exface\Core\Widgets\DebugMessage;
use exface\Core\Widgets\Markdown;

/**
 * Generic chat assistant with configurable system prompt
 * 
 * ## Examples
 * 
 * ```
 * {
 *   "system_prompt": "
 *      You are a helpful assistant, who will answer questions about the structure of the following database. 
 *      Here is the DB schema in DBML: \n\n[#metamodel_dbml#]
 *      Answer using the following locale \"[#=User('LOCALE')#]\"
 *   ",
 *   "system_concepts": {
 *     "metamodel_bmdb": {
 *       "class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept",
 *       "object_filters": {
 *         "operator": "AND",
 *         "conditions": [
 *           {"expression": "APP__ALIAS", "comparator": "==", "value": "exface.Core"}
 *         ]
 *       }
 *     }
 *   }
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 */
class GenericAssistant implements AiAgentInterface
{
    use ICanBeConvertedToUxonTrait;

    use AliasTrait;

    private $workbench = null;

    private $systemPrompt = null;
    
    private $sampleSystemPrompt = null;

    private $systemPromptRendered = null;

    private $conceptConfig = [];

    private $dataConnectionAlias = null;

    private $dataConnection = null;

    private $name = null;

    private $selector = null;

    private $agentDataSheet = null;

    private $versionDataSheet = null;

    private $versionRow = null;

    private $responseJsonSchema = null;

    private $devMode = null;

    private $responseAnswerPath = null;

    private $responseTitlePath = null;

    private ?array $tools = null;
    private ?array $toolsUxon = null;

    private $sequenceNumber = null;

    private $maxNumberOfCalls = 5;

    /** @var AiToolCallResponse[] */
    private array $toolCalls = [];

    private $promptSuggestions = [];

    /**
     * 
     * @param \axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface $selector
     * @param \exface\Core\CommonLogic\UxonObject|null $uxon
     */
    public function __construct(AiAgentSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->workbench = $selector->getWorkbench();
        $this->selector = $selector;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }


    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        
        $userPromt = $prompt->getUserPrompt();
        try {
            $systemPrompt = $this->getSystemPrompt($prompt);
            $this->setInstructions($systemPrompt );
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to render AI prompt. ' . $e->getMessage(), null, $e);
            throw $this->saveConversationError($prompt,$e);
            /* TODO handle different errors differently
            $this->workbench->getLogger()->logException($e);
            return $this->createResponseUnavailable('Error contacting the assistant', $prompt, $e);
            */
        }
        
        $query = new OpenAiApiDataQuery($this->workbench);
        $query->setSystemPrompt($this->systemPrompt);
        $query->appendMessage($userPromt);
        if (null !== $val = $prompt->getConversationUid()) 
            $query->setConversationUid($val);

        if($this->hasJsonSchema())
            $query->setResponseJsonSchema($this->getResponseJsonSchema());

        foreach ($this->getTools() as $tool) {
            $query->addTool($tool);
        }
        
        $query->setFiles($prompt->getFiles());

        try {
            $conversationId = $this->saveConversation($prompt, $query);
            $prompt->setConversationUid($conversationId);
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to save AI conversation. ' . $e->getMessage(), null, $e);
            throw $this->saveConversationError($prompt, $e);
        }

        try {
            $performedQuery = $this->getConnection()->query($query);
        } catch (\Throwable $e){
            $e = new AiPromptError($this, $prompt, 'Failed to query LLM. ' . $e->getMessage(), null, $e);
            throw $this->saveConversationError($prompt,$e);
        }
        
        try {
            $performedQuery = $this->handleToolCalls($prompt, $performedQuery);
        } catch (\Throwable $e){
            if (! $e instanceof AiToolRuntimeError) {
                $e = new AiPromptError($this, $prompt, 'Failed to call AI tools. ' . $e->getMessage(), null, $e);
            }
            throw $this->saveConversationError($prompt,$e);
        }
        try {
            $this->saveConversationResponse($prompt, $performedQuery);
            return $this->parseDataQueryResponse($prompt, $performedQuery, $conversationId);
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to process AI response. ' . $e->getMessage(), null, $e);
            throw $this->saveConversationError($prompt, $e);
        }
    }
    
    protected function handleToolCalls(AiPromptInterface $prompt, AiQueryInterface $performedQuery) : AiQueryInterface
    {
        $numberOfCallResponses = 0;
        // Check if the LLM has put some tool calls in its response
        while ($performedQuery->hasToolCalls()) {
            $numberOfCallResponses++;
            $this->saveConversationToolCallRequest($prompt, $performedQuery);

            if ($numberOfCallResponses > $this->maxNumberOfCalls) {
                // Add an AiQueryError that will accept the $performedQuery too, so that we see the actual
                // HTTP messages in the logs
                throw new AiPromptError($this, $prompt, 'Too many recursive tool call responses from LLM: ' . $numberOfCallResponses . ' one after another!');
            }

            $requestedCalls = $performedQuery->getToolCalls();
            $existingCall = false;

            foreach($requestedCalls as $call){
                $resultOfTool = null;
                $tool = $this->getTool($call->getToolName());
                $args = array_values($call->getArguments());
                if ($this->maxNumberOfCalls >= $numberOfCallResponses) {
                    try {
                        $resultOfTool = $tool->invoke($this, $prompt, $args);
                        $exceptions = $resultOfTool->getExceptions();
                    } catch (\Throwable $e) {
                        if (! $e instanceof AiToolCriticalError) {
                            $e = new AiToolCriticalError($tool, $prompt, 'Unexpected error in AI tool. ' . $e->getMessage(), null, $e);
                        }
                        $exceptions = [$e];
                    }
                    foreach ($exceptions as $e) {
                        $this->getWorkbench()->getLogger()->logException($e);
                    }
                    $this->saveConversationExceptions($prompt, $exceptions);
                    
                    // On critical errors, we should tell the LLM not to use this tool anymore. It will either tell the
                    // user or continue with other tools.
                    if ($resultOfTool && $resultOfTool->isFailed()) {
                        // TODO should we give more error details to the LLM
                        $resultOfTool = new AiToolResultString($tool, $args, "ERROR: Tool execution failed. It seems, this tool is broken.");
                    }
                    
                } else {
                    $resultOfTool = new AiToolResultString($tool, $args, "ERROR: Maximum number of tool calls ({$numberOfCallResponses}) have been reached.");
                    // TODO is this actually an error? Should we log an exception here?
                } 

                //to prevent duplication on calls
                $callId = $call->getCallId();

                $toolCallResponses[$callId] = new AiToolCallResponse(
                    $call->getToolName(),
                    $callId,
                    $call->getArguments(),
                    $resultOfTool
                );

                $this->toolCalls[] = $toolCallResponses[$callId];

                $performedQuery->appendToolMessages($existingCall, $resultOfTool, $callId, $performedQuery->getResponseMessage());
                $existingCall = true;
            }
            $toolCallResponses = $this->saveConversationToolResponses($prompt, $performedQuery, $toolCallResponses);
            // $toolCallResponses = null;
            $performedQuery = $this->getConnection()->query($performedQuery);
            //$query->clearPreviousToolCalls();
        }
        return $performedQuery;
    }

    /**
     * creates a new conversation and return conversationId
     * 
     * @return string conversationId
     */
    protected function createConversation(AiPromptInterface $prompt, ?AiQueryInterface $query): string
    {
        
        $conversationId = $prompt->getConversationUid();

        if($conversationId !== null) {
            return $conversationId;
        }
        
        $transaction = $this->workbench->data()->startTransaction();
        $conversation = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
        
        $modelName    = null;
        $connectionId = null;

        try {
            $connection   = $this->getConnection();
            $connectionId = $connection->getId();

            if ($query !== null) {
                $modelName = $connection->getModelName($query);
            }
        } catch (\Throwable $e) {
            // TODO possible Errorhandling
        }

        $title = $query !== null
            ? $this->getTitle($query)
            : 'Standard generated title';
        
        $dataUxon = $prompt->getInputData()->exportUxonObject();

        $row = [
            'AI_AGENT' => $this->getUid(),
            'AI_AGENT_VERSION_NO' => $this->getVersion(),
            'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
            'TITLE' => $title,
            'DATA' => $dataUxon->toJson(),
            'DEVMODE' => $this->getDevmode() ? 1 : 0,
            'MODEL' => $modelName,
            'CONNECTION' => $connectionId
        ];
        if ($prompt->hasMetaObject()) {
            $row['META_OBJECT'] = $prompt->getMetaObject()->getId();
        }
        if ($prompt->isTriggeredOnPage()) {
            $row['PAGE'] = $prompt->getPageTriggeredOn()->getUid();
        }
        $conversation->addRow($row);
        $conversation->dataCreate(false,$transaction);
        $conversationId = $conversation->getUidColumn()->getValue(0);
        $transaction->commit();
        
        // Save the conversation id in the prompt, so it visible to all other logic using the prompt (e.g.
        // logging, exception handling, etc.=
        $prompt->setConversationUid($conversationId);
        
        return $conversationId;
    }
    
    /**
     * Saves the first system message and the user messages and returns conversationId
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @throws \axenox\GenAI\Exceptions\AiConversationNotFoundError
     * @return string
     */
    protected function saveConversation(AiPromptInterface $prompt, AiQueryInterface $query) : string
    {
        $transaction = $this->workbench->data()->startTransaction();
        $this->sequenceNumber = $query->getSequenceNumber();
        try {
            $conversationId = $prompt->getConversationUid();
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
            if($conversationId === null) {

            $conversationId = $this->createConversation($prompt, $query);    
                
            // create dataUxon for the message
            // JSON structure:
            // {
            //   "tools": [ toolUxon, ... ],
            //   "concepts": {
            //     "placeholderName": conceptUxon,
            //     ...
            //   }
            // }
            $dataUxon = new UxonObject();
            $this->enrichUxonWithTools( $dataUxon);
            $this->enrichUxonWithJsonSchema($dataUxon);

            // collect concepts mapped by their placeholder
            $concepts = [];
            // TODO this causes exceptions if placeholders require input data because the rendere is not created
            // here correctly - see getSystemPrompt()
            /*
            foreach($this->getConcepts($prompt, new BracketHashStringTemplateRenderer($this->workbench)) as $concept) {
                $concepts[$concept->getPlaceholder()] = $concept->exportUxonObject()->toArray();
            }*/
            // add concepts section only if at least one concept exists
            if(!empty($concepts)) {
                $dataUxon->setProperty('concepts', new UxonObject($concepts));
            }    
                
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::SYSTEM,
                'MESSAGE'=> $this->systemPrompt,
                'DATA' => $dataUxon->toJson(true),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++
            ]);
            }
            else {
                $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
                $ds->getFilters()->addConditionFromAttribute($ds->getMetaObject()->getUidAttribute(), $conversationId, ComparatorDataType::EQUALS);
                $ds->getColumns()->addFromAttributeGroup($ds->getMetaObject()->getAttributes());
                $ds->dataRead();
                if($ds->isEmpty()){
                    throw new AiConversationNotFoundError("Ai Conversation '$conversationId' not found");
                }
            }
            
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::USER,
                'MESSAGE'=> $query->getUserPrompt(),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++
            ]);

            $message->dataCreate(false, $transaction);

            $transaction->commit();
        } catch(\Throwable $e){
            $this->workbench->getLogger()->logException($e);
            $transaction->rollback();
            throw $e;
        }
        return $conversationId;
    }

    /**
     * Saves the Tool Calling request of AI 
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return void
     */
    protected function saveConversationToolCallRequest(AiPromptInterface $prompt, AiQueryInterface $query) 
    {
        $transaction = $this->workbench->data()->startTransaction();
        $conversationId = $prompt->getConversationUid();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $toolCalls = $query->getToolCalls();
        $markdown = '**' . count($toolCalls) . "** tool calls:\n\n";
        // Compact summary
        foreach ($toolCalls as $i => $toolCall) {
            $markdown .= ($i + 1 ) . '. `' . StringDataType::truncate($toolCall->__toString(), 120, false, true, true, true) . "`\n";
        }
        // Full tool calls
        foreach ($toolCalls as $i => $toolCall) {
            $no = $i + 1;
            $markdown .= "\n## {$no}. " . $toolCall->getToolName() . "()";
            $markdown .= "\n\n" . MarkdownDataType::escapeCodeBlock($toolCall->__toString());
        }
        try {
            
            $cost = $query->getCosts();
            $this->saveConversationWarning($prompt, $query->getWarnings());
            
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::TOOLCALLING,
                'MESSAGE'=> $markdown,
                'DATA' => UxonObject::fromArray($query->getResponseMessage())->toJson(true),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++,
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST' => $cost,
                'FINISH_REASON' => $query->getFinishReason()
            ]);         

            $message->dataCreate(false, $transaction);
            $transaction->commit();

        } catch(\Throwable $e){
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * Saves the response of AI to the user
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return void
     */
    protected function saveConversationResponse(AiPromptInterface $prompt, AiQueryInterface $query) 
    {
        $transaction = $this->workbench->data()->startTransaction();
        $conversationId = $prompt->getConversationUid();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        try {

            $cost = $query->getCosts();

            $dataUxon = new UxonObject();
            
            if($this->hasJsonSchema()){
                $dataUxon->setProperty("fullJsonResponse",$query->getAnswerJson() );
            }
            $this->saveConversationWarning($prompt, $query->getWarnings());
            
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::ASSISTANT,
                'MESSAGE'=> $this->getAnswer($query),
                // TODO save the JSON data here if we are in JSON mode. Can we detect JSON mode in the answer?
                // 'DATA' => $query->getAnswerJson(),
                'SEQUENCE_NUMBER' => $this->sequenceNumber,
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST' => $cost,
                'FINISH_REASON' => $query->getFinishReason(),
                'DATA' => $dataUxon->toJson(true)
            ]);            

            $message->dataCreate(false, $transaction);
            $transaction->commit();

        } catch(\Throwable $e){
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
    }

    /**
     * saves the responses of the requested tools 
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @param AiToolCallResponse[] $responses
     * @return void
     */
    protected function saveConversationToolResponses(AiPromptInterface $prompt, AiQueryInterface $query, array $responses) : ?array 
    {
        $transaction = $this->workbench->data()->startTransaction();
        $conversationId = $prompt->getConversationUid();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        $toolCalls = $query->getToolCalls();
        
        // Summary of tool calls
        $markdown = '> **' . count($toolCalls) . "** tool calls:\n";
        foreach ($toolCalls as $i => $toolCall) {
            $markdown .= '> ' . ($i + 1) . '. `' . StringDataType::truncate($toolCall->__toString(), 120, false, true, true, true) . "`\n";
        }
        // Extra line break to make sure the first line of the response is not rendered as part of the block quote
        $markdown .= "\n";
        
        // Tool responses
        // NOTE: $toolCalls have call-IDs as keys
        $no = 0;
        foreach ($responses as $response) {
            $no++;
            $markdown .= "\n## {$no}. {$response->getToolName()}()";
            $markdown .= "\n\n" . MarkdownDataType::escapeCodeBlock($toolCalls[$no-1]?->__toString());
            $markdown .= MarkdownDataType::makeHorizontalLine();
            $markdown .= "\n\n" . $response->getToolResult()->getValueAsMarkdown();
        }
        
        // Save the message with the tool responses
        try {
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::TOOL,
                'DATA' => UxonObject::fromArray($responses)->toJson(true),
                'MESSAGE' => $markdown,
                'SEQUENCE_NUMBER' => $this->sequenceNumber++
            ]);

            $message->dataCreate(false, $transaction);
            $transaction->commit();
            return null;
        } catch(\Throwable $e){
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
            return $responses;
        }
    }

    /**
     * Saves all exceptions attached to tool responses.
     *
     * Warning-like exceptions are saved as warning messages, all others as errors.
     *
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param AiToolCallResponse[] $responses
     * @return void
     */
    protected function saveConversationExceptions(AiPromptInterface $prompt, array $exceptions) : void
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

        $this->saveConversationWarning($prompt, $warnings);
        $this->saveConversationErrorMessages($prompt, $errors);
    }

    /**
     * Detects warning-like exceptions based on their configured PSR-3 log level.
     */
    protected function isWarningException(ExceptionInterface $exception) : bool
    {
        try {
            $levelCmp = LogLevelDataType::compareLogLevels($exception->getLogLevel(), LoggerInterface::WARNING);
            return $levelCmp <= 0;
        } catch (\Throwable $e) {
            // Fail-safe: if level comparison breaks, keep exception as error.
            return false;
        }
    }

    /**
     * Saves error payloads in the conversation as ERROR messages.
     */
    protected function saveConversationErrorMessages(AiPromptInterface $prompt, array $errors) : void
    {
        if (empty($errors)) {
            return;
        }

        $conversationId = $prompt->getConversationUid();
        if ($conversationId === null) {
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
                            $this,
                            $prompt,
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
                            $this,
                            $prompt,
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
                    'AI_CONVERSATION' => $conversationId,
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
     * saves an error that occurred during the conversation
     * 
     * TODO why is this so different from saveConversationWarning? It still produces a message, just with a different
     * role and maybe additional rating.
     *
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \Throwable $error
     * @return ?\Throwable returns the error if persisting failed, otherwise null
     */
    protected function saveConversationError(AiPromptInterface $prompt, \Throwable $error) : ExceptionInterface
    {
        $transaction = $this->workbench->data()->startTransaction();

        $conversationId  = $prompt->getConversationUid();
        
        $messageData  = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        
        // compact error payload for DATA column
        // TODO Save the SAME data here as in the case of a successful message!
        // Solution:
        // - Make sure we only save AI exception here
        // - All the error information will be saved by the logger when logging the exception
        // - We will have a button in the conversation to show the error details - see `Administratino > BG processing`
        // or `Administration > Data Flows > dblclick a flow run` for examples
        
        if (! $error instanceof ExceptionInterface) {
            $error = new AiPromptError($this, $prompt, 'AI prompt failed. ' . $error->getMessage(), null, $error);
        }

        $markdown = '';
        $errorWidget = $error->createWidget(UiPageFactory::createEmpty($this->getWorkbench()));
        foreach ($errorWidget->getTab(0)->getWidgets() as $widget) {
            if ($widget instanceof Markdown) {
                $markdown .= "\n" . $widget->getMarkdown() . "\n";
            }
        }
        
        $errorID = $error->getId();

        $errorPayload = [
            'class'   => get_class($error),
            'message' => $error->getMessage(),
            'code'    => $error->getCode(),
            'file'    => $error->getFile(),
            'line'    => $error->getLine(),
        ];
        $dataUxon = UxonObject::fromArray($errorPayload);
        
        $dataUxon->setProperty('ID', $errorID );
        
        if($conversationId === null) {
            $query = new OpenAiApiDataQuery($this->workbench);
            $query->appendMessage('Failed conversation: ' . $prompt->getUserPrompt());
            $conversationId =  $this->createConversation($prompt, $query);
            $this->enrichUxonWithTools($dataUxon);
            $dataUxon->setProperty('User Prompt', $prompt->getUserPrompt());
            try {
                $dataUxon->setProperty('System Prompt', $this->systemPrompt);
            } catch (\Throwable $e){
                $this->workbench->getLogger()->logException(
                    new AiPromptError($this, $prompt, 'Failed to log AI system prompt. ' . $e->getMessage(), null, $e)
                );
                $dataUxon->setProperty('System Prompt causes an error', true);
            }
        }

        try {
            $this->saveConversationErrorFeedback($conversationId, $error->getMessage(), $transaction);

            $messageData->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER'            => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'            => AiMessageTypeDataType::ERROR, // fallback to ::SYSTEM or ::TOOL if ERROR is not available
                'DATA'            => $dataUxon->toJson(true),
                'MESSAGE'         => $markdown,
                'SEQUENCE_NUMBER' => $this->sequenceNumber++,
                'ERROR_LOG_ID'    => $errorID
            ]);

            $messageData->dataCreate(false, $transaction);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
            // original error is returned so caller can still handle it
        }
        return $error;
    }

    protected function saveConversationErrorFeedback(string $conversationId, string $errorMessage, ?DataTransactionInterface $transaction = null) : void
    {
        $this->saveConversationFeedback(
            $conversationId,
            "Auto generated error message:\n" . substr($errorMessage, 0, 500),
            1,
            $transaction
        );
    }

    protected function saveConversationWarning(AiPromptInterface $prompt, array $warnings) : void
    {
        if (empty($warnings)) {
            return;
        }

        $conversationId = $prompt->getConversationUid();
        if ($conversationId === null) {
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
                            $this,
                            $prompt,
                            'Unrecognized payload while saving warning: ' . $warning->getMessage(),
                            null,
                            $warning
                        );
                    } else {
                        // Fallback path: convert non-platform warning payloads into AiPromptError
                        // so they can be saved consistently in the warning log.
                        $warningMessage = is_scalar($warning) || $warning === null
                            ? trim((string) $warning)
                            : trim(json_encode($warning, JSON_UNESCAPED_UNICODE) ?: '');

                        if ($warningMessage === '') {
                            $warningMessage = gettype($warning);
                        }

                        $warningException = new AiPromptError(
                            $this,
                            $prompt,
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
                    'AI_CONVERSATION' => $conversationId,
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

            // Use a flag for actually queued rows instead of count($warnings):
            // warnings can be transformed or skipped (e.g. empty message), so not every input warning becomes a DB row.
            if ($hasRows) {
                $messageData->dataCreate(false, $transaction);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
    }

    protected function saveConversationFeedback(string $conversationId, string $feedback, ?int $defaultRating = null, ?DataTransactionInterface $transaction = null) : void
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
    
    protected function enrichUxonWithTools( ?UxonObject $uxon) : UxonObject
    {
        if($uxon === null) {
            $dataUxon = new UxonObject([
                'tools' => []
            ]);
        }else {
            $dataUxon = $uxon;
            $dataUxon->setProperty('tools', []);
        }
        foreach ($this->getTools() as $tool) {
            $dataUxon->appendToProperty('tools', $tool->exportUxonObject());
        }
        
        return $dataUxon;
    }
    
    protected function enrichUxonWithJsonSchema(?UxonObject $uxon) : UxonObject
    {
        
        if($uxon === null) {
            $dataUxon = new UxonObject();
        }else {
            $dataUxon = $uxon;
        }
        if ($this->hasJsonSchema()) {
            $dataUxon->setProperty('responseJsonSchema', $this->getResponseJsonSchema());
        }
        return $dataUxon;
        
    }
    



    /**
     * AI concepts to be used in the system prompt
     * 
     * Each concept is basically a plugin, that generates part of the system prompt. You can use it anywhere in your
     * prompt via placeholder
     * 
     * @uxon-property concepts
     * @uxon-type \axenox\GenAI\Common\AbstractConcept
     * @uxon-template {"placeholder_name": {"alias": ""}}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $arrayOfConcepts
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setConcepts(UxonObject $arrayOfConcepts) : AiAgentInterface
    {
        $this->conceptConfig = null;
        $this->conceptConfig = $arrayOfConcepts;
        return $this;
    }

    public function getRawConcepts() : ?UxonObject
    {
        if ($this->conceptConfig instanceof UxonObject) {
            return $this->conceptConfig;
        }
        return null;
    }


    /**
     * 
     * @return \axenox\GenAI\Interfaces\AiConceptInterface[]
     */
    protected function getConcepts(AiPromptInterface $prompt, BracketHashStringTemplateRenderer $configRenderer) : array
    {
        $concepts = [];
        foreach ($this->conceptConfig as $placeholder => $uxon) {            
            if(! $uxon->hasProperty('output')) {
                $json = $configRenderer->render($uxon->toJson());
            }
            
            $concepts[] = AiFactory::createConceptFromUxon($this, $prompt, $placeholder, UxonObject::fromJson($json));
        }
        return $concepts;
    }

    /**
     * An introduction to explain the LLM, what the assistant is supposed to do.
     * 
     * ## Available placeholders
     * 
     * - `[#~app:#]` - get properties of the app, where the assistant is called from: e.g. `[#~app:alias#]`
     * - `[#~input:#]` - access the first row of the input data (e.g. data sent by the AIChat widget)
     * - `[#~config:#]`
     * 
     * @uxon-property instructions
     * @uxon-type string
     * @uxon-template You are a helpful assistant, who will answer questions about the structure of the following database. Here is the DB schema in DBML: \n\n[#metamodel_dbml#] \n\nAnswer using the following locale [#=User('LOCALE')#]
     * 
     * @param string $text
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setInstructions(string $text) : AiAgentInterface
    {
        $this->systemPrompt = $text;
        return $this;
    }
    
    protected function setSampleSystemPrompt(string $text) : AiAgentInterface
    {
        $this->sampleSystemPrompt = $text;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $promt
     * @return string
     */
    protected function getSystemPrompt(AiPromptInterface $prompt) : string
    {
        if ($this->systemPromptRendered === null) {
            $renderer = new BracketHashStringTemplateRenderer($this->workbench);
            $renderer->addPlaceholder(new FormulaPlaceholders($this->workbench, null, null, '='));
            $renderer->addPlaceholder(new ConfigPlaceholders($this->workbench, '~config:'));
            if (null !== $app = $this->getApp($prompt)) {
                $renderer->addPlaceholder(new AppPlaceholders($app, '~app:'));
            }
            if ($prompt->hasInputData()) {
                $renderer->addPlaceholder(new DataRowPlaceholders($prompt->getInputData(), 0, '~input:'));
            }
            foreach ($this->getConcepts($prompt, $renderer) as $placeholderResolver) {
                $renderer->addPlaceholder($placeholderResolver);
                if ($placeholderResolver instanceof AiConceptInterface) {
                    foreach ($placeholderResolver->getToolModels() as $toolName => $toolUxon) {
                        $this->toolsUxon[$toolName] = $toolUxon;
                    }
                }
            }
            
            try {
                
                if($this->sampleSystemPrompt){
                    $systemPrompt = $this->sampleSystemPrompt;
                } else {
                    $systemPrompt = $this->systemPrompt;
                }
                $this->systemPromptRendered = $renderer->render($systemPrompt ?? '');
            } catch (\Throwable $e) {
                throw new AiConceptRenderingError($renderer, 'Cannot apply AI concepts. ' . $e->getMessage(), null, $e, $systemPrompt);
            }
        }
        return $this->systemPromptRendered;
    }

    protected function getApp(AiPromptInterface $prompt) : ?AppInterface
    {
        $app = null;
        if ($prompt->isTriggeredOnPage() && $prompt->getPageTriggeredOn()->hasApp()) {
            $app = $prompt->getPageTriggeredOn()->getApp();
        }
        // TODO determine the app from input data?
        return $app;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiAgentUxonSchema::class;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\DataSources\DataConnectionInterface
     */
    protected function getConnection() : DataConnectionInterface
    {
        if ($this->dataConnection === null) {
            if($this->dataConnectionAlias === null) {
                throw new AiConnectionNotFoundError($this,"No Connection for agent " . $this->getName() . " found!");
            }
            $this->dataConnection = DataConnectionFactory::createFromModel($this->workbench, $this->dataConnectionAlias);
        }
        return $this->dataConnection;
    }
    
    /**
     * 
     * @param string $selector
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setDataConnectionAlias(string $selector) : AiAgentInterface
    {
        $this->dataConnectionAlias = $selector;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery $query
     * @return \axenox\GenAI\Common\AiResponse
     */
    protected function parseDataQueryResponse(AiPromptInterface $prompt, OpenAiApiDataQuery $query, string $conversationId) : AiResponse
    {
        if($this->hasJsonSchema()){
            $response = new AiResponse($prompt, $this->getAnswer($query), $conversationId, $query->getAnswerJson());
        }else {
            $response = new AiResponse($prompt, $this->getAnswer($query), $conversationId);
        }
        $response->setToolCalls($this->toolCalls);
        return $response;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return string
     */
    protected function getAnswer(AiQueryInterface $query) : string
    {
        if ($this->hasJsonSchema()) {
            $json = $query->getAnswerJson();

            if ($this->getResponseAnswerPath() !== null) {
                return ArrayDataType::filterJsonPath($json, $this->getResponseAnswerPath())[0];
            } else {
                $answer = $json;
            }

            if (!is_string($answer)) {
                $answer = json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            // Codeblock for the AIChat (?)
            return "```json\n" . $answer . "\n```";
        }

        return $query->getFullAnswer();
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return string
     */
    protected function getTitle(AiQueryInterface $query) : string
    {
        if ($this->hasJsonSchema() && $query->hasResponse() && $this->getResponseAnswerPath() !== null) {
            $json = $query->getAnswerJson();
            $title = ArrayDataType::filterJsonPath($json, $this->getResponseTitlePath())[0];
        } else {
            $title = StringDataType::truncate($query->getUserPrompt(), 50, true, true, true);
        }
        return $title;
    }

    /**
     * 
     * @param string $message
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param mixed $e
     * @return AiResponse
     */
    protected function createResponseUnavailable(string $message, AiPromptInterface $prompt, ?\Throwable $e = null)
    {
        return new AiResponse($prompt, $message);
    }

    /**
     * 
     * @param string $alias
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setAlias(string $alias) : AiAgentInterface
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\Selectors\AliasSelectorInterface
     */
    public function getSelector() : AliasSelectorInterface
    {
        return $this->selector;
    }

    /**
     * 
     * @param string $name
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setName(string $name) : AiAgentInterface
    {
        $this->name = $name;
        return $this;
    }

    protected function getModelData() : DataSheetInterface
    {
        if ($this->agentDataSheet === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT');
            $sheet->getColumns()->addFromSystemAttributes();
            $sheet->getColumns()->addMultiple([
                'NAME'
            ]);
            $sheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $this->getAliasWithNamespace(), ComparatorDataType::EQUALS);
            $sheet->dataRead();
            switch ($sheet->countRows()) {
                case 0: throw new AiAgentNotFoundError('AI agent "' . $this->getSelector()->__toString() . '" not found!');
                case 1: break;
                default: throw new AiAgentNotFoundError('Multiple AI agents found for "' . $this->getSelector()->__toString() . '"!');
            }
            $this->agentDataSheet = $sheet;
        }
        return $this->agentDataSheet;
    }

    protected function getVersionModelData() : DataSheetInterface
    {
        if($this-> versionDataSheet === null){
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT_VERSION');
            $sheet->getColumns()->addMultiple([
                    'VERSION',
                    'ENABLED_FLAG',
                    'DATA_CONNECTION'
                ]);
            $sheet->dataRead();
            $this->versionDataSheet = $sheet;
        }
        return $this->versionDataSheet;
        
    }

    protected function getVersionRow()  {
        if($this->versionRow === null){
            $this->versionRow = $this->getVersionModelData()->getRow($this->getVersionModelData()->getColumn('VERSION')->findRowByValue($this->getVersion()));
        }
        return $this->versionRow;
    }

    /**
     * 
     * @return string
     */
    public function getUid() : string
    {
        return $this->getModelData()->getCellValue('UID', 0);
    }

    /**
     * 
     * @return string
     */
    public function getName() : string
    {
        return $this->getModelData()->getCellValue('NAME', 0);
    }

    /**
     *
     * @return string
     */
    public function getVersion() : string
    {
        return $this->getSelector()->getVersion();
    }

    /**
     * 
     * @return array|null
     */
    protected function getResponseJsonSchema() : ?array
    {
        return $this->responseJsonSchema;
    }

    /**
     * 
     * @return bool
     */
    private function hasJsonSchema() : bool
    {        
        return $this->responseJsonSchema !== null;
    }

    public function setDevmode(bool $trueOrFalse): AiAgentInterface
    {
        $this->devMode = $trueOrFalse;
        return $this;
    }

    /**
     * 
     * @return bool
     */
    public function getDevmode() : bool
    {
        if($this->devMode === null){
            $this->setDevmode(BooleanDataType::cast($this->getVersionRow()['ENABLED_FLAG']));
        }
        return $this->devMode;
        
    }

    

    /**
     * Summary of setResponseJsonSchema
     * @uxon-property response_json_schema 
     * @uxon-type object
     * @uxon-template {"type":"object","properties":{"title":{"type":"string","description":"Summary of the conversation"},"text":{"type":"string","description":"Your answer as markdown"}},"additionalProperties":false,"required":["title"]}
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return static
     */
    protected function setResponseJsonSchema(UxonObject $uxon)
    {
        $this->responseJsonSchema = $uxon->toArray();
        return $this;
    }

    /**
     * The JSONPath to the response message if a JSON schema is used by this assistant
     * 
     * @uxon-property response_answer_path
     * @uxon-type string
     * @uxon-template $.text
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseAnswerPath(string $jsonPath) : GenericAssistant
    {
        $this->responseAnswerPath = $jsonPath;
        return $this;
    } 

    /**
     * Returns the JSONpath to find the text answer in the response JSON if a response_json_schema was provided
     * @return string
     */
    protected function getResponseAnswerPath() : ?string
    {
        return $this->responseAnswerPath;
    }

    /**
     * The JSONPath to the conversation title in the response JSON if a JSON schema is used by this assistant
     * 
     * @uxon-property response_title_path
     * @uxon-type string
     * @uxon-template $.title
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseTitlePath(string $jsonPath) : GenericAssistant
    {
        $this->responseTitlePath = $jsonPath;
        return $this;
    } 

    /**
     * Returns the JSONPath to the conversation title in the response JSON if a JSON schema is used by this assistant
     * 
     * @return string
     */
    protected function getResponseTitlePath() : ?string
    {
        return $this->responseTitlePath;
    }

    /**
     * Tools (function calls) made available to the LLM
     * 
     * ```
     *   {
     *      "tools": {
     *          "GetDocs": {
     *              "description": "Load markdown from our documentation by URL",
     *              "arguments": [
     *                  {
     *                      "name": "uri",
     *                      "description": "Markdown file URL - absolute (with https://...) or relative to api/docs on this server",
     *                      "data_type": {
     *                          "alias": "exface.Core.String"
     *                      }
     *                  }
     *              ]
     *          }
     *      }
     *  }
     *  
     * ```
     * @uxon-property tools
     * @uxon-type \axenox\GenAI\Common\AbstractAiTool[]
     * @uxon-template {"": {"description": "", "arguments": [{"name": "", "data_type": {"alias": ""}}]}}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $objectWithToolDefs
     * @return GenericAssistant
     */
    protected function setTools(UxonObject $objectWithToolDefs) : AiAgentInterface
    {
        foreach ($objectWithToolDefs as $toolName => $toolUxon) {
            $this->toolsUxon[$toolName] = $toolUxon;
        }
        return $this;
    }

    /**
     * 
     * @return AiToolInterface[]
     */
    protected function getTools() : array
    {
        if ($this->tools === null) {
            if ($this->toolsUxon === null) {
                $this->tools = [];
            } else {
                foreach ($this->toolsUxon as $toolName => $uxon) {
                    $tool = AiFactory::createToolFromUxon($this->workbench, $uxon, $toolName);
                    $this->addTool($tool);
                }
            }
        }
        return $this->tools;
    }

    /**
     * @param string $name
     * @return AiToolInterface
     */
    public function getTool(string $name) : AiToolInterface
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        throw new AiAgentRuntimeError($this, 'Tool "' . $name . '" not found!');
    }

    protected function addTool(AiToolInterface $tool) : AiAgentInterface
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property prompt_suggestions
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template [""]
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setPromptSuggestions(UxonObject $suggestions) : GenericAssistant
    {
        $array = $suggestions->getPropertiesAll();
        foreach ($array as $s) {
            if (!is_string($s)) {
                
                return $this;
            }
        }

        $this->promptSuggestions = $array;
        return $this;
    }

    public function getPromptSuggestions(): array
    {
        return $this->promptSuggestions;
    }
    
    public function getWorkbench()
    {
        return $this->workbench;
    }

    /**
     * @param DebugMessage $debugWidget
     * @return void
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        foreach ($debugWidget->getTabs() as $tab) {
            if ($tab->getCaption() === 'AI Agent') {
                return $debugWidget;
            }
        }
        $tab = $debugWidget->createTab();
        $tab->setCaption('AI Agent');
        $tab->setWidgets(new UxonObject([[
            'widget_type' => 'InputUxon',
            'disabled' => true,
            'width' => '100%',
            'height' => '100%',
            'hide_caption' => true,
            'value' => $this->exportUxonObject()->toJson(),
        ]]));
        $debugWidget->addTab($tab);
        return $debugWidget;
    }
}