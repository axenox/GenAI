<?php
namespace axenox\GenAI\Common\DataQueries;

use axenox\GenAI\DataTypes\AiFinishReasonDataType;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\DataQueries\AbstractDataQuery;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugger;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Widgets\DebugMessage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A single API-agnostic exchange with an LLM carrying the selected conversation messages.
 * 
 * The agent fills the query with messages selected from the conversation and request settings,
 * the connector sends it and adds the response. Everything the agent needs from the response -
 * answer, tool calls, finish reason - is derived from the generic response message created by
 * the response adapter, so there is only one source of truth.
 * 
 * Queries are constructed by agents, not configured by users. They transport messages, 
 * temperature, response JSONschema and other things between agent and LLM.
 * 
 * Technically AiQuery is a UXON prototype, but this currently not used anywhere - just inherited
 * from AbstractDataQuery.
 * 
 * @author Andrej Kabachnik
 */
class AiQuery extends AbstractDataQuery implements AiQueryInterface
{
    private array $messages = [];

    private $temperature = null;

    private ?AiConversationInterface $conversation = null;

    private ?RequestInterface $request = null;

    private ?ResponseInterface $response = null;
    
    private ?HttpResponseAdapterInterface $responseAdapter = null;
    
    private ?AiMessageInterface $responseMessage = null;

    private $costs = null;
    
    private array $warnings = [];
    
    private ?array $responseJsonSchema = null;
    
    private ?string $responseAnswerPath = null;

    private array $tools = [];

