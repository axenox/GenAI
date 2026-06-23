<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpRequestToolTestInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\MimeTypeDataType;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Interfaces\Filesystem\FileInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class ResponsesApiRequestAdapter implements HttpRequestAdapterInterface, HttpRequestToolTestInterface
{
    private AiConnectorInterface $connector;

    /**
     * Optional dry-run payload returned as assistant output text.
     *
     * @var mixed
     */
    protected $dryrunResponse = null;

    public function __construct(AiConnectorInterface $connector)
    {
        $this->connector = $connector;
    }

    public function buildBody(OpenAiApiDataQuery $query): string
    {
        $json = [
            'model' => $this->connector->getModelName(),
            'input' => $this->buildJsonInput($query),
        ];

        $instructions = $this->buildInstructions($query);
        if ($instructions !== null && $instructions !== '') {
            $json['instructions'] = $instructions;
        }

        if (null !== $schema = $query->getResponseJsonSchema()) {
            $json['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'powerUi',
                    'schema' => $schema,
                    'strict' => true,
                ]
            ];
        }

        $tools = $this->buildJsonTools($query->getTools());
        if (!empty($tools)) {
            $json['tools'] = $tools;
        }

        if (null !== $val = $this->connector->getTemperature($query)) {
            $json['temperature'] = $val;
        }

        $json = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
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
    protected function buildJsonTools(array $aiTools): array
    {
        $tools = [];

        foreach ($aiTools as $tool) {
            $arguments = [];
            $requiredArgNames = [];

            foreach ($tool->getArguments() as $argument) {
                $argSchema = $this->buildArgumentSchema($argument);

                $arguments[$argument->getName()] = $argSchema;
                $requiredArgNames[] = $argument->getName();
            }

            $description = $tool->getDescription();
            if ($rules = $tool->getRules()) {
                $description .= "\n\n" . $rules;
            }
            
            $tools[] = [
                'type' => 'function',
                'name' => $tool->getName(),
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $arguments,
                    'required' => $requiredArgNames,
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ];
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

    /**
     * Converts old Chat Completions style messages to Responses API input items.
     *
     * Supported mappings:
     * - user/system/developer/assistant text messages
     * - assistant tool_calls -> function_call items
     * - tool messages -> function_call_output items
     *
     * @return array
     */
   

    protected function buildJsonInput(OpenAiApiDataQuery $query): array
    {
        $messages = $query->getMessages(true);
        $input = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ($role === 'system' || $role === 'developer') {
                continue;
            }

            if ($role === 'tool') {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $message['tool_call_id'] ?? $message['call_id'] ?? '',
                    'output' => $this->normalizeMessageText($message['content'] ?? ''),
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                $assistantText = $this->normalizeMessageText($message['content'] ?? '');
                if ($assistantText !== '') {
                    $input[] = [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => $assistantText,
                            ]
                        ],
                    ];
                }

                foreach ($message['tool_calls'] as $toolCall) {
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'arguments' => $toolCall['function']['arguments'] ?? '{}',
                    ];
                }

                continue;
            }

            $content = $this->normalizeContentBlocks($role, $message['content'] ?? '');

            $input[] = [
                'type' => 'message',
                'role' => $role,
                'content' => $content,
            ];
        }

        foreach ($query->getFiles() as $file) {
            $input[] = [
                'type' => 'message',
                'role' => 'user',
                'content' => [
                    $this->makeInputFileBlock($file),
                ],
            ];
        }

        return $input;
    }

    protected function makeInputFileBlock(FileInterface $file): array
    {
        $fileInfo = $file->getFileInfo();

        $filename = method_exists($fileInfo, 'getFilename')
            ? $fileInfo->getFilename()
            : 'upload.bin';

        $mimeType = method_exists($fileInfo, 'getMimeType') && $fileInfo->getMimeType()
            ? $fileInfo->getMimeType()
            : $this->guessMimeTypeFromFilename($filename);

        $base64 = base64_encode($file->read());
        $dataUrl = 'data:' . $mimeType . ';base64,' . $base64;

        if (str_starts_with($mimeType, 'image/')) {
            return [
                'type' => 'input_image',
                'image_url' => $dataUrl,
            ];
        }

        return [
            'type' => 'input_file',
            'filename' => $filename,
            'file_data' => $dataUrl,
        ];
    }

    protected function guessMimeTypeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return MimeTypeDataType::guessMimeTypeOfExtension(
            $ext,
            'application/octet-stream'
        );
    }

    /**
     * Builds Responses API instructions from old system/developer messages.
     */
    protected function buildInstructions(OpenAiApiDataQuery $query): ?string
    {
        $messages = $query->getMessages(true);
        $instructions = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if ($role === 'system' || $role === 'developer') {
                $text = $this->normalizeMessageText($message['content'] ?? '');
                if ($text !== '') {
                    $instructions[] = $text;
                }
            }
        }

        if (empty($instructions)) {
            return null;
        }

        return implode("\n\n", $instructions);
    }

    /**
     * Normalizes content into Responses API content blocks.
     */
    protected function normalizeContentBlocks(string $role, $content): array
    {
        if (is_array($content)) {
            $blocks = [];

            foreach ($content as $item) {
                if (!is_array($item)) {
                    $blocks[] = $this->makeTextBlock($role, (string) $item);
                    continue;
                }

                $type = $item['type'] ?? 'text';

                // Already in Responses format
                if (in_array($type, ['input_text', 'output_text', 'input_image', 'input_file'], true)) {
                    $blocks[] = $item;
                    continue;
                }

                // Old Chat Completions text block
                if ($type === 'text') {
                    $blocks[] = $this->makeTextBlock($role, (string) ($item['text'] ?? ''));
                    continue;
                }

                // Old Chat Completions image block
                if ($type === 'image_url') {
                    $imageUrl = $item['image_url']['url'] ?? null;
                    if ($imageUrl !== null) {
                        $blocks[] = [
                            'type' => 'input_image',
                            'image_url' => $imageUrl,
                        ];
                    }
                    continue;
                }

                // Fallback: stringify unknown block
                $blocks[] = $this->makeTextBlock(
                    $role,
                    json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            return !empty($blocks) ? $blocks : [$this->makeTextBlock($role, '')];
        }

        return [$this->makeTextBlock($role, $this->normalizeMessageText($content))];
    }

    /**
     * Extracts a plain text representation from message content.
     */
    protected function normalizeMessageText($content): string
    {
        if ($content === null) {
            return '';
        }

        if (is_string($content)) {
            return $content;
        }

        if (is_scalar($content)) {
            return (string) $content;
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $item) {
                if (is_string($item) || is_scalar($item)) {
                    $parts[] = (string) $item;
                    continue;
                }

                if (!is_array($item)) {
                    continue;
                }

                $type = $item['type'] ?? 'text';

                if (in_array($type, ['text', 'input_text', 'output_text'], true)) {
                    $parts[] = (string) ($item['text'] ?? '');
                    continue;
                }

                if ($type === 'image_url') {
                    $url = $item['image_url']['url'] ?? null;
                    if ($url !== null) {
                        $parts[] = '[image:' . $url . ']';
                    }
                    continue;
                }

                $parts[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            return trim(implode("\n", array_filter($parts, static fn($v) => $v !== null && $v !== '')));
        }

        return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function makeTextBlock(string $role, string $text): array
    {
        return [
            'type' => $role === 'assistant' ? 'output_text' : 'input_text',
            'text' => $text,
        ];
    }

    public function getDryrunResponse(array $requestJson): ResponseInterface
    {
        $debug = [
            'request' => $requestJson,
        ];

        $debugJsonStr = json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $contentJson = json_encode(
            $this->dryrunResponse ?? 'Dummy response - AI connector is in dry-run mode',
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $json = <<<JSON
{
    "id": "resp_dryrun_001",
    "object": "response",
    "created_at": 1726608704,
    "status": "completed",
    "error": null,
    "incomplete_details": null,
    "instructions": null,
    "model": "{$this->connector->getModelName()}",
    "output": [
        {
            "type": "message",
            "id": "msg_dryrun_001",
            "status": "completed",
            "role": "assistant",
            "content": [
                {
                    "type": "output_text",
                    "text": {$contentJson},
                    "annotations": []
                }
            ]
        }
    ],
    "parallel_tool_calls": true,
    "tool_choice": "auto",
    "tools": [],
    "temperature": 1,
    "text": {
        "format": {
            "type": "text"
        }
    },
    "usage": {
        "input_tokens": 3004,
        "output_tokens": 30,
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
        foreach (($requestJson['input'] ?? []) as $item) {
            if (($item['type'] ?? 'message') !== 'message') {
                continue;
            }
            if (($item['role'] ?? null) !== 'user') {
                continue;
            }
            $content = $item['content'] ?? '';
            if (is_string($content)) {
                $prompt = $content;
                continue;
            }
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && in_array(($block['type'] ?? null), ['input_text', 'text'], true)) {
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
            if (null !== $name = ($tool['name'] ?? null)) {
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
            if (($tool['name'] ?? null) !== $toolName) {
                continue;
            }
            $properties = $tool['parameters']['properties'] ?? [];
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
        foreach (($requestJson['input'] ?? []) as $item) {
            if (in_array(($item['type'] ?? null), ['function_call_output', 'function_call'], true)) {
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
        foreach (($requestJson['input'] ?? []) as $item) {
            if (($item['type'] ?? null) === 'function_call_output') {
                $results[] = $this->normalizeMessageText($item['output'] ?? '');
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
            'id' => 'resp_tooltest_001',
            'object' => 'response',
            'created_at' => 1726608704,
            'status' => 'completed',
            'error' => null,
            'model' => $this->connector->getModelName(),
            'output' => [
                [
                    'type' => 'message',
                    'id' => 'msg_tooltest_001',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $text,
                            'annotations' => [],
                        ],
                    ],
                ],
            ],
            'parallel_tool_calls' => true,
            'tool_choice' => 'auto',
            'tools' => $requestJson['tools'] ?? [],
            'temperature' => 0,
            'text' => [
                'format' => [
                    'type' => 'text',
                ],
            ],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
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
        $output = [];
        foreach ($toolCalls as $call) {
            $output[] = [
                'type' => 'function_call',
                'id' => 'fc_' . ($call['call_id'] ?? ''),
                'call_id' => $call['call_id'] ?? '',
                'name' => $call['name'] ?? '',
                'arguments' => json_encode(
                    (object) ($call['arguments'] ?? []),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'status' => 'completed',
            ];
        }

        $json = [
            'id' => 'resp_tooltest_001',
            'object' => 'response',
            'created_at' => 1726608704,
            'status' => 'completed',
            'error' => null,
            'model' => $this->connector->getModelName(),
            'output' => $output,
            'parallel_tool_calls' => true,
            'tool_choice' => 'auto',
            'tools' => $requestJson['tools'] ?? [],
            'temperature' => 0,
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
            ],
            'debug' => [
                'request' => $requestJson,
            ],
        ];

        return new Response(200, [], json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}