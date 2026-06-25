<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\AI\Traits\FileAccessToolTrait;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\RegularExpressionDataType;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Run a CLI command with configurable allow/block filters.
 * 
 * The execution folder is passed via the `folder` argument and is validated
 * using the shared file access settings `base_path`, `use_vendor_folder_as_base`
 * and `allowed_paths`.
 * 
 * Use `allowed_commands` and `blocked_commands` to control which commands are executable.
 * Each list can contain exact command names/strings and regular expressions.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 * {
 *     "instructions": "You may run safe read-only PHP checks.",
 *     "tools": {
 *         "run_cli": {
 *             "alias": "axenox.GenAI.CommandLineTool",
 *             "description": "Run whitelisted CLI commands",
 *             "allowed_commands": [
 *                 "/^php\\s+-l\\s+.+$/",
 *                 "php -v",
 *                 "composer"
 *             ],
 *             "blocked_commands": [
 *                 "/\\brm\\b/i",
 *                 "/\\bdel\\b/i"
 *             ]
 *         }
 *     }
 * }
 * 
 * ```
 */
class CommandLineTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_COMMAND = 'command';

    public const ARG_FOLDER = 'folder';

    /** @var string[] */
    private array $allowedCommands = [];

    /** @var string[] */
    private array $blockedCommands = [];

    /** @var float */
    private float $commandTimeout = 60.0;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $command = trim((string) ($arguments[0] ?? ''));
        $folder = trim((string) ($arguments[1] ?? '.'));
        if ($command === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing CLI command.');
        }

        $this->checkCommandAllowed($command, $prompt);

        $basePath = $this->getBasePathAbsolute();
        $cwd = $this->getPathAbsolute($folder, $basePath, $prompt);
        if (! is_dir($cwd)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid folder: target directory does not exist.');
        }

        try {
            $output = '';
            $generator = CliCommandRunner::runCliCommand($command, [], $this->commandTimeout, $cwd, true);
            foreach ($generator as $chunk) {
                $output .= (string) $chunk;
            }
            $output = trim($output);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'CLI command failed: ' . $e->getMessage(), null, $e);
        }

        if ($output === '') {
            $output = '(no output)';
        }
        
        $markdown = MarkdownDataType::escapeCodeBlock($output, 'text');

        return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_COMMAND)
                ->setDescription('Complete CLI command to execute, e.g. `php -l AI/Tools/CommandLineTool.php`.')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_FOLDER)
                ->setDescription('Path to the folder, where the command ist to be executed - absolute or relative to the vendor folder'),
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }

    /**
     * Timeout in seconds for CLI command execution.
     *
     * @uxon-property command_timeout
     * @uxon-type number
     * @uxon-default 60
     *
     * @param float $value
     * @return CommandLineTool
     */
    protected function setCommandTimeout(float $value): CommandLineTool
    {
        $this->commandTimeout = max(0.0, $value);
        return $this;
    }

    /**
     * Allowed command patterns.
     *
     * Entries can be exact command strings (for example `php` or `php -v`) or regex patterns
     * enclosed in delimiters (for example `/^php\\s+-l\\s+.+$/`). If this list is empty, all
     * commands are allowed except blocked ones.
     *
     * @uxon-property allowed_commands
     * @uxon-type array
     * @uxon-default []
     * @uxon-template ["/^php\\s+-l\\s+.+$/", "php -v"]
     *
     * @param string[] $patterns
     * @return CommandLineTool
     */
    protected function setAllowedCommands(array $patterns): CommandLineTool
    {
        $this->allowedCommands = $this->sanitizePatterns($patterns);
        return $this;
    }

    /**
     * Blocked command patterns.
     *
     * Entries can be exact command strings or regex patterns enclosed in delimiters.
     * This list has higher priority than `allowed_commands`.
     *
     * @uxon-property blocked_commands
     * @uxon-type array
     * @uxon-default []
     * @uxon-template ["/\\brm\\b/i", "/\\bdel\\b/i"]
     *
     * @param string[] $patterns
     * @return CommandLineTool
     */
    protected function setBlockedCommands(array $patterns): CommandLineTool
    {
        $this->blockedCommands = $this->sanitizePatterns($patterns);
        return $this;
    }

    /**
     * Validates command against blocked/allowed patterns.
     *
     * @param string $command
     * @param AiPromptInterface $prompt
     * @return void
     */
    protected function checkCommandAllowed(string $command, AiPromptInterface $prompt): void
    {
        if ($this->checkCommandMatchesPatterns($command, $this->blockedCommands)) {
            throw new AiToolRuntimeError($this, $prompt, 'Command blocked by tool configuration.');
        }

        if (! empty($this->allowedCommands) && ! $this->checkCommandMatchesPatterns($command, $this->allowedCommands)) {
            throw new AiToolRuntimeError($this, $prompt, 'Command is not in allowed_commands.');
        }
    }

    /**
     * Returns TRUE if command matches at least one configured pattern.
     *
     * For plain strings, both full command and executable name are matched.
     *
     * @param string $command
     * @param string[] $patterns
     * @return bool
     */
    protected function checkCommandMatchesPatterns(string $command, array $patterns): bool
    {
        $commandName = $this->getCommandName($command);
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if (RegularExpressionDataType::isRegex($pattern)) {
                $result = @preg_match($pattern, $command);
                if ($result === 1) {
                    return true;
                }
                continue;
            }

            if ($command === $pattern || $commandName === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes and validates configured command patterns.
     *
     * @param array $patterns
     * @return string[]
     */
    protected function sanitizePatterns(array $patterns): array
    {
        $sanitized = [];
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if (RegularExpressionDataType::isRegex($pattern) && @preg_match($pattern, '') === false) {
                continue;
            }

            $sanitized[] = $pattern;
        }
        return array_values(array_unique($sanitized));
    }

    /**
     * Returns the executable part of a CLI command string.
     *
     * @param string $command
     * @return string
     */
    protected function getCommandName(string $command): string
    {
        $parts = preg_split('/\s+/', trim($command), 2);
        return (string) ($parts[0] ?? '');
    }
}