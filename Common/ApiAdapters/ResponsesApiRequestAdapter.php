<?php
namespace axenox\GenAI\Common\ApiAdapters;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\Filesystem\FileInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class ResponsesApiRequestAdapter implements HttpRequestAdapterInterface
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

        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                $argSchema = JsonDataType::convertDataTypeToJsonSchemaType($argument->getDataType());
                $argSchema['description'] = $argument->getDescription();

                $arguments[$argument->getName()] = $argSchema;
                $requiredArgNames[] = $argument->getName();
            }

            $tools[] = [
                'type' => 'function',
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
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

        return [
            'type' => 'input_file',
            'filename' => $filename,
            'file_data' => 'data:' . $mimeType . ';base64,' . $base64,
        ];
    }

    protected function guessMimeTypeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'pdf':
                return 'application/pdf';
            case 'txt':
                return 'text/plain';
            case 'csv':
                return 'text/csv';
            case 'json':
                return 'application/json';
            default:
                return 'application/octet-stream';
        }
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
}