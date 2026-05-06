<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class CompletionsApiRequestAdapter implements HttpRequestAdapterInterface
{
    private AiConnectorInterface $connector;
    
    public function __construct(AiConnectorInterface $connector)
    {
        $this->connector = $connector;    
    }

    public function buildBody(OpenAiApiDataQuery $query): string
    {
        if ($query->hasFiles()) {
            throw new \LogicException(
                'Files are not supported by this Type of AI. Please use a newer version'
            );
        }
        
        //TODO Überlegen ob man Textdateien in die Message mit einbauen könnte. Das würde zumindest die Möglichkeit bieten, Dateien zu übergeben, auch wenn sie nicht direkt von der KI verarbeitet werden können. Oder man stellt im AiChat ein das Files nicht verwendet werden dürfen

        $json = [
            'model' => $this->connector->getModelName(),
            'messages' => $this->buildJsonMessages($query)
        ];

        if (null !== $schema = $query->getResponseJsonSchema()) {
            $json['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'powerUi',
                    'schema' => $schema,
                    'strict' => true
                ]
            ];
        }

        $json['tools'] = $this->buildJsonTools($query->getTools());

        if (null !== $val = $this->connector->getTemperature($query)) {
            $json['temperature'] = $val;
        }

        return json_encode($json);
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
                $argSchema = $this->buildArgumentSchema($argument);
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

    protected function buildArgumentSchema(ServiceParameterInterface $argument): array
    {
        $schema = null;
        if (method_exists($argument, 'getCustomProperty')) {
            $schemaJson = $argument->getCustomProperty('json_schema');
            if (is_string($schemaJson) && trim($schemaJson) !== '') {
                $decoded = json_decode($schemaJson, true);
                if (is_array($decoded) && ! empty($decoded)) {
                    $schema = $decoded;
                }
            }
        }

        if ($schema === null) {
            $schema = JsonDataType::convertDataTypeToJsonSchemaType($argument->getDataType());
        }

        $description = $argument->getDescription();
        if ($description !== '' && ! array_key_exists('description', $schema)) {
            $schema['description'] = $description;
        }

        return $schema;
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