    /**
     * Accepts the messages selected for the model context without copying or loading them.
     * 
     * @param WorkbenchInterface $workbench
     * @param AiConversationInterface|null $conversation
     * @param AiMessageInterface[] $messages
     * @return void
     */
    public function __construct(WorkbenchInterface $workbench, ?AiConversationInterface $conversation = null, array $messages = [])
    {
        $this->conversation = $conversation;
        $this->messages = $messages;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getMessages()
     */
    public function getMessages() : array
    {
        return $this->messages;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::withMessages()
     */
    public function withMessages(array $messages) : AiQuery
    {
        $clone = clone $this;
        $clone->messages = $messages;
        return $clone;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getConversation()
     */
    public function getConversation() : AiConversationInterface
    {
        if ($this->conversation === null) {
            throw new \LogicException('This standalone query is not bound to a conversation.');
        }
        return $this->conversation;
    }

    /**
     * Adds a message to the end of the model context
     * 
     * @param AiMessageInterface $message
     * @return AiQuery
     */
    public function appendMessage(AiMessageInterface $message) : AiQuery
    {
        $this->messages[] = $message;
        return $this;
    }

    /**
     * Adds a message to the beginning of the model context
     * 
     * @param AiMessageInterface $message
     * @return AiQuery
     */
    public function prependMessage(AiMessageInterface $message) : AiQuery
    {
        array_unshift($this->messages, $message);
        return $this;
    }

    /**
     * Control response variability when supported by the selected model.
     * 
     * @uxon-property temperature
     * @uxon-type number
     * 
     * @param float $temperature
     * @return AiQuery
     */
    public function setTemperature(float $temperature) : AiQuery
    {
        $this->temperature = $temperature;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getTemperature()
     */
    public function getTemperature() : ?float
    {
        return $this->temperature;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::withRequest()
     */
    public function withRequest(RequestInterface $request) : AiQuery
    {
        $clone = clone $this;
        $clone->request = $request;
        return $clone;
    }

    /**
     * Returns the HTTP request sent to the provider if available
     * 
     * @return RequestInterface|null
     */
    public function getRequest() : ?RequestInterface
    {
        return $this->request;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::withResponse()
     */
    public function withResponse(ResponseInterface $response, HttpResponseAdapterInterface $adapter, float $costs = null) : AiQuery
    {
        $clone = clone $this;
        $clone->response = $response;
        $clone->responseAdapter = $adapter;
        $clone->responseMessage = null;
        $clone->costs = $costs;
        return $clone;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::withWarnings()
     */
    public function withWarnings(array $warnings) : AiQuery
    {
        $clone = clone $this;
        foreach ($warnings as $warning) {
            $clone->addWarning($warning);
        }
        return $clone;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::addWarning()
     */
    public function addWarning(\Throwable|string $warning) : AiQuery
    {
        $this->warnings[] = $warning;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getWarnings()
     */
    public function getWarnings() : array
    {
        $warnings = $this->warnings;
        if ($this->responseAdapter !== null) {
            foreach ($this->responseAdapter->getWarnings() as $warning) {
                $warnings[] = $warning;
            }
        }
        return $warnings;
    }

    /**
     * Returns TRUE if an HTTP response was received.
     *
     * @return bool
     */
    public function hasResponse() : bool
    {
        return $this->response !== null;
    }

    /**
     * Returns the HTTP response received from the provider.
     *
     * @throws DataQueryFailedError if there is no response yet
     * @return ResponseInterface
     */
    public function getResponse() : ResponseInterface
    {
        if ($this->response === null) {
            throw new DataQueryFailedError($this, 'Cannot access LLM response before the query was sent!');
        }
        return $this->response;
    }

    /**
     * Returns the response adapter or throws an error if there is no response yet
     * 
     * @throws DataQueryFailedError
     * @return HttpResponseAdapterInterface
     */
    protected function getResponseAdapter() : HttpResponseAdapterInterface
    {
        if ($this->responseAdapter === null) {
            throw new DataQueryFailedError($this, 'Cannot access LLM response before the query was sent!');
        }
        return $this->responseAdapter;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getResponseMessage()
     */
    public function getResponseMessage() : AiMessageInterface
    {
        // Cache the message as the agent may enrich its tool calls (e.g. with tool aliases) before saving
        if ($this->responseMessage === null) {
            $this->responseMessage = $this->getResponseAdapter()->getResponseMessage();
        }
        return $this->responseMessage;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getCosts()
     */
    public function getCosts() : ?float
    {
        return $this->costs;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        $request = $this->getRequest();
        $response = $this->hasResponse() ? $this->getResponse() : null;
        if ($request !== null || $response !== null) {
            $renderer = new HttpMessageDebugger($request, $response, 'Data request', 'Data response');
            $debug_widget = $renderer->createDebugWidget($debug_widget);
        }
        
        return $debug_widget;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getAnswerRaw()
     */
    public function getAnswerRaw() : string
    {
        return $this->getResponseMessage()->getText();
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getAnswerMarkdown()
     */
    public function getAnswerMarkdown() : string
    {
        if ($this->hasResponseJsonSchema()) {
            $json = $this->getAnswerJson();

            if ($this->getResponseAnswerPath() !== null) {
                $matches = ArrayDataType::filterJsonPath($json ?? [], $this->getResponseAnswerPath());
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
     * @see AiQueryInterface::getAnswerJson()
     */
    public function getAnswerJson() : ?array
    {
        $text = $this->getAnswerRaw();
        if (trim($text) === '') {
            return null;
        }
        try {
            $json = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new DataQueryFailedError($this, 'Expected JSON not received from LLM. ' . $e->getMessage(), null, $e);
        }
        return is_array($json) ? $json : null;
    }
    
    /**
     * {@inheritDoc}
     * @see AiQueryInterface::isFinished()
     */
    public function isFinished() : bool
    {
        return $this->getFinishReason() === AiFinishReasonDataType::COMPLETED;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getFinishReason()
     */
    public function getFinishReason() : string
    {
        return $this->getResponseAdapter()->getFinishReason();
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getTokensInPrompt()
     */
    public function getTokensInPrompt() : int
    {
        return $this->getResponseAdapter()->getTokensInPrompt();
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getTokensInAnswer()
     */
    public function getTokensInAnswer() : int
    {
        return $this->getResponseAdapter()->getTokensInAnswer();
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getModelName()
     */
    public function getModelName() : ?string
    {
        return $this->getResponseAdapter()->getModelName();
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getSequenceNumber()
     */
    public function getSequenceNumber() : int
    {
        return count($this->messages);
    }

    /**
     * Sets the runtime answer schema from a PHP array, outside query UXON import.
     * 
     * @param array<string,mixed> $value JSON schema already normalized by the caller.
     * @return AiQuery
     */
    public function setResponseJsonSchema(array $value)
    {
        $this->responseJsonSchema = $value;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getResponseJsonSchema()
     */
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
     * 
     * @param string $jsonPath
     * @return AiQueryInterface
     */
    public function setResponseAnswerPath(string $jsonPath) : AiQueryInterface
    {
        $this->responseAnswerPath = $jsonPath;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getResponseAnswerPath()
     */
    public function getResponseAnswerPath() : ?string
    {
        return $this->responseAnswerPath;
    }

    /**
     * Adds a configured tool available to the next model request.
     * 
     * @param AiToolInterface $tool
     * @return AiQuery
     */
    public function addTool(AiToolInterface $tool) : AiQuery
    {
        $this->tools[] = $tool;
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getTools()
     */
    public function getTools() : array
    {
        return $this->tools;
    }
    
    /**
     * {@inheritDoc}
     * @see AiQueryInterface::hasToolCalls()
     */
    public function hasToolCalls() : bool
    {
        return $this->getToolCalls() !== [];
    }

    /**
     * {@inheritDoc}
     * @see AiQueryInterface::getToolCalls()
     */
    public function getToolCalls() : array
    {
        $message = $this->getResponseMessage();
        return $message instanceof AiToolCallMessageInterface ? $message->getCalls() : [];
    }
}
