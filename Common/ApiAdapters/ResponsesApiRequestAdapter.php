<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use Psr\Http\Message\ResponseInterface;

class ResponsesApiRequestAdapter implements HttpRequestAdapterInterface
{
    private AiConnectorInterface $connector;
    
    public function __construct(AiConnectorInterface $connector)
    {
        $this->connector = $connector;    
    }
    
    public function buildBody(OpenAiApiDataQuery $query): string
    {
        $json = [
            'model' => $this->connector->getModelName(),
            'messages' => $this->buildJsonMessages($query)
        ];

        if(null !== $schema = $query->getResponseJsonSchema())
        {
            $json['response_format'] = [
                'type'=>'json_schema',
                'json_schema' => [
                    'name' => 'powerUi',
                    'schema'=> $schema,
                    'strict' => true
                ]
            ];
        }
        // if(null !== $tools = $query->getTools())
        // {
        // TODO move conversion of tools to array to the adapter class. The $query is our own understanding
        // of an AI query - it does not depend on the API used!
        $json['tools'] = $this->buildJsonTools($query->getTools());
        // }

        if (null !== $val = $this->connector->getTemperature($query)) {
            $json['temperature'] = $val;
        }

        return json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param AiToolInterface[] $aiTools
     * @return array
     */
    protected function buildJsonTools(array $aiTools) : array
    {
        $tools = [];
        foreach ($aiTools as $tool) {
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
                $tools,
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
        }
        return $tools;
    }
    
    protected function buildJsonMessages(OpenAiApiDataQuery $query) : array
    {
        // TODO add a AiMessageInterface abstraction for every individual message. Currently they are returned
        // here is the completions format already. Other adapter will need to translate this format into theirs.
        $messages = $query->getMessages(true);
        return $messages;
    }

    public function getDryrunResponse(array $requestJson) : ResponseInterface
    {
        $debug = [
            'request' => $requestJson
        ];
        $debugJsonStr = json_encode($debug, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES);

        $contentJson = json_encode($this->dryrunResponse ?? 'Dummy response - AI connector is in dry-run mode',JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


        $json = <<<JSON
      {
    "choices": [
        {
            "content_filter_results": {
                "hate": {
                    "filtered": false,
                    "severity": "safe"
                },
                "self_harm": {
                    "filtered": false,
                    "severity": "safe"
                },
                "sexual": {
                    "filtered": false,
                    "severity": "safe"
                },
                "violence": {
                    "filtered": false,
                    "severity": "safe"
                }
            },
            "finish_reason": "stop",
            "index": 0,
            "logprobs": null,
            "message": {
                "content": {$contentJson},
                "role": "assistant"
            }
        }
    ],
    "created": 1726608704,
    "id": "chatcmpl-A8a5Q1jUobKy5hhtxR9r1acmuNTi9",
    "model": "gpt-35-turbo",
    "object": "chat.completion",
    "prompt_filter_results": [
        {
            "prompt_index": 0,
            "content_filter_results": {
                "hate": {
                    "filtered": false,
                    "severity": "safe"
                },
                "jailbreak": {
                    "filtered": false,
                    "detected": false
                },
                "self_harm": {
                    "filtered": false,
                    "severity": "safe"
                },
                "sexual": {
                    "filtered": false,
                    "severity": "safe"
                },
                "violence": {
                    "filtered": false,
                    "severity": "safe"
                }
            }
        }
    ],
    "system_fingerprint": null,
    "usage": {
        "completion_tokens": 30,
        "prompt_tokens": 3004,
        "total_tokens": 3034
    },
    "debug": {$debugJsonStr}
}  
JSON;



        return new Response(200, [], $json);
    }
}