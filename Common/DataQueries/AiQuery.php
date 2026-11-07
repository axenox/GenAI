<?php
namespace axenox\GenAI\Common\DataQueries;

use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\DataQueries\AbstractDataQuery;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugger;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Interfaces\Filesystem\FileInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Widgets\DebugMessage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Factories\AiToolResultFactory;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Common\ApiAdapters\AiMessageSnapshot;

/**
 * One API-agnostic model exchange with detached typed request context.
 */
class AiQuery extends AbstractDataQuery implements AiQueryInterface
{

    private $workbench;

    private $messages = [];
    private ?AiConversationInterface $conversation = null;
    private array $contextMessages = [];
    private array $toolResults = [];
    private array $toolResultBatches = [];

    private $systemPrompt = null;

    private $temperature = null;

    private $conversationUid = null;
    
    private $request = null;

    private $response = null;
    private ?HttpResponseAdapterInterface $responseAdapter = null;

    private $costs = null;
    private array $warnings = [];
    
    private ?array $responseJsonSchema = null;
    private ?string $responseAnswerPath = null;

    private $tools = [];
    
    private $files = [];

    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /**
     * 
     * @return array
     */
    public function getMessages(bool $includeConversation = false) : array
    {
        $messages = [];
        if ($systemPrompt = $this->getSystemPrompt()) {
            $messages[] = ['content' => $systemPrompt, 'role' => AiMessageTypeDataType::SYSTEM];
        }
        $messages = array_merge($messages, $this->messages);
        return $messages;
    }

    /** Returns the history owner without loading or formatting history. */
    public function getConversation() : ?AiConversationInterface
    {
        return $this->conversation;
    }

    /** Associates this exchange with its history owner. */
    public function setConversation(AiConversationInterface $conversation) : AiQuery
    {
        $this->conversation = $conversation;
        $this->conversationUid = $conversation->getConversationId();
        return $this;
    }

    /** Takes a detached snapshot selected by the caller. */
    public function setContextMessages(array $messages) : AiQuery
    {
        $this->contextMessages = [];
        foreach ($messages as $message) {
            if (!$message instanceof AiMessageInterface) {
                throw new \InvalidArgumentException('AI context requires typed conversation messages.');
            }
            $this->contextMessages[] = new AiMessageSnapshot($this->workbench, $message);
        }
        return $this;
    }

    /** Returns detached typed input, never a database-backed view. */
    public function getContextMessages() : array
    {
        return array_map(static fn(AiMessageInterface $message) => clone $message, $this->contextMessages);
    }

    /** Returns correlated results without response wrappers or formatting. */
    public function getToolResults() : array
    {
        return array_map(fn(AiToolResultInterface $result) => $this->snapshotToolResult($result), $this->toolResults);
    }

    /** Accepts typed execution results while retaining their call-ID correlation. */
    public function withToolResults(array $results, ?string $messageId = null) : AiQueryInterface
    {
        $clone = clone $this;
        if ($messageId === null && $this->contextMessages !== []) {
            $messageId = end($this->contextMessages)->getUid();
        }
        foreach ($results as $id => $result) {
            $id = (string) $id;
            if ($id === '' || !$result instanceof AiToolResultInterface) {
                throw new \InvalidArgumentException('AI tool results must be typed and keyed by a non-empty call ID.');
            }
            $clone->toolResults[$id] = $this->snapshotToolResult($result);
            if ($messageId !== null) {
                $clone->toolResultBatches[$messageId][$id] = $clone->toolResults[$id];
            }
        }
        return $clone;
    }

    /** Returns execution results scoped to their message, allowing provider call-ID reuse. */
    public function getToolResultBatches() : array
    {
        $batches = [];
        foreach ($this->toolResultBatches as $messageId => $results) {
            $batches[$messageId] = array_map(fn(AiToolResultInterface $result) => $this->snapshotToolResult($result), $results);
        }
        return $batches;
    }

