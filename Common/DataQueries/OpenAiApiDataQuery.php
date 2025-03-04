<?php
namespace axenox\GenAI\Common\DataQueries;

use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\DataQueries\AbstractDataQuery;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugWidgetRenderer;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Widgets\DebugMessage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;

/**
 * Data query in OpenAI style
 * 
 * Inspired by OpenAI chat completion API: https://platform.openai.com/docs/api-reference/chat/create
 */
class OpenAiApiDataQuery extends AbstractDataQuery implements AiQueryInterface
{

    private $workbench;

    private $messages = null;

    private $systemPrompt = null;

    private $temperature = null;

    private $conversationUid = null;
    
    private $conversationData = null;

    private $request = null;

    private $response = null;

    private $responseData = null;

    private $costPerMTokens = null;
    
    private $jsonSchema = null;

    private $tools = [];

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
        if ($includeConversation === true) {
            foreach ($this->getConversationData()->getRows() as $row) {
                if(!in_array($row['ROLE'] ,[AiMessageTypeDataType::SYSTEM, AiMessageTypeDataType::TOOL, AiMessageTypeDataType::TOOLCALLING]))
                    $messages[] = ['content' => $row['MESSAGE'], 'role' => $row['ROLE']];
            }
        }
        $messages = array_merge($messages, $this->messages);
        return $messages;
    }

    /**
     * 
     * @param string $content
     * @param string $role
     * @return \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery
     */
    public function appendMessage(string $content, string $role = AiMessageTypeDataType::USER) : OpenAiApiDataQuery
    {
        $this->messages[] = ['content' => $content, 'role' => $role];
        return $this;
    }

    public function appendToolMessages(string $toolResponse, string $callId, array $requestMessage) : OpenAiApiDataQuery
    {
        $this->messages[] = $requestMessage;
        $this->messages[] = [
            'content' => $toolResponse, 
            'role' => AiMessageTypeDataType::TOOL, 
            'tool_call_id' => $callId
        ];
        return $this;
    }

    /**
     * 
     * @param string $content
     * @param string $role
     * @return \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery
     */
    public function prependMessage(string $content, string $role) : OpenAiApiDataQuery
    {
        array_unshift($this->messages, ['content'=> $content,'role'=> $role]);
        return $this;
    }

    /**
     * 
     * @param float $temperature
     * @return \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery
     */
    public function setTemperature(float $temperature) : OpenAiApiDataQuery
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

    public function setConversationUid(string $conversationUid) : OpenAiApiDataQuery
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
    protected function getConversationData() : DataSheetInterface
    {
        if ($this->conversationData === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
            $sheet->getColumns()->addMultiple([
                'MESSAGE',
                'ROLE'
            ]);
            $sheet->getFilters()->addConditionFromString('AI_CONVERSATION', $this->getConversationUid());
            $sheet->getSorters()->addFromString('SEQUENCE_NUMBER','ASC');
            $sheet->dataRead();
            $this->conversationData = $sheet;
        }
        return $this->conversationData;
    }

    /**
     * 
     * @return string
     */
    public function getSystemPrompt() : ?string
    {
        return $this->systemPrompt;
    }

    /**
     * 
     * @param string $text
     * @return \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery
     */
    public function setSystemPrompt(string $text) : OpenAiApiDataQuery
    {
        $this->systemPrompt = $text;
        return $this;
    }


    /**
     * 
     * @param \Psr\Http\Message\RequestInterface $request
     * @return \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery
     */
    public function withRequest(RequestInterface $request) : OpenAiApiDataQuery
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
     * @return \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery
     */
    public function withResponse(ResponseInterface $response, float $costPerMTokens = null) : OpenAiApiDataQuery
    {
        $clone = clone $this;
        $clone->response = $response;
        $clone->costPerMTokens = $costPerMTokens;
        return $clone;
    }

    /**
     * 
     * @return array
     */
    public function getResponseData() : array
    {
        if ($this->responseData === null) {
            try {
                $json = JsonDataType::decodeJson($this->getResponse()->getBody()->__toString(), true);
                $this->responseData = $json;
            } catch (\Throwable $e) {
                throw new DataQueryFailedError($this, 'Cannot parse LLM response. ' . $e->getMessage(), null, $e);
            }
        }
        return $this->responseData;
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

    public function getCostPerMTokens() : ?float
    {
        return $this->costPerMTokens;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        if (null !== $request = $this->getRequest()) {
            $renderer = new HttpMessageDebugWidgetRenderer($request, ($this->hasResponse() ? $this->getResponse() : null), 'Data request', 'Data response');
            $debug_widget = $renderer->createDebugWidget($debug_widget);
        }
        
        return $debug_widget;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::getFullAnswer()
     */
    public function getFullAnswer() : string
    {
        $fullAnswer = $this->getResponseData()['choices'][0]['message']['content'];
        return $fullAnswer;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::getAnswerJson()
     */
    public function getAnswerJson() : ?array
    {
        return json_decode($this->getFullAnswer(), true);
    }

    public function isFinished() : bool
    {
        return $this->getResponseData()['choices'][0]['finish_reason'] === 'stop';
    }

    public function getTokensInPrompt() : int
    {
        return $this->getResponseData()['usage']['prompt_tokens'];
    }

    public function getTokensInAnswer() : int
    {
        return $this->getResponseData()['usage']['completion_tokens'];
    }

    public function getUserPrompt() : string
    {
        foreach ($this->messages as $row) {
            if($row['role'] === AiMessageTypeDataType::USER)
                return $row['content'];
        }
        throw new DataQueryFailedError($this, 'User message cannot be found');
    }

    public function getSequenceNumber() : int
    {
        $this-> getConversationData();
        return $this->conversationData-> countRows() + 1;
    }

    public function setResponseJsonSchema(array $value)
    {
        $this->jsonSchema = $value;
        return $this;
    }

    public function getResponseJsonSchema() : ?array
    {
        return $this->jsonSchema;
    }

    public function getFinishReason() : string
    {
        return $this->getResponseData()['choices'][0]['finish_reason'];
    }

    public function addTool(AiToolInterface $tool) : OpenAiApiDataQuery 
    {
        $arguments = [];
        $requireds = [];
        foreach($tool->getArguments() as $argument)
        {
            $arguments[$argument->getName()] = [  
                "type" => strtolower($argument->getDataType()->getName()),
                "description" => $argument->getDescription()
            ];
            $requireds[] = $argument->getName();
        }
        
        array_push($this->tools,  [
                                    "type" => "function",
                                    "function" => [
                                        "name" => $tool->getName(),
                                        "description" => $tool->getDescription(),
                                        "parameters" => [
                                            "type" => "object",
                                            "properties" => $arguments,
                                            "required" => $requireds,
                                            "additionalProperties" => false
                                        ],
                                        "strict" => true
                                    ]
                                ]);
        
        return $this;
    }

    public function getTools() : ?array
    {
        return $this->tools;
    }
    
    public function hasToolCalls() : bool
    {
        return $this->getResponseData()['choices'][0]['finish_reason'] === 'tool_calls';
    }

    /**
     * gets the called tools in the message for tool calling
     * @return array
     */
    public function requestedToolCalls() : array
    {
        return $this->getResponseData()['choices'][0]['message']['tool_calls'];
    }

    /**
     * gets the message for tool calling
     * @return array
     */
    public function getResponseMessage() : array
    {
        return $this->getResponseData()['choices'][0]['message'];
    }

    public function getRawAnswer() : string
    {
        return $this->getResponseData()['choices'][0]['message']['content'];
    }
}
