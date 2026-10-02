<?php
$packageRoot = dirname(__DIR__, 2);
require dirname($packageRoot, 2) . DIRECTORY_SEPARATOR . 'autoload.php';

if (! class_exists(\axenox\GenAI\AI\Agents\McpServer::class)) {
    spl_autoload_register(static function (string $class) use ($packageRoot) : void {
        $prefix = 'axenox\\GenAI\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = $packageRoot
            . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)))
            . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

$taskHandler = \axenox\GenAI\Interfaces\AiTaskHandlerInterface::class;
$agent = \axenox\GenAI\Interfaces\AiAgentInterface::class;
$mcpServer = \axenox\GenAI\AI\Agents\McpServer::class;
$genericAssistant = \axenox\GenAI\AI\Agents\GenericAssistant::class;
$genericSkill = \axenox\GenAI\AI\Skills\GenericSkill::class;

if (! is_subclass_of($mcpServer, $taskHandler)) {
    throw new \RuntimeException('McpServer must implement AiTaskHandlerInterface.');
}
if (is_subclass_of($mcpServer, $agent)) {
    throw new \RuntimeException('McpServer must not implement the chat-specific AiAgentInterface.');
}
if (! is_subclass_of($genericAssistant, $agent)) {
    throw new \RuntimeException('GenericAssistant must continue to implement AiAgentInterface.');
}

$skillConstructor = new \ReflectionMethod($genericSkill, '__construct');
$skillParameters = $skillConstructor->getParameters();
if ((string) $skillParameters[0]->getType() !== $taskHandler) {
    throw new \RuntimeException('GenericSkill must accept AiTaskHandlerInterface as its owner.');
}
if (! $skillParameters[1]->allowsNull()) {
    throw new \RuntimeException('GenericSkill must allow tool-only construction without an AI prompt.');
}

fwrite(STDOUT, "AI task handler contract test passed.\n");
