<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpRequestToolTestInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class CompletionsApiRequestAdapter implements HttpRequestAdapterInterface, HttpRequestToolTestInterface
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

        $json = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // FIXME don't use arrays for JSON schemas because array do not distinguish between `[]` and `{}`.
        // Need to use \stdClass instead when building the request.
        // The below is a workaround for tools with no arguments
        $json = str_replace('"parameters":{"type":"object","properties":[],"required":[]', '"parameters":{"type":"object","properties":{},"required":[]', $json);
        
        return $json;
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

            $description = $tool->getDescription();
            if ($rules = $tool->getRules()) {
                $description .= "\n\n" . $rules;
            }
            
            array_push(
                $tools,
                [
                    "type" => "function",
                    "function" => [
                        "name" => $tool->getName(),
                        "description" => $description,
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
        $schemaJson = $argument->getCustomProperty('json_schema');
        if (is_string($schemaJson) && trim($schemaJson) !== '') {
            $decoded = json_decode($schemaJson, true);
            if (is_array($decoded) && ! empty($decoded)) {
                $schema = $decoded;
            }
        }

        if ($schema === null) {
            $schema = JsonDataType::convertDataTypeToJsonSchemaType($argument->getDataType());
        }

        $description = $argument->getDescription();
        if ($description !== '' && ! array_key_exists('description', $schema)) {
            $schema['description'] = $description;
        }

        return $this->normalizeStrictFunctionSchema($schema);
    }

    /**
     * Ensure nested object schemas satisfy OpenAI strict function schema rules.
     *
     * @param array $schema
     * @return array
     */
    protected function normalizeStrictFunctionSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;

            if (isset($schema['properties']) && is_array($schema['properties'])) {
                foreach ($schema['properties'] as $key => $childSchema) {
                    if (is_array($childSchema)) {
                        $schema['properties'][$key] = $this->normalizeStrictFunctionSchema($childSchema);
                    }
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalizeStrictFunctionSchema($schema['items']);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $variantKey) {
            if (isset($schema[$variantKey]) && is_array($schema[$variantKey])) {
                foreach ($schema[$variantKey] as $idx => $variantSchema) {
                    if (is_array($variantSchema)) {
                        $schema[$variantKey][$idx] = $this->normalizeStrictFunctionSchema($variantSchema);
                    }
                }
            }
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

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::getUserPromptFromRequest()
     */
    public function getUserPromptFromRequest(array $requestJson) : ?string
    {
        $prompt = null;
        foreach (($requestJson['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $prompt = $content;
                continue;
            }
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && in_array(($block['type'] ?? null), ['text', 'input_text'], true)) {
                        $prompt = $block['text'] ?? '';
                    }
                }
            }
        }
        return $prompt;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::getToolNamesFromRequest()
     */
    public function getToolNamesFromRequest(array $requestJson) : array
    {
        $names = [];
        foreach (($requestJson['tools'] ?? []) as $tool) {
            if (null !== $name = ($tool['function']['name'] ?? null)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::getToolArgumentNames()
     */
    public function getToolArgumentNames(array $requestJson, string $toolName) : ?array
    {
        foreach (($requestJson['tools'] ?? []) as $tool) {
            if (($tool['function']['name'] ?? null) !== $toolName) {
                continue;
            }
            $properties = $tool['function']['parameters']['properties'] ?? [];
            return array_keys(is_array($properties) ? $properties : []);
        }
        return null;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::hasToolCallResultsInRequest()
     */
    public function hasToolCallResultsInRequest(array $requestJson) : bool
    {
        foreach (($requestJson['messages'] ?? []) as $message) {
            $role = $message['role'] ?? null;
            if ($role === 'tool' || ($role === 'assistant' && ! empty($message['tool_calls']))) {
                return true;
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::getToolCallResultsFromRequest()
     */
    public function getToolCallResultsFromRequest(array $requestJson) : array
    {
        $results = [];
        foreach (($requestJson['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) === 'tool') {
                $content = $message['content'] ?? '';
                $results[] = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $results;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::buildTextResponse()
     */
    public function buildTextResponse(array $requestJson, string $text) : ResponseInterface
    {
        $json = [
            'id' => 'chatcmpl-tooltest-001',
            'object' => 'chat.completion',
            'created' => 1726608704,
            'model' => $this->connector->getModelName(),
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'logprobs' => null,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $text,
                    ],
                ],
            ],
            'usage' => [
                'completion_tokens' => 0,
                'prompt_tokens' => 0,
                'total_tokens' => 0,
            ],
            'debug' => [
                'request' => $requestJson,
            ],
        ];

        return new Response(200, [], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\HttpRequestToolTestInterface::buildToolCallResponse()
     */
    public function buildToolCallResponse(array $requestJson, array $toolCalls) : ResponseInterface
    {
        $calls = [];
        foreach ($toolCalls as $call) {
            $calls[] = [
                'id' => $call['call_id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $call['name'] ?? '',
                    'arguments' => json_encode(
                        (object) ($call['arguments'] ?? []),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ];
        }

        $json = [
            'id' => 'chatcmpl-tooltest-001',
            'object' => 'chat.completion',
            'created' => 1726608704,
            'model' => $this->connector->getModelName(),
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'tool_calls',
                    'logprobs' => null,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $calls,
                    ],
                ],
            ],
            'usage' => [
                'completion_tokens' => 0,
                'prompt_tokens' => 0,
                'total_tokens' => 0,
            ],
            'debug' => [
                'request' => $requestJson,
            ],
        ];

        return new Response(200, [], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}