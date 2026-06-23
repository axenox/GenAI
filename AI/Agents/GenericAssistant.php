<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiConversation;
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
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
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
use exface\Core\Widgets\DebugMessage;

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
    private ?AiConversation $conversation = null;

    private $maxNumberOfCalls = 10;

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
            throw $conversation->saveError($e, $this->systemPrompt, $this->getTools(), $this->hasJsonSchema() ? $this->getResponseJsonSchema() : null);
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

        $conversation = $this->getConversation($prompt, $query);

        try {
            $conversation->saveSystemPrompt($query, $this->systemPrompt, $this->getTools(), $this->hasJsonSchema() ? $this->getResponseJsonSchema() : null);
            $conversation->saveUserPrompt($query);
            $prompt->setConversationUid($conversation->getConversationId());
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to save AI conversation. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->systemPrompt, $this->getTools(), $this->hasJsonSchema() ? $this->getResponseJsonSchema() : null);
        }

        try {
            $performedQuery = $this->getConnection()->query($query);
        } catch (\Throwable $e){
            $e = new AiPromptError($this, $prompt, 'Failed to query LLM. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->systemPrompt, $this->getTools(), $this->hasJsonSchema() ? $this->getResponseJsonSchema() : null);
        }
        
        try {
            $performedQuery = $this->handleToolCalls($prompt, $performedQuery, $conversation);
        } catch (\Throwable $e){
            if (! $e instanceof AiToolRuntimeError) {
                $e = new AiPromptError($this, $prompt, 'Failed to call AI tools. ' . $e->getMessage(), null, $e);
            }
            throw $conversation->saveError($e, $this->systemPrompt, $this->getTools(), $this->hasJsonSchema() ? $this->getResponseJsonSchema() : null);
        }
        try {
            $conversation->saveResponse(
                $performedQuery,
                $this->getAnswer($performedQuery),
                $this->hasJsonSchema() ? $performedQuery->getAnswerJson() : null
            );
            return $this->parseDataQueryResponse($prompt, $performedQuery, $conversation->getConversationId());
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to process AI response. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->systemPrompt, $this->getTools(), $this->hasJsonSchema() ? $this->getResponseJsonSchema() : null);
        }
    }

    /**
     * Returns the current conversation helper for the prompt.
     *
     * Reuses the existing helper if it matches the prompt conversation ID,
     * otherwise creates a new helper and initializes the prompt conversation.
     */
    protected function getConversation(AiPromptInterface $prompt, ?AiQueryInterface $query = null) : AiConversation
    {
        $promptConversationId = $prompt->getConversationUid();

        if ($this->conversation === null) {
            $this->conversation = new AiConversation($this, $prompt, $promptConversationId, $query);
            return $this->conversation;
        }

        if ($promptConversationId === null || $this->conversation->getConversationId() !== $promptConversationId) {
            $this->conversation = new AiConversation($this, $prompt, $promptConversationId, $query);
        }

        return $this->conversation;
    }
    
    protected function handleToolCalls(AiPromptInterface $prompt, AiQueryInterface $performedQuery, AiConversation $conversation) : AiQueryInterface
    {
        $numberOfCallResponses = 0;
        // Check if the LLM has put some tool calls in its response
        while ($performedQuery->hasToolCalls()) {
            $numberOfCallResponses++;
            $conversation->saveToolCallRequest($performedQuery);

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
                    $resultOfTool = null;
                    try {
                        $resultOfTool = $tool->invoke($this, $prompt, $args);
                        $exceptions = $resultOfTool->getExceptions();
                    } catch (\Throwable $e) {
                        if (! $e instanceof AiToolCriticalError) {
                            $e = new AiToolCriticalError($tool, $prompt, 'Unexpected error in AI tool. ' . $e->getMessage(), null, $e);
                        }
                        $resultOfTool = new AiToolResultString($tool, $args, 'ERROR: Tool execution failed. ' . $e->getMessage());
                        $exceptions = [$e];
                    }
                    foreach ($exceptions as $e) {
                        $this->getWorkbench()->getLogger()->logException($e);
                    }
                    $conversation->saveExceptions($exceptions);
                    
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
            $toolCallResponses = $conversation->saveToolResponses($performedQuery, $toolCallResponses);
            // $toolCallResponses = null;
            $performedQuery = $this->getConnection()->query($performedQuery);
            //$query->clearPreviousToolCalls();
        }
        return $performedQuery;
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
    public function getConnection() : DataConnectionInterface
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
    public function getTitle(AiQueryInterface $query) : string
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
     * @uxon-template {"": {"alias": "", "description": ""}}
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

    /**
     * Maximum number of tool calls before a response
     * 
     * @uxon-property tool_calls_max
     * @uxon-type integer
     * @uxon-default 30
     * 
     * @param int $number
     * @return $this
     */
    protected function setToolCallsMax(int $number) : GenericAssistant
    {
        $this->maxNumberOfCalls = $number;
        return $this;
    }
}