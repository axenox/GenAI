<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpRequestToolTestInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds OpenAI Chat Completions requests from generic conversation messages.
 * 
 * - Instructions become `system` messages, input `user` messages, output `assistant` messages.
 * - Tool call messages become `assistant` messages with `tool_calls` followed by `tool` messages with results.
 * - Image attachments of input messages are sent as images. Other attachments and attachments of
 * output messages are replaced by short notes and a warning is added to the query.
 * 
 * @author Andrej Kabachnik
 */
class CompletionsApiRequestAdapter implements HttpRequestAdapterInterface, HttpRequestToolTestInterface
{
    use ConversationApiAdapterTrait;

    const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private AiConnectorInterface $connector;
    
    /**
     * Creates the connector-specific request adapter.
     * 
     * @param AiConnectorInterface $connector
     * @return void
     */
    public function __construct(AiConnectorInterface $connector)
    {
        $this->connector = $connector;    
    }

    /**
     * {@inheritDoc}
     * @see HttpRequestAdapterInterface::buildBody()
     */
    public function buildBody(AiQueryInterface $query): string
    {
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

        $tools = $this->buildJsonTools($query->getTools());
        if ($tools !== []) {
            $json['tools'] = $tools;
        }

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
     * @return array<array-key,mixed>
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

    /**
     * Builds a strict JSON schema for a configured tool argument.
     * 
     * @param ServiceParameterInterface $argument
     * @return array<array-key,mixed>
     */
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

        if (! $argument->isRequired()) {
            $schema = ['anyOf' => [$schema, ['type' => 'null']]];
        }

        return $this->normalizeStrictFunctionSchema($schema);
    }

    /**
     * Ensure nested object schemas satisfy OpenAI strict function schema rules.
     * 
     * @param array<array-key,mixed> $schema
     * @return array<array-key,mixed>
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
    
    /**
     * Builds native messages from the generic messages of the query.
     * 
     * @param AiQueryInterface $query
     * @return array<array-key,mixed>
     */
    protected function buildJsonMessages(AiQueryInterface $query) : array
    {
        [$instructions, $dialog] = $this->groupContextMessages($query);
        $output = [];
        foreach ($instructions as $message) {
            $output[] = ['role' => 'system', 'content' => $message->getText()];
        }
        $idState = [];
        $appendixSeen = [];
        foreach ($dialog as $message) {
            if ($message->isInput()) {
                $output[] = ['role' => 'user', 'content' => $this->buildUserContent($query, $message)];
                continue;
            }
            // Output messages
            $text = $message->getText();
            foreach ($message->getAttachments() as $file) {
                $query->addWarning('File "' . $file->getFilename() . '" generated by the model cannot be sent back to this API - replaced by a note.');
                $text = $this->appendNote($text, $this->buildAttachmentNote($file));
            }
            $native = ['role' => 'assistant', 'content' => $text];
            $calls = $this->getContextCalls($message);
            if ($calls === []) {
                $output[] = $native;
                continue;
            }
            if ($text === '') {
                $native['content'] = null;
            }
            foreach ($calls as $call) {
                $native['tool_calls'][] = [
                    'type' => 'function', 
                    'id' => $this->mapCallId($message, $call->getCallId(), $idState),
                    'function' => [
                        'name' => $call->getToolName(),
                        'arguments' => $this->buildToolArgumentsJson($call)
                    ]
                ];
            }
            $output[] = $native;
            /** @var AiToolCallMessageInterface $message */
            $results = $message->getToolResults();
            foreach ($calls as $call) {
                $output[] = [
                    'role' => 'tool', 
                    'tool_call_id' => $this->mapCallId($message, $call->getCallId(), $idState),
                    'content' => $this->buildToolResultText($results[$call->getCallId()], $appendixSeen)
                ];
            }
        }
        return $output;
    }

    /**
     * Builds the content blocks of a user message - attachments, that cannot be sent, become notes
     *
     * @param AiQueryInterface $query
     * @param AiMessageInterface $message
     * @return array<int,array<string,mixed>>
     */
    private function buildUserContent(AiQueryInterface $query, AiMessageInterface $message) : array
    {
        $text = $message->getText();
        $images = [];
        foreach ($message->getAttachments() as $file) {
            $contents = $this->readAttachment($query, $file, self::SUPPORTED_IMAGE_TYPES);
            if ($contents === null) {
                $text = $this->appendNote($text, $this->buildAttachmentNote($file));
                continue;
            }
            $images[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $this->getAttachmentMimeType($file) . ';base64,' . base64_encode($contents)]];
        }
        return array_merge([['type' => 'text', 'text' => $text]], $images);
    }

    /**
     * {@inheritDoc}
     * @see HttpRequestAdapterInterface::getDryrunResponse()
     */
    public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface
    {
        $debug = [
            'request' => $requestJson
        ];
        $debugJsonStr = json_encode($debug, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES);

        $contentJson = json_encode($response,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


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
        "completion_tokens": 0,
        "prompt_tokens": 0,
        "total_tokens": 0
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