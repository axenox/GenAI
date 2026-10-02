<?php

$packageRoot = dirname(__DIR__);
$autoloadCandidates = [
    $packageRoot . '/vendor/autoload.php',
    dirname($packageRoot, 2) . '/autoload.php'
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

if (! class_exists(\PHPUnit\Framework\TestCase::class)) {
    throw new \RuntimeException('Composer autoloader with PHPUnit could not be found.');
}

if (! class_exists(\axenox\GenAI\AI\Agents\McpServer::class)) {
    spl_autoload_register(static function (string $class) use ($packageRoot) : void {
        $prefix = 'axenox\\GenAI\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $file = $packageRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}