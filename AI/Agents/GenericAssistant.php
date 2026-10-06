<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AbstractAiTaskHandler;
use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\AiToolCallResponse;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\ToolBox;
use axenox\GenAI\Events\OnAiToolCallEvent;
use axenox\GenAI\Events\OnBeforeAiToolCallEvent;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\Exceptions\AiConceptRenderingError;
use axenox\GenAI\Exceptions\AiConnectionNotFoundError;
use axenox\GenAI\Exceptions\AiPromptError;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiConceptInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Uxon\AiAgentUxonSchema;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Interfaces\AppInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\AppPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;

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
class GenericAssistant extends AbstractAiTaskHandler implements AiAgentInterface
{
    private $systemPrompt = null;
    
    private $sampleSystemPrompt = null;

    private $systemPromptRendered = null;

    private $conceptConfig = [];

    private $dataConnectionAlias = null;

    private $dataConnection = null;

    private $responseJsonSchema = null;

    private $devMode = null;

    private $responseAnswerPath = null;

    private $responseTitlePath = null;

    /** @var UxonObject[] */
    private array $conceptToolsUxon = [];

    /** @var AiConceptInterface[]|null */
    protected ?array $concepts = null;

    private $maxNumberOfCalls = 10;

    /** @var AiToolCallResponse[] */
    private array $toolCalls = [];

    private $promptSuggestions = [];

    /**
     * Initializes all configured prompt components once.
     * TODO why do we need this inner state of the GenericAssistant defined by the $prompt? It interferes with the idea
     * of handling prompts independently. We should move back to explicit prompt dependency in getConcepts() and
     * probably getSkills() too. In the case of getSkills() we could also leave each skill prompt-independent, but
     * give Skill::getInstructions() the $prompt as argument underlining that the instruction rendering actually needs
     * the prompt context - for concepts inside the skills.
     */
    protected function init(AiPromptInterface $prompt) : void
    {
        // Concepts depend on the prompt (the describe the context of it)
        $this->initConcepts($prompt);
        // Skills may have concepts too
        $this->initSkills($prompt);
        // Tools are prompt-independent
        $this->initTools();
    }

    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        $this->init($prompt);

        // Initialize the data query
        $query = new AiQuery($this->workbench);
        // Add the user prompt. Do it before initializing the conversation - if it is a new conversation, the user
        // prompt will be used as title.
        $query->appendMessage($prompt->getUserPrompt());
        $query->setFiles($prompt->getFiles());
        
        // Create or restore the conversation for this execution.
        $conversation = AiFactory::createConversationFromPrompt($this, $prompt);
        $conversationId = $conversation->getConversationId();
        $query->setConversationUid($conversationId);


        try {
            if ($conversation->hasSystemPrompt()) {
                $systemPrompt = $conversation->getSystemPrompt();
            } else {
                $systemPrompt = $this->renderSystemPrompt($prompt);
            }
            $query->setSystemPrompt($systemPrompt);
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to prepare AI prompt. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
            /* TODO handle different errors differently
            $this->workbench->getLogger()->logException($e);
            return $this->createResponseUnavailable('Error contacting the assistant', $prompt, $e);
            */
        }

        // Add JSON schema
        if($this->hasResponseJsonSchema()) {
            $query->setResponseJsonSchema($this->getResponseJsonSchema());
            if ($val = $this->getResponseAnswerPath()) {
                $query->setResponseAnswerPath($val);
            }
        }
        
        // Add tools
        foreach ($this->getTools() as $tool) {
            $query->addTool($tool);
        }

        // Now save the conversation messages for system and user prompts including all their metadata metadata
        try {
            if (! $conversation->hasSystemPrompt()) {
                $conversation->saveSystemPrompt($systemPrompt, $this->createSystemPromptMessageData());
            }
            $conversation->saveWarnings($this->toolWarnings);
            $conversation->saveUserPrompt(
                $prompt->getUserPrompt(),
                $prompt->getFiles()
            );
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to save AI conversation. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }

        try {
            $performedQuery = $this->getConnection()->query($query);
        } catch (\Throwable $e){
            $e = new AiPromptError($this, $prompt, 'Failed to query LLM. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }
        
        try {
            $performedQuery = $this->handleToolCalls($prompt, $performedQuery, $conversation);
        } catch (\Throwable $e){
            if (! $e instanceof AiToolRuntimeError) {
                $e = new AiPromptError($this, $prompt, 'Failed to call AI tools. ' . $e->getMessage(), null, $e);
            }
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }
        try {
            $conversation->saveResponse(
                $performedQuery,
                $performedQuery->getAnswerMarkdown(),
                $this->hasResponseJsonSchema() ? $performedQuery->getAnswerJson() : null
            );
            return $this->parseDataQueryResponse($prompt, $performedQuery, $conversation->getConversationId());
        } catch (\Throwable $e) {
            $e = new AiPromptError($this, $prompt, 'Failed to process AI response. ' . $e->getMessage(), null, $e);
            throw $conversation->saveError($e, $this->getTools(), $this->getResponseJsonSchema());
        }
    }

    protected function handleToolCalls(AiPromptInterface $prompt, AiQueryInterface $performedQuery, AiConversationInterface $conversation) : AiQueryInterface
    {
        $numberOfCallResponses = 0;
        $toolCallResponses = [];
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
                    try {
                        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeAiToolCallEvent($tool));
                    } catch (\Throwable $e) {
                        $this->getWorkbench()->getLogger()->logException($e);
                    }
                    $toolStartedAt = microtime(true);
                    try {
                        $resultOfTool = $tool->invoke($this, $prompt, $args);
                        $exceptions = $resultOfTool->getExceptions();
                    } catch (\Throwable $e) {
                        if (! $e instanceof AiToolCriticalError) {
                            $e = new AiToolCriticalError($tool, $prompt, 'Unexpected error in AI tool. ' . $e->getMessage(), null, $e);
                        }
                        $e->setToolCall($call);
                        $resultOfTool = new AiToolResultString($tool, $args, 'ERROR: Tool execution failed. ' . $e->getMessage(), null, [], [$e]);
                        $exceptions = [$e];
                    } finally {
                        if ($resultOfTool !== null && method_exists($resultOfTool, 'setDurationMs')) {
                            $resultOfTool->setDurationMs((microtime(true) - $toolStartedAt) * 1000);
                        }
                    }
                    foreach ($exceptions as $e) {
                        if ($e instanceof AiToolCriticalError) {
                            $e->setToolCall($call);
                        }
                        $this->getWorkbench()->getLogger()->logException($e);
                    }
                    $conversation->saveExceptions($exceptions);
                    
                    // TODO why are we checking for a specific method here? Why is it not in the interface???
                    $durationMs = method_exists($resultOfTool, 'getDurationMs')
                        ? $resultOfTool->getDurationMs()
                        : null;
                    try {
                        $this->getWorkbench()->eventManager()->dispatch(new OnAiToolCallEvent($resultOfTool, $durationMs));
                    } catch (\Throwable $e) {
                        $this->getWorkbench()->getLogger()->logException($e);
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
                
                // TODO move rendering tool result as string to the API adapters. 
                // Having the result rendering logic in the tool result itself is not flexible enough! The adapter should
                // decide, if the result should be represented as markdown, as JSON or maybe even as XML!
                $performedQuery->appendToolMessages($existingCall, $resultOfTool->getValueWithMetadata(), $callId, $performedQuery->getResponseMessage());
                $existingCall = true;
            }
            $conversation->saveToolResponses($performedQuery, $toolCallResponses);
            $toolCallResponses = [];
            $performedQuery = $this->getConnection()->query($performedQuery);
            if (! $performedQuery instanceof AiQueryInterface) {
                throw new AiPromptError($this, $prompt, 'AI connection returned an invalid query type: expecting instance of AiQueryInterface.');
            }
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
        $this->conceptConfig = $arrayOfConcepts;
        $this->concepts = null;
        $this->systemPromptRendered = null;
        $this->tools = null;
        return $this;
    }

    
    protected function setSkills(UxonObject $skills) : AiAgentInterface
    {
        parent::setSkills($skills);
        $this->systemPromptRendered = null;
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
    protected function initConcepts(
        AiPromptInterface $prompt,
        ?BracketHashStringTemplateRenderer $configRenderer = null
    ) : void
    {
        if ($this->concepts !== null) {
            return;
        }

        $configRenderer = $configRenderer ?? $this->createPromptRenderer($prompt);
        $this->concepts = [];
        foreach ($this->conceptConfig as $placeholder => $uxon) {
            $json = $uxon->toJson();
            if (! $uxon->hasProperty('output')) {
                $json = $configRenderer->render($json);
            }

            $this->concepts[] = AiFactory::createConceptFromUxon(
                $this,
                $prompt,
                $placeholder,
                UxonObject::fromJson($json)
            );
        }
        $this->tools = null;
    }

    /**
     * Returns the initialized concepts.
     *
     * @return AiConceptInterface[]
     */
    protected function getConcepts() : array
    {
        return $this->concepts ?? [];
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
     * {@inheritDoc}
    * @see \axenox\GenAI\Interfaces\AiAgentInterface::renderSystemPrompt()
     */
    public function renderSystemPrompt(AiPromptInterface $prompt) : string
    {
        $renderer = $this->createPromptRenderer($prompt);
        $this->init($prompt);

        foreach ($this->getPlaceholderResolvers($prompt) as $placeholderResolver) {
            $renderer->addPlaceholder($placeholderResolver);
        }

        if ($this->systemPromptRendered === null) {
            try {
                
                if($this->sampleSystemPrompt){
                    $systemPrompt = $this->sampleSystemPrompt;
                } else {
                    $systemPrompt = $this->systemPrompt;
                }
                $this->systemPromptRendered = $renderer->render($systemPrompt ?? '');
                $this->systemPromptRendered .= $this->renderSkills();
            } catch (\Throwable $e) {
                throw new AiConceptRenderingError($renderer, 'Cannot apply AI concepts. ' . $e->getMessage(), null, $e, $systemPrompt);
            }
        }
        return $this->systemPromptRendered;
    }

    /**
     * Returns an array of resolvers for placeholders in instructions.
     * 
     * Placeholders may be defined
     * 
     * - by the agent designer in the form of concepts (each concept replaces a placeholder)
     * - by the agent prototype itself in case it has its own special context - e.g. the axenox.IDE.SqlAdminAssistant
     * "knows" which data connection it is working with.
     * 
     * @param AiPromptInterface $prompt
     * @return AiConceptInterface[]
     */
    protected function getPlaceholderResolvers(AiPromptInterface $prompt) : array
    {
        return $this->getConcepts();
    }

    /**
     * Builds the persisted system-prompt metadata, including rendered skill lengths.
     */
    protected function createSystemPromptMessageData() : array
    {
        $payload = new UxonObject([
            'tools' => [],
            'skills' => []
        ]);
        foreach ($this->getTools() as $tool) {
            $payload->appendToProperty('tools', $tool->exportUxonObject());
        }

        foreach ($this->getSkills() as $skill) {
            $skillUxon = $skill->exportUxonObject();
            $payload->appendToProperty('skills', new UxonObject([
                'alias' => $skillUxon instanceof UxonObject ? $skillUxon->getProperty('alias') : null,
                'placeholder' => $skill->getPlaceholder(),
                'length_chars' => mb_strlen($skill->getInstructions(), 'UTF-8')
            ]));
        }

        if ($this->hasResponseJsonSchema()) {
            $payload->setProperty('responseJsonSchema', $this->getResponseJsonSchema());
        }

        return [
            'model' => $this->getConnection()->getModelName(),
            'payload' => $payload
        ];
    }

    /**
     * Renders the instructions of all assigned skills as a system-prompt appendix.
     *
     * @return string
     */
    protected function renderSkills() : string
    {
        $appendix = '';
        foreach ($this->getSkills() as $skill) {
            $skillText = $skill->getInstructions();
            if (trim($skillText) === '') {
                continue;
            }
            $appendix .= "\n\n" . $skillText;
        }
        return $appendix;
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
     * Creates a renderer with placeholders from the current prompt context.
     */
    protected function createPromptRenderer(AiPromptInterface $prompt) : BracketHashStringTemplateRenderer
    {
        $renderer = new BracketHashStringTemplateRenderer($this->workbench);
        $renderer->addPlaceholder(new FormulaPlaceholders($this->workbench, null, null, '='));
        $renderer->addPlaceholder(new ConfigPlaceholders($this->workbench, '~config:'));
        if (null !== $app = $this->getApp($prompt)) {
            $renderer->addPlaceholder(new AppPlaceholders($app, '~app:'));
        }
        if ($prompt->hasInputData()) {
            $renderer->addPlaceholder(new DataRowPlaceholders($prompt->getInputData(), 0, '~input:'));
        }

        return $renderer;
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
        * @return AiConnectorInterface
     */
    public function getConnection() : AiConnectorInterface
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
    * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return \axenox\GenAI\Common\AiResponse
     */
    protected function parseDataQueryResponse(AiPromptInterface $prompt, AiQueryInterface $query, string $conversationId) : AiResponse
    {
        if($this->hasResponseJsonSchema()){
            $response = new AiResponse($prompt, $query->getAnswerMarkdown(), $conversationId, $query->getAnswerJson());
        } else {
            $response = new AiResponse($prompt, $query->getAnswerMarkdown(), $conversationId);
        }
        $response->setToolCalls($this->toolCalls);
        return $response;
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
    protected function hasResponseJsonSchema() : bool
    {
        return $this->getResponseJsonSchema() !== null;
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
     * If the LLM should respond with a JSON, define its JSONschema here
     * 
     * @uxon-property response_json_schema 
     * @uxon-type object
     * @uxon-template {"type":"object","properties":{"title":{"type":"string","description":"Summary of the conversation"},"text":{"type":"string","description":"Your answer as markdown"}},"additionalProperties":false,"required":["title"]}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return static
     */
    public function setResponseJsonSchema(UxonObject $uxon) : AiAgentInterface
    {
        $this->responseJsonSchema = $uxon->toArray();
        return $this;
    }

    /**
     * If the AI should respond with JSON, specify the path where to find its answer/comment in that JSON
     * 
     * @uxon-property response_answer_path
     * @uxon-type string
     * @uxon-template $.text
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseAnswerPath(string $jsonPath) : AiAgentInterface
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
     * Adds tools contributed by configured skills and concepts.
     *
     * @param ToolBox $toolBox
     * @return \Throwable[]
     */
    protected function configureAdditionalTools(ToolBox $toolBox) : array
    {
        $warnings = parent::configureAdditionalTools($toolBox);
        $this->conceptToolsUxon = [];
        foreach ($this->getConcepts() as $concept) {
            foreach ($concept->getToolModels() as $toolName => $toolUxon) {
                $this->conceptToolsUxon[$toolName] = $toolUxon;
            }
        }

        foreach ($this->conceptToolsUxon as $toolName => $toolUxon) {
            $toolBox->append(
                AiFactory::createToolFromUxon($this->workbench, $toolUxon, $toolName),
                $toolName,
                'concept configuration'
            );
        }

        return $warnings;
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