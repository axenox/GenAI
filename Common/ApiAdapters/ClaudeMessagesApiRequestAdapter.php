<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\DataConnectors\ClaudeConnector;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpRequestToolTestInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class ClaudeMessagesApiRequestAdapter implements HttpRequestAdapterInterface, HttpRequestToolTestInterface
{
    private ClaudeConnector $connector;

    public function __construct(ClaudeConnector $connector)
    {
        $this->connector = $connector;
    }

    public function buildBody(OpenAiApiDataQuery $query): string
    {
        $json = [
            'model' => $this->connector->getModelName(),
            'max_tokens' => $this->connector->getMaxTokens(),
            'messages' => $this->buildMessages($query),
        ];

        $system = $this->buildSystemPrompt($query);
        if ($system !== null && $system !== '') {
            $json['system'] = $system;
        }

        $tools = $this->buildJsonTools($query->getTools());
        if (! empty($tools)) {
            $json['tools'] = $tools;
        }

        if (null !== $val = $this->connector->getTemperature($query)) {
            $json['temperature'] = $val;
        }

        if (null !== $schema = $query->getResponseJsonSchema()) {
            $json['output_config'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ];
        }

        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function buildSystemPrompt(OpenAiApiDataQuery $query): ?string
    {
        $messages = $query->getMessages(true);
        $instructions = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if ($role !== 'system' && $role !== 'developer') {
                continue;
            }

            $text = $this->normalizeMessageText($message['content'] ?? '');
            if ($text !== '') {
                $instructions[] = $text;
            }
        }

        if (empty($instructions)) {
            return null;
        }

        return implode("\n\n", $instructions);
    }

    protected function buildMessages(OpenAiApiDataQuery $query): array
    {
        $messages = [];

        foreach ($query->getMessages(true) as $message) {
            $role = $message['role'] ?? 'user';

            if ($role === 'system' || $role === 'developer') {
                continue;
            }

            if ($role === 'tool') {
                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $message['tool_call_id'] ?? $message['call_id'] ?? '',
                            'content' => $this->normalizeMessageText($message['content'] ?? ''),
                        ],
                    ],
                ];
                continue;
            }

            if ($role === 'assistant' && ! empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                $content = [];

                $assistantText = $this->normalizeMessageText($message['content'] ?? '');
                if ($assistantText !== '') {
                    $content[] = [
                        'type' => 'text',
                        'text' => $assistantText,
                    ];
                }

                foreach ($message['tool_calls'] as $toolCall) {
                    $arguments = $toolCall['function']['arguments'] ?? '{}';
                    $arguments = $this->normalizeToolUseInput($arguments);

                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'input' => $arguments,
                    ];
                }

                if (empty($content)) {
                    $content[] = ['type' => 'text', 'text' => ''];
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];
                continue;
            }

            $messages[] = [
                'role' => ($role === 'assistant' ? 'assistant' : 'user'),
                'content' => $this->normalizeContentBlocks($message['content'] ?? ''),
            ];
        }

        if ($query->hasFiles()) {
            foreach ($query->getFiles() as $file) {
                $fileInfo = $file->getFileInfo();
                $filename = method_exists($fileInfo, 'getFilename') ? $fileInfo->getFilename() : 'upload.bin';
                $mimeType = method_exists($fileInfo, 'getMimeType') && $fileInfo->getMimeType()
                    ? $fileInfo->getMimeType()
                    : 'application/octet-stream';

                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => base64_encode($file->read()),
                            ],
                            'title' => $filename,
                        ],
                    ],
                ];
            }
        }

        return $messages;
    }

    /**
     * Anthropic expects tool_use.input to always be a JSON object.
     * Empty or list-like payloads are converted to objects for compatibility.
     *
     * @param mixed $arguments
     * @return array|object
     */
    protected function normalizeToolUseInput($arguments)
    {
        if (is_string($arguments)) {
            $decoded = json_decode($arguments);
            $arguments = $decoded ?? new \stdClass();
        }

        if ($arguments instanceof \stdClass) {
            return $arguments;
        }

        if (is_array($arguments)) {
            if (empty($arguments)) {
                return new \stdClass();
            }

            $keys = array_keys($arguments);
            $isSequential = ($keys === range(0, count($arguments) - 1));
            if ($isSequential) {
                return (object) $arguments;
            }

            return $arguments;
        }

        if (is_object($arguments)) {
            return $arguments;
        }

        return new \stdClass();
    }

    /**
     * @param AiToolInterface[] $aiTools
     * @return array
     */
    protected function buildJsonTools(array $aiTools): array
    {
        $tools = [];

        foreach ($aiTools as $tool) {
            $arguments = [];
            $requiredArgNames = [];

            foreach ($tool->getArguments() as $argument) {
                $arguments[$argument->getName()] = $this->buildArgumentSchema($argument);
                $requiredArgNames[] = $argument->getName();
            }

            $properties = empty($arguments) ? new \stdClass() : $arguments;

            $description = $tool->getDescription();
            if ($rules = $tool->getRules()) {
                $description .= "\n\n" . $rules;
            }

            $toolSchema = [
                'name' => $tool->getName(),
                'description' => $description,
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'additionalProperties' => false,
                ],
            ];

            if (! empty($requiredArgNames)) {
                $toolSchema['input_schema']['required'] = $requiredArgNames;
            }

            $tools[] = $toolSchema;
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

    protected function normalizeStrictFunctionSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['additionalProperties'] = false;

            if (isset($schema['properties']) && is_array($schema['properties'])) {
                if (empty($schema['properties'])) {
                    $schema['properties'] = new \stdClass();
                } else {
                    foreach ($schema['properties'] as $key => $childSchema) {
                        if (is_array($childSchema)) {
                            $schema['properties'][$key] = $this->normalizeStrictFunctionSchema($childSchema);
                        }
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

    protected function normalizeContentBlocks($content): array
    {
        if (! is_array($content)) {
            return [
                [
                    'type' => 'text',
                    'text' => $this->normalizeMessageText($content),
                ],
            ];
        }

        $blocks = [];
        foreach ($content as $item) {
            if (! is_array($item)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => (string) $item,
                ];
                continue;
            }

            $type = $item['type'] ?? 'text';

            if (in_array($type, ['text', 'input_text', 'output_text'], true)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => (string) ($item['text'] ?? ''),
                ];
                continue;
            }

            if ($type === 'image_url') {
                $url = $item['image_url']['url'] ?? null;
                if ($url !== null) {
                    $blocks[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $url,
                        ],
                    ];
                }
                continue;
            }

            $blocks[] = [
                'type' => 'text',
                'text' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        if (empty($blocks)) {
            $blocks[] = ['type' => 'text', 'text' => ''];
        }

        return $blocks;
    }

    protected function normalizeMessageText($content): string
    {
        if ($content === null) {
            return '';
        }

        if (is_string($content) || is_scalar($content)) {
            return (string) $content;
        }

        if (! is_array($content)) {
            return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item) || is_scalar($item)) {
                $parts[] = (string) $item;
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? 'text';
            if (in_array($type, ['text', 'input_text', 'output_text'], true)) {
                $parts[] = (string) ($item['text'] ?? '');
                continue;
            }

            $parts[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim(implode("\n", array_filter($parts, static fn ($v) => $v !== null && $v !== '')));
    }

    public function getDryrunResponse(array $requestJson, string $response) : ResponseInterface
    {
        $debug = [
            'request' => $requestJson,
        ];

        $json = [
            'id' => 'msg_dryrun_001',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->connector->getModelName(),
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $response,
                ],
            ],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
            ],
            'debug' => $debug,
        ];

        return new Response(200, [], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

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

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (($block['type'] ?? null) === 'text') {
                    $prompt = $block['text'] ?? '';
                }
            }
        }

        return $prompt;
    }

    public function getToolNamesFromRequest(array $requestJson) : array
    {
        $names = [];
        foreach (($requestJson['tools'] ?? []) as $tool) {
            if (null !== $name = ($tool['name'] ?? null)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    public function getToolArgumentNames(array $requestJson, string $toolName) : ?array
    {
        foreach (($requestJson['tools'] ?? []) as $tool) {
            if (($tool['name'] ?? null) !== $toolName) {
                continue;
            }

            $properties = $tool['input_schema']['properties'] ?? [];
            return array_keys(is_array($properties) ? $properties : []);
        }

        return null;
    }

    public function hasToolCallResultsInRequest(array $requestJson) : bool
    {
        foreach (($requestJson['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            if (! is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_result') {
                    return true;
                }
            }
        }

        return false;
    }

    public function getToolCallResultsFromRequest(array $requestJson) : array
    {
        $results = [];

        foreach (($requestJson['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            if (! is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) !== 'tool_result') {
                    continue;
                }

                $content = $block['content'] ?? '';
                if (is_string($content)) {
                    $results[] = $content;
                } else {
                    $results[] = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $results;
    }

    public function buildTextResponse(array $requestJson, string $text) : ResponseInterface
    {
        $json = [
            'id' => 'msg_tooltest_001',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->connector->getModelName(),
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
            ],
            'debug' => [
                'request' => $requestJson,
            ],
        ];

        return new Response(200, [], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function buildToolCallResponse(array $requestJson, array $toolCalls) : ResponseInterface
    {
        $content = [];
        foreach ($toolCalls as $call) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $call['call_id'] ?? '',
                'name' => $call['name'] ?? '',
                'input' => $call['arguments'] ?? [],
            ];
        }

        $json = [
            'id' => 'msg_tooltest_001',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->connector->getModelName(),
            'stop_reason' => 'tool_use',
            'stop_sequence' => null,
            'content' => $content,
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
            ],
            'debug' => [
                'request' => $requestJson,
            ],
        ];

        return new Response(200, [], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}