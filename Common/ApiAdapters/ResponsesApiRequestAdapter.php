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
 * Builds OpenAI Responses API requests from generic conversation messages.
 * 
 * - Instructions become top-level `instructions`, input `user` messages, output `assistant` messages.
 * - Tool call messages become `function_call` items followed by `function_call_output` items.
 * - Image attachments of input messages are sent as images. PDFs, text and code files, Office and
 * OpenDocument documents, presentations and spreadsheets are sent as files. Other attachments and attachments
 * of output messages are replaced by short notes and a warning is added to the query.
 * - Output items kept by `ResponsesApiResponseAdapter` as adapter data (incl. reasoning items) are
 * replayed only if they were produced by the same model and still match the generic message.
 * 
 * @author Andrej Kabachnik
 */
class ResponsesApiRequestAdapter implements HttpRequestAdapterInterface, HttpRequestToolTestInterface
{
    use ConversationApiAdapterTrait;

    const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    /**
     * File types accepted as `input_file` - see https://developers.openai.com/api/docs/guides/file-inputs
     * 
     * PDFs are read as text and page images, spreadsheets get a special spreadsheet treatment, all other
     * types are read as text only.
     */
    const SUPPORTED_FILE_TYPES = [
        // PDF
        'application/pdf',
        // Text and code - e.g. .txt, .md, .html, .xml, .csv, source code
        'text/*',
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-javascript',
        'application/typescript',
        'application/x-php',
        'application/x-httpd-php',
        'application/x-python',
        'application/x-sh',
        'application/sql',
        'application/yaml',
        'application/x-yaml',
        // Rich documents - .doc, .docx, .rtf, .odt
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/rtf',
        'application/vnd.oasis.opendocument.text',
        // Presentations - .ppt, .pptx
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Spreadsheets - .xls, .xlsx (.csv is text/csv)
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];

    private AiConnectorInterface $connector;

    /**
     * Optional dry-run payload returned as assistant output text.
     *
     * @var mixed
     */
    protected $dryrunResponse = null;

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
        [$instructions, $dialog] = $this->groupContextMessages($query);
        $json = [
            'model' => $this->connector->getModelName(),
            'input' => $this->buildJsonInput($query, $dialog),
        ];

        $instructions = $this->buildInstructions($instructions);
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
     * @return array<array-key,mixed>
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

