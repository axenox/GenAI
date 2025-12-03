<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiToolCallResponse;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiConceptIncompleteError;
use axenox\GenAI\Exceptions\AiToolNotFoundError;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Uxon\AiAgentUxonSchema;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\AppPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;

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
    use ImportUxonObjectTrait;

    use AliasTrait;

    private $workbench = null;

    private $systemPrompt = null;

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

    private $tools = [];

    private $sequenceNumber = null;

    private $maxNumberOfCalls = 3;

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
            $this->setInstructions($systemPrompt);
        } catch (AiConceptIncompleteError $e) {
            throw $e;
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

        $conversationId = $this->saveConversation($prompt, $query);
        $prompt->setConversationUid($conversationId);

        $performedQuery = $this->getConnection()->query($query);
        $numberOfCalls = 0;
        while ($performedQuery->hasToolCalls()) {
            $this->saveConversationToolCallRequest($prompt, $performedQuery);
           
            $requestedCalls = $performedQuery->getToolCalls();
            $existingCall = false;

            foreach($requestedCalls as $call){

                foreach ($this->getTools() as $tool){
                    if ($tool->getName() === $call->getToolName()){
                        break;
                    }
                    $tool = null;
                }
                if ($tool === null){
                    throw (new AiToolNotFoundError("Requested tool not found"))
                            ->setConversationId($conversationId);
                }

                $resultOfTool = $this->maxNumberOfCalls > $numberOfCalls ? $tool->invoke(array_values($call->getArguments())): "Maximum number of calls have been reached.";

                //to prevent duplication on calls
                $callId = $call->getCallId();
                
                $toolCallResponses[$callId] = new AiToolCallResponse(
                    $call->getToolName(),
                    $callId,
                    $call->getArguments(),
                    $resultOfTool
                );

                $this->toolCalls[] = $toolCallResponses[$callId];
                
                $query->appendToolMessages($existingCall, $resultOfTool, $callId, $performedQuery->getResponseMessage());
                $existingCall = true;  
            }            
            $toolCallResponses = $this->saveConversationToolCalls($prompt, $query, $toolCallResponses);
            // $toolCallResponses = null;
            $performedQuery = $this->getConnection()->query($query);
            $numberOfCalls++;
            //$query->clearPreviousToolCalls();
        }
        $this->saveConversationResponse($prompt, $performedQuery);

        return $this->parseDataQueryResponse($prompt, $performedQuery, $conversationId);
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
                $conversation = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
                try {
                    $connection = $this->getConnection();
                    $modelName = $connection->getModelName($query);
                    $connectionId = $connection->getId();
                } catch (\Throwable $e) {
                    $modelName = null;
                    $connectionId = null;
                }
                $row = [
                    'AI_AGENT' => $this->getUid(),
                    'AI_AGENT_VERSION_NO' => $this->getVersion(),
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'TITLE' => $this->getTitle($query),
                    'DATA' => $prompt->getInputData()->exportUxonObject()->toJson(),
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

                $message->addRow([
                    'AI_CONVERSATION' => $conversationId,
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'ROLE'=> AiMessageTypeDataType::SYSTEM,
                    'MESSAGE'=> $this->systemPrompt,
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
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
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
        try {

            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::TOOLCALLING,
                'MESSAGE'=> MarkdownDataType::escapeCodeBlock(json_encode($query->getToolCalls(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'DATA' => UxonObject::fromArray($query->getResponseMessage())->toJson(true),
                'SEQUENCE_NUMBER' => $this->sequenceNumber++,
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST_PER_M_TOKENS'=> $query->getCostPerMTokens(),
                'COST' => ($query->getTokensInPrompt() + $query->getTokensInAnswer()) * $query->getCostPerMTokens() * 0.000001,
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
                'COST_PER_M_TOKENS'=> $query->getCostPerMTokens(),
                'COST' => ($query->getTokensInPrompt() + $query->getTokensInAnswer()) * $query->getCostPerMTokens() * 0.000001,
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
     * saves the responses of the requested tools 
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @param array $responses
     * @return void
     */
    protected function saveConversationToolCalls(AiPromptInterface $prompt, AiQueryInterface $query, array $responses) : ?array 
    {
        $transaction = $this->workbench->data()->startTransaction();
        $conversationId = $prompt->getConversationUid();
        $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
        try {
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE' => AiMessageTypeDataType::TOOL,
                'DATA' => UxonObject::fromArray($responses)->toJson(true),
                'MESSAGE' =>  arkdownDataType::escapeCodeBlock(json_encode($responses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
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
     * AI concepts to be used in the system prompt
     * 
     * Each concept is basically a plugin, that generates part of the system prompt. You can use it anywhere in your
     * prompt via placeholder
     * 
     * @uxon-property concepts
     * @uxon-type \axenox\GenAI\Common\AbstractConcept
     * @uxon-template {"metamodel_bmdb": {"class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept"}}
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

    /**
     * 
     * @return \axenox\GenAI\Interfaces\AiConceptInterface[]
     */
    protected function getConcepts(AiPromptInterface $prompt, BracketHashStringTemplateRenderer $configRenderer) : array
    {
        $concepts = [];
        foreach ($this->conceptConfig as $placeholder => $uxon) {
            $json = $uxon->toJson();
            $json = $configRenderer->render($json);
            $concepts[] = AiFactory::createConceptFromUxon($this->workbench,$placeholder, $prompt, UxonObject::fromJson($json));
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
            foreach ($this->getConcepts($prompt, $renderer) as $concept) {
                $renderer->addPlaceholder($concept);
                if(is_a($concept, \axenox\GenAI\Interfaces\AiConceptInterface::class)){
                    foreach($concept->getTools() as $tool){
                        $this->addTool($tool);
                    }
                }
            }
            
            try {
                $this->systemPromptRendered = $renderer->render($this->systemPrompt ?? '');
            } catch (\Throwable $e) {
                throw new AiConceptIncompleteError('Cannot apply AI concepts. ' . $e->getMessage(), null, $e);
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
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = new UxonObject();
        // TODO
        return $uxon;
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
        $response = new AiResponse($prompt, $this->getAnswer($query), $conversationId);
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
            $answer = ArrayDataType::filterJsonPath($json, $this->getResponseAnswerPath())[0];
        } else {
            $answer = $query->getFullAnswer();
        }
        return $answer;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return string
     */
    protected function getTitle(AiQueryInterface $query) : string
    {
        if ($this->hasJsonSchema() && $query->hasResponse()) {
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
        foreach ($objectWithToolDefs as $tool => $uxon) {
            $tool = AiFactory::createToolFromUxon($this->workbench, $tool, $uxon);
            $this->addTool($tool);
        }
        return $this;
    }

    /**
     * 
     * @return AiToolInterface[]
     */
    protected function getTools() : array
    {
        return $this->tools;
    }

    protected function addTool(AiToolInterface $tool)
    {
        $this->tools[] = $tool;
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
}