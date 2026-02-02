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
use axenox\GenAI\Common\AiToolCall;

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

    private $costs = null;
    
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

    public function appendToolMessages(bool $existingCall, string $toolResponse, string $callId, array $requestMessage) : OpenAiApiDataQuery
    {
        if (!$existingCall){
            $this->messages[] = $requestMessage;
        }
        
        $this->messages[] = [
            'tool_call_id' => $callId,
            'content' => $toolResponse, 
            'role' => AiMessageTypeDataType::TOOL
        ];

        return $this;
    }

    public function clearPreviousToolCalls()
    {
        $this->messages = array_filter($this->messages, function ($message) {
            return isset($message['role']) && $message['role'] === AiMessageTypeDataType::USER;
        });
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
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
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
    public function withResponse(ResponseInterface $response, float $costs = null) : OpenAiApiDataQuery
    {
        $clone = clone $this;
        $clone->response = $response;
        $clone->responseData = null;
        $clone->costs = $costs;
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

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getCostPerMTokens() : ?float
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
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
    
    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function isFinished() : bool
    {
        return $this->getResponseData()['choices'][0]['finish_reason'] === 'stop';
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getTokensInPrompt() : int
    {
        return $this->getResponseData()['usage']['prompt_tokens'];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getTokensInAnswer() : int
    {
        return $this->getResponseData()['usage']['completion_tokens'];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getUserPrompt() : string
    {
        foreach ($this->messages as $row) {
            if($row['role'] === AiMessageTypeDataType::USER)
                return $row['content'];
        }
        throw new DataQueryFailedError($this, 'User message cannot be found');
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getSequenceNumber() : int
    {
        $storedSheet = $this->getConversationData();
        $cnt = $storedSheet->countRows();
        return $cnt;
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

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getFinishReason() : string
    {
        return $this->getResponseData()['choices'][0]['finish_reason'];
    }

    public function addTool(AiToolInterface $tool) : OpenAiApiDataQuery 
    {
        $arguments = [];
        $requiredArgNames = [];
        foreach($tool->getArguments() as $argument)
        {
            $argSchema = JsonDataType::convertDataTypeToJsonSchemaType($argument->getDataType());
            $argSchema['description'] = $argument->getDescription();
            $arguments[$argument->getName()] = $argSchema;
            $requiredArgNames[] = $argument->getName();
        }
        
        array_push(
            $this->tools,  
            [
                "type" => "function",
                "function" => [
                    "name" => $tool->getName(),
                    "description" => $tool->getDescription(),
                    "parameters" => [
                        "type" => "object",
                        "properties" => $arguments,
                        "required" => $requiredArgNames,
                        "additionalProperties" => false
                    ],
                    "strict" => true
                ]
            ]
        );
        
        return $this;
    }

    public function getTools() : ?array
    {
        return $this->tools;
    }
    
    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::hasToolCalls()
     */
    public function hasToolCalls() : bool
    {
        return $this->getResponseData()['choices'][0]['finish_reason'] === 'tool_calls';
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface::getToolCalls()
     */
    public function getToolCalls() : array
    {
        $result = [];
        foreach($this->getResponseData()['choices'][0]['message']['tool_calls'] as $call) {
            $function = $call['function'];
            $result[] = new AiToolCall($function['name'], $call['id'], json_decode($function['arguments'], true));
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiQueryInterface
     */
    public function getResponseMessage() : array
    {
        return $this->getResponseData()['choices'][0]['message'];
    }

}