    /**
     * Build Argument Schema.
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

        /*
        if ($argument->hasDefaultValue()) {
            $defaultInstruction = 'Use the default value '
                . JsonDataType::encodeJson($argument->getDefaultValue())
                . ' when no value is specified.';
            $schema['description'] = trim(($schema['description'] ?? '') . "\n\n" . $defaultInstruction);
        }*/

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
     * Builds ordered Responses input items from the dialog messages of the query.
     * 
     * @param AiQueryInterface $query
     * @param AiMessageInterface[] $dialog Input and output messages selected by groupContextMessages()
     * @return array<array-key,mixed>
     */
    protected function buildJsonInput(AiQueryInterface $query, array $dialog): array
    {
        $output = [];
        $idState = [];
        $appendixSeen = [];
        foreach ($dialog as $message) {
            if ($message->isInput()) {
                $output[] = ['type' => 'message', 'role' => 'user', 'content' => $this->buildUserContent($query, $message)];
                continue;
            }
            // Output messages
            $calls = $this->getContextCalls($message);
            $nativeItems = $this->getMatchingAdapterItems($message, $idState);
            if ($nativeItems !== null) {
                array_push($output, ...$nativeItems);
            } else {
                $text = $message->getText();
                foreach ($message->getAttachments() as $file) {
                    $query->addWarning('File "' . $file->getFilename() . '" generated by the model cannot be sent back to this API - replaced by a note.');
                    $text = $this->appendNote($text, $this->buildAttachmentNote($file));
                }
                if ($text !== '' || $calls === []) {
                    $output[] = ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];
                }
                foreach ($calls as $call) {
                    $output[] = [
                        'type' => 'function_call', 
                        'call_id' => $this->mapCallId($message, $call->getCallId(), $idState),
                        'name' => $call->getToolName(), 
                        'arguments' => $this->buildToolArgumentsJson($call)
                    ];
                }
            }
            if ($calls === []) {
                continue;
            }
            /** @var AiToolCallMessageInterface $message */
            $results = $message->getToolResults();
            foreach ($calls as $call) {
                $output[] = [
                    'type' => 'function_call_output', 
                    'call_id' => $this->mapCallId($message, $call->getCallId(), $idState),
                    'output' => $this->buildToolResultText($results[$call->getCallId()], $appendixSeen)
                ];
            }
        }
        return $output;
    }

    /**
     * Builds top-level instructions from all instruction messages.
     * 
     * @param AiMessageInterface[] $instructions
     * @return string|null
     */
    protected function buildInstructions(array $instructions): ?string
    {
        $parts = [];
        foreach ($instructions as $message) {
            $parts[] = $message->getText();
        }
        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Builds the content parts of a user message - attachments, that cannot be read, become notes
     *
     * @param AiQueryInterface $query
     * @param AiMessageInterface $message
     * @return array<int,array<string,mixed>>
     */
    private function buildUserContent(AiQueryInterface $query, AiMessageInterface $message): array
    {
        $text = $message->getText();
        $files = [];
        foreach ($message->getAttachments() as $file) {
            $contents = $this->readAttachment($query, $file, array_merge(self::SUPPORTED_IMAGE_TYPES, self::SUPPORTED_FILE_TYPES));
            if ($contents === null) {
                $text = $this->appendNote($text, $this->buildAttachmentNote($file));
                continue;
            }
            $mimeType = $this->getAttachmentMimeType($file);
            $url = 'data:' . $mimeType . ';base64,' . base64_encode($contents);
            $files[] = $this->isMimeTypeSupported($mimeType, self::SUPPORTED_IMAGE_TYPES)
                ? ['type' => 'input_image', 'image_url' => $url]
                : ['type' => 'input_file', 'filename' => $file->getFilename(), 'file_data' => $url];
        }
        return array_merge([['type' => 'input_text', 'text' => $text]], $files);
    }

    /**
     * Returns the native output items kept by the response adapter if they can be replayed - NULL otherwise
     * 
     * The generic message is authoritative: the items are only used if they were produced by the current
     * model and still match the text and the tool calls of the message. Call IDs are mapped the same way
     * as for generic items.
     * 
     * @param AiMessageInterface $message
     * @param array<string,mixed> $idState
     * @return array<int,mixed>|null
     */
    private function getMatchingAdapterItems(AiMessageInterface $message, array &$idState): ?array
    {
        $data = $message->getAdapterData(ResponsesApiResponseAdapter::ADAPTER_DATA_KEY);
        if ($data === null || ! is_array($data['output'] ?? null) || $message->getAttachments() !== []) {
            return null;
        }
        if (! $this->isCurrentModel($data['model'] ?? null)) {
            return null;
        }
        $items = [];
        foreach ($data['output'] as $item) {
            if (! $item instanceof \stdClass && ! is_array($item)) {
                return null;
            }
            $items[] = (object) $item;
        }
        if (ResponsesApiResponseAdapter::getText($items) !== $message->getText()) {
            return null;
        }
        $calls = $this->getContextCalls($message);
        $nativeCalls = [];
        foreach ($items as $item) {
            switch ($item->type ?? null) {
                case 'function_call':
                    $nativeCalls[] = $item;
                    break;
                case 'message':
                case 'reasoning':
                    break;
                default:
                    // Items, this adapter cannot replay safely
                    return null;
            }
        }
        if (count($nativeCalls) !== count($calls)) {
            return null;
        }
        // A reasoning item must be followed by its output item - never replay a trailing reasoning item
        if ($items === [] || (end($items)->type ?? null) === 'reasoning') {
            return null;
        }
        foreach ($calls as $i => $call) {
            $item = $nativeCalls[$i];
            try {
                $arguments = json_decode((string) ($item->arguments ?? ''), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return null;
            }
            if (($item->call_id ?? null) !== $call->getCallId()
                || ($item->name ?? null) !== $call->getToolName()
                || json_encode($arguments) !== json_encode($this->buildToolArgumentsObject($call))) {
                return null;
            }
        }
        $replay = [];
        foreach ($items as $item) {
            $item = clone $item;
            if (($item->type ?? null) === 'function_call') {
                $item->call_id = $this->mapCallId($message, $item->call_id, $idState);
            }
            $replay[] = $item;
        }
        return $replay;
    }

    /**
     * Returns TRUE if the given model name (as reported in a response) belongs to the model of the connector
     * 
     * Providers often report versioned names (e.g. `gpt-4o-2024-08-06` for `gpt-4o`).
     * 
     * @param mixed $model
     * @return bool
     */
    private function isCurrentModel(mixed $model): bool
    {
        $current = $this->connector->getModelName();
        if (! is_string($model) || $model === '') {
            return false;
        }
        return $model === $current || str_starts_with($model, $current . '-');
    }

    /**
     * Extracts a plain text representation from message content.
     * 
     * @param mixed $content
     * @return string
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

    /**
     * {@inheritDoc}
     * @see HttpRequestAdapterInterface::getDryrunResponse()
     */
    public function getDryrunResponse(array $requestJson, string $response): ResponseInterface
    {
        $debug = [
            'request' => $requestJson,
        ];

        $debugJsonStr = json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contentJson = json_encode($response,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
      "input_tokens": 0,
      "input_tokens_details": {
        "cached_tokens": 0
      },
      "output_tokens": 0,
      "output_tokens_details": {
        "reasoning_tokens": 0
      },
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
