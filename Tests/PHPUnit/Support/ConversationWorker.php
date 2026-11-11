<?php
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php';

use axenox\GenAI\Common\Mcp\McpConversationResolver;
use axenox\GenAI\Common\Mcp\McpWorkbenchScope;
use axenox\GenAI\DataTypes\AiConversationTypeDataType;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataSheetFactory;

[$script, $mode, $selector, $identity] = $argv;
$workbench = McpWorkbenchScope::start();
try {
    $handler = AiFactory::createTaskHandlerFromString($workbench, $selector);
    if ($mode === 'write') {
        $conversation = AiFactory::createConversation($handler, $identity, ['TYPE' => AiConversationTypeDataType::MCP]);
    }
    if (isset($argv[4])) {
        touch($argv[5]);
        $deadline = microtime(true) + 10;
        while (!is_file($argv[4])) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Concurrent fixture barrier timed out.');
            }
            usleep(1000);
        }
    }
    if ($mode === 'create') {
        $conversation = AiFactory::createConversation($handler, null, [
            'TYPE' => AiConversationTypeDataType::MCP, 'DEVMODE' => true, 'TITLE' => 'Concurrent fixture'
        ]);
        $output = ['uid' => $conversation->getConversationId()];
    } elseif ($mode === 'group') {
        $conversation = (new McpConversationResolver())->resolve($handler, [
            'session_id' => $identity, 'native_session_id' => $identity, 'chat_id' => $identity,
            'received_at' => microtime(true), 'started_on' => date('Y-m-d H:i:s'), 'tool_name' => 'fixture'
        ]);
        $output = ['uid' => $conversation->getConversationId()];
    } elseif ($mode === 'cleanup') {
        foreach (['AI_MESSAGE', 'AI_CONVERSATION'] as $alias) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.' . $alias);
            $sheet->getFilters()->addConditionFromString($alias === 'AI_CONVERSATION' ? 'UID' : 'AI_CONVERSATION', $identity, ComparatorDataType::EQUALS);
            $sheet->dataDelete();
        }
        $output = ['cleaned' => true];
    } else {
        $conversation = $conversation ?? AiFactory::createConversation($handler, $identity, ['TYPE' => AiConversationTypeDataType::MCP]);
        if ($mode === 'write') {
            for ($index = 0; $index < 4; $index++) {
                $conversation->saveUserPrompt('worker ' . getmypid() . ' message ' . $index);
            }
            $output = ['written' => count($conversation->getGeneratedMessages())];
        } elseif ($mode === 'inspect') {
            $output = ['sequences' => array_map(static fn($message) => $message->getSequenceNumber(), $conversation->getMessages())];
        } else {
            throw new \InvalidArgumentException('Unknown conversation fixture operation.');
        }
    }
} finally {
    McpWorkbenchScope::stop($workbench);
}
echo json_encode($output, JSON_THROW_ON_ERROR);