    /** Detaches semantic JSON values while retaining the typed durable contract. */
    private function snapshotToolResult(AiToolResultInterface $result) : AiToolResultInterface
    {
        return AiToolResultFactory::createFromUxon($this->workbench, new UxonObject(
            AiPayload::decode(AiPayload::encode($result->exportUxonObject()->toArray()))
        ));
    }

    /** Returns normalized response content and explicit exchange metadata. */
    public function getResponseData() : array
    {
        return [
            'content' => $this->responseAdapter->getContent(),
            'provider' => $this->responseAdapter->getProviderData(),
            'model' => $this->responseAdapter->getModel(),
            'tokens_prompt' => $this->getTokensInPrompt(),
            'tokens_completion' => $this->getTokensInAnswer(),
            'cost' => $this->getCosts(),
            'finish_reason' => $this->getFinishReason(),
        ];
    }

    /**
     * 
     * @param string $content
     * @param string $role
     * @return \axenox\GenAI\Common\DataQueries\AiQuery
     */
    public function appendMessage(string $content, string $role = AiMessageTypeDataType::USER) : AiQuery
    {
        $this->messages[] = ['content' => $content, 'role' => $role];
        return $this;
    }

    /**
     * 
     * @param string $content
     * @param string $role
     * @return \axenox\GenAI\Common\DataQueries\AiQuery
     */
    public function prependMessage(string $content, string $role) : AiQuery
    {
        array_unshift($this->messages, ['content'=> $content,'role'=> $role]);
        return $this;
    }

    /**
     * 
     * @param float $temperature
     * @return \axenox\GenAI\Common\DataQueries\AiQuery
     */
    public function setTemperature(float $temperature) : AiQuery
    {
        $this->temperature = $temperature;
        return $this;
    }

    /**
     * 
     * @return float|null
     */
    public function getTemperature() : ?float
    {
        return $this->temperature;
    }

    public function setConversationUid(string $conversationUid) : AiQuery
    {
        $this->conversationUid = $conversationUid;
        return $this;
    }

