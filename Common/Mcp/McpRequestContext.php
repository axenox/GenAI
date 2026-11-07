<?php
namespace axenox\GenAI\Common\Mcp;

use Mcp\Schema\Request\CallToolRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Builds explicit, SDK-visible MCP conversation metadata without request services.
 */
final class McpRequestContext
{
    public static function create(
        CallToolRequest $request,
        string $endpoint,
        string $connectionId,
        string $nativeSessionId
    ) : array {
        $meta = $request->getMeta() ?? [];
        $modern = isset($meta['io.modelcontextprotocol/protocolVersion'])
            && is_string($meta['io.modelcontextprotocol/protocolVersion'])
            && strcmp($meta['io.modelcontextprotocol/protocolVersion'], '2026-07-28') >= 0;
        $context = [
            'type' => 'mcp',
            'devmode' => false,
            'topic_id' => $endpoint,
            'session_id' => $modern ? $connectionId : $nativeSessionId,
            'connection_id' => $connectionId,
            'native_session_id' => $nativeSessionId,
            'tool_name' => $request->name,
            'request_id' => $request->getId(),
            'argument_fidelity' => 'sdk-visible',
            'sdk_arguments' => $request->arguments
        ];
        $chatId = $meta['axenox.GenAI/chat_id'] ?? null;
        if (is_string($chatId) && trim($chatId) !== '') {
            $context['chat_id'] = $chatId;
        }
        return $context;
    }

    public static function newId() : string
    {
        return Uuid::v4()->toRfc4122();
    }

    public static function validateGrouping(mixed $timeout, mixed $scope) : void
    {
        if (!is_int($timeout) || $timeout < 1) {
            throw new \InvalidArgumentException('conversation_inactivity_timeout_seconds must be a positive integer.');
        }
        if (!is_string($scope) || !in_array($scope, ['session', 'user'], true)) {
            throw new \InvalidArgumentException('conversation_grouping_scope must be session or user.');
        }
    }
}