    public function getConversationUid() : string
    {
        if ($this->conversationUid === null) {
            $this->conversationUid = UUIDDataType::generateSqlOptimizedUuid();
        }
        return $this->conversationUid;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    /**
     * 
     * @return string
     */
    public function getSystemPrompt() : ?string
    {
        return $this->systemPrompt;
    }

    /**
     * {@inheritDoc}
     * @param string $text
     * @return \axenox\GenAI\Common\DataQueries\AiQuery
     *@see \axenox\GenAI\Interfaces\AiQueryInterface
     *
     */
    public function setSystemPrompt(string $text) : AiQuery
    {
        $this->systemPrompt = $text;
        return $this;
    }


    /**
     * 
     * @param \Psr\Http\Message\RequestInterface $request
     * @return \axenox\GenAI\Common\DataQueries\AiQuery
     */
    public function withRequest(RequestInterface $request) : AiQuery
    {
        $clone = clone $this;
        $clone->request = $request;
        return $clone;
    }

    /**
     * 
     * @return RequestInterface
     */
    public function getRequest() : ?RequestInterface
    {
        return $this->request;
    }

    /**
     * 
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return \axenox\GenAI\Common\DataQueries\AiQuery
     */
    public function withResponse(ResponseInterface $response, HttpResponseAdapterInterface $adapter, float $costs = null) : AiQuery
    {
        $clone = clone $this;
        $clone->response = $response;
        $clone->responseAdapter = $adapter;
        $clone->costs = $costs;
        return $clone;
    }

    public function withWarnings(array $warnings) : AiQuery
    {
        $clone = clone $this;
        $clone->warnings = $warnings;
        return $clone;
    }

    /**
     * 
     * @return bool
     */
    public function hasResponse() : bool
    {
        return $this->response !== null;
    }

    public function getResponse() : ResponseInterface
    {
        if ($this->response === null) {
            throw new DataQueryFailedError($this, 'Cannot access LLM response before the query was sent!');
        }
        return $this->response;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getCosts() : ?float
    {
        return $this->costs;
    }

    public function getWarnings() : array
    {
        return $this->warnings;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        if (null !== $request = $this->getRequest()) {
            $renderer = new HttpMessageDebugger($request, ($this->hasResponse() ? $this->getResponse() : null), 'Data request', 'Data response');
            $debug_widget = $renderer->createDebugWidget($debug_widget);
        }
        
        return $debug_widget;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::getAnswerRaw()
     */
    public function getAnswerRaw() : string
    {
        return $this->responseAdapter->getAnswerRaw();
    }

    /**
     *
     * @see AiQueryInterface::getAnswerMarkdown()
     */
    public function getAnswerMarkdown() : string
    {
        if ($this->hasResponseJsonSchema()) {
            $json = $this->getAnswerJson();

            if ($this->getResponseAnswerPath() !== null) {
                $matches = ArrayDataType::filterJsonPath($json, $this->getResponseAnswerPath());
                return $matches[0] ?? '';
            } else {
                $answer = $json;
            }

            if (!is_string($answer)) {
                $answer = json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            // Codeblock for the AIChat (?)
            return "```json\n" . $answer . "\n```";
        }

        return $this->getAnswerRaw();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::getAnswerJson()
     */
    public function getAnswerJson() : ?array
    {
         try {
             $json = $this->responseAdapter->getAnswerJson();
         } catch (\Throwable $e) {
             throw new DataQueryFailedError($this, 'Expected JSON not received from LLM. ' . $e->getMessage(), null, $e);
         }
         return $json;
    }
    
    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function isFinished() : bool
    {
        return $this->responseAdapter->isFinished();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getTokensInPrompt() : ?int
    {
        return $this->responseAdapter?->getTokensInPrompt();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getTokensInAnswer() : ?int
    {
        return $this->responseAdapter?->getTokensInAnswer();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getUserPrompt() : string
    {
        foreach ($this->messages as $row) {
            if($row['role'] === AiMessageTypeDataType::USER){
                $userPrompt = $row['content'];
                if (!isset($userPrompt) || trim($userPrompt) === "") {
                    $userPrompt = " ";
                }
                return $userPrompt;
            }
                
        }
        throw new DataQueryFailedError($this, 'User message cannot be found');
    }

    public function setResponseJsonSchema(array $value)
    {
        $this->responseJsonSchema = $value;
        return $this;
    }

    public function getResponseJsonSchema() : ?array
    {
        return $this->responseJsonSchema;
    }

    /**
     *
     * @return bool
     */
    protected function hasResponseJsonSchema() : bool
    {
        return $this->responseJsonSchema !== null;
    }

    /**
     * If the AI should respond with JSON, specify the path where to find its answer/comment in that JSON
     *
     * @uxon-property response_answer_path
     * @uxon-type string
     * @uxon-template $.text
     *
     * @see AiQueryInterface::setResponseAnswerPath()
     */
    public function setResponseAnswerPath(string $jsonPath) : AiQueryInterface
    {
        $this->responseAnswerPath = $jsonPath;
        return $this;
    }

    /**
     * Returns the JSONpath to find the text answer in the response JSON if a response_json_schema was provided
     * 
     * @see AiQueryInterface::getResponseAnswerPath()
     */
    public function getResponseAnswerPath() : ?string
    {
        return $this->responseAnswerPath;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getFinishReason() : string
    {
        return $this->responseAdapter->getFinishReason();
    }

    public function addTool(AiToolInterface $tool) : AiQuery
    {
        $this->tools[] = $tool;
        return $this;
    }

    /**
     * @return AiToolInterface[]
     */
    public function getTools() : array
    {
        return $this->tools;
    }
    
    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::hasToolCalls()
     */
    public function hasToolCalls() : bool
    {
        return $this->responseAdapter->hasToolCalls();
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::getToolCalls()
     */
    public function getToolCalls() : array
    {
        return $this->responseAdapter->getToolCalls();
    }
    
    public function setFiles(array $files): AiQuery
    {
        $this->files = $files;
        return $this;
    }
    
    public function hasFiles() : bool
    {
        return !empty($this->files);
    }

    /**
     * @return FileInterface[]
     */
    public function getFiles() : array
    {
        return $this->files;
    }
    
    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getResponseMessage() : array
    {
        return $this->getResponseData();
    }

}