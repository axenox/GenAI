<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Runs explicitly enabled Git operations in a validated repository folder.
 * 
 * By default, this tool only permits read-only operations for inspecting current
 * changes and commit history. Use `allowed_commands` to select other predefined
 * Git operations without writing regular expressions.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 * {
 *     "tools": {
 *         "git": {
 *             "alias": "axenox.GenAI.GitTool",
 *             "description": "Inspect current changes and repository history",
 *             "allowed_commands": ["status", "diff", "log", "show", "blame"]
 *         }
 *     }
 * }
 * 
 * ```
 */
class GitTool extends CommandLineTool
{
    private const DEFAULT_COMMANDS = [
        'status',
        'diff',
        'log',
        'show',
        'blame',
        'grep',
    ];

    private const COMMANDS = [
        'status' => 'status',
        'diff' => 'diff',
        'log' => 'log',
        'show' => 'show',
        'blame' => 'blame',
        'grep' => 'grep',
        'rev-list' => 'rev-list',
        'rev-parse' => 'rev-parse',
        'ls-files' => 'ls-files',
        'ls-tree' => 'ls-tree',
        'shortlog' => 'shortlog',
        'describe' => 'describe',
        'stage' => 'add',
        'commit' => 'commit',
        'switch' => 'switch',
        'checkout' => 'checkout',
        'restore' => 'restore',
        'pull' => 'pull',
        'push' => 'push',
        'fetch' => 'fetch',
        'merge' => 'merge',
        'rebase' => 'rebase',
        'reset' => 'reset',
        'revert' => 'revert',
        'cherry-pick' => 'cherry-pick',
        'stash' => 'stash',
        'tag' => 'tag',
        'branch' => 'branch',
        'clean' => 'clean',
        'rm' => 'rm',
        'mv' => 'mv',
    ];

    private bool $allowedCommandsInitialized = false;

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
                ->setDescription('Complete Git command to execute, e.g. `git diff -- AI/Tools/GitTool.php` or `git log -10 --oneline`.')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_FOLDER)
                ->setDescription('Path to the Git repository, absolute or relative to the vendor folder.'),
        ];
    }
    
    public function getRules(): ?string
    {
        $commands = implode(', ', $this->getAllowedCommands());
        return (parent::getRules() ?? '') . <<<MD

Allowed commands are: $commands.
MD;
    }

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $arguments[0] = $this->normalizeGitCommand((string) ($arguments[0] ?? ''));
        return parent::invoke($agent, $prompt, $arguments);
    }
    
    protected function getAllowedCommands() : array
    {
        if (! $this->allowedCommandsInitialized) {
            $this->setAllowedCommands(self::DEFAULT_COMMANDS);
        }
        return parent::getAllowedCommands();
    }

    /**
     * Allowed Git operations.
     *
     * Every entry must be one of the predefined operation names. The tool translates
     * the names into strict command patterns before passing them to `CommandLineTool`.
     * Mutating operations such as `stage`, `commit`, `switch`, `pull`, and `push` are
     * available for explicit opt-in but are not enabled by default.
     *
     * @uxon-property allowed_commands
     * @uxon-type [status,diff,log,show,blame,grep,rev-list,rev-parse,ls-files,ls-tree,shortlog,describe,stage,commit,switch,checkout,restore,pull,push,fetch,merge,rebase,reset,revert,cherry-pick,stash,tag,branch,clean,rm,mv][]
     * @uxon-default ["status", "diff", "log", "show", "blame", "grep"]
     * @uxon-template ["status", "diff", "log", "show", "blame", "grep"]
     *
     * @param string[] $commands
     * @return GitTool
     */
    protected function setAllowedCommands(array $commands): GitTool
    {
        $patterns = [];
        foreach ($commands as $command) {
            $command = strtolower(trim((string) $command));
            if (! isset(self::COMMANDS[$command])) {
                throw new \InvalidArgumentException(
                    'Invalid Git operation "' . $command . '". Allowed values: ' . implode(', ', array_keys(self::COMMANDS))
                );
            }
            $patterns[] = $this->buildCommandPattern(self::COMMANDS[$command]);
        }

        $this->allowedCommandsInitialized = true;
        parent::setAllowedCommands($patterns ?: ['/a^/']);
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\AI\Tools\CommandLineTool::checkCommandAllowed()
     */
    protected function checkCommandAllowed(string $command, AiPromptInterface $prompt): void
    {
        if (! $this->allowedCommandsInitialized) {
            $this->setAllowedCommands(self::DEFAULT_COMMANDS);
        }
        $command = $this->normalizeGitCommand($command);
        parent::checkCommandAllowed($command, $prompt);
    }

    /**
     * Normalizes a Git subcommand into canonical form.
     *
     * The tool accepts either the bare operation name (for example `status`) or a
     * complete command starting with `git` (for example `git status`). Both forms
     * are translated to the canonical `git <subcommand>` form before validation and
     * execution.
     *
     * @param string $command
     * @return string
     */
    private function normalizeGitCommand(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            return $command;
        }

        if (preg_match('/^git\b/i', $command) === 1) {
            return trim($command);
        }

        $parts = preg_split('/\s+/', $command, 2);
        $subCommand = strtolower((string) ($parts[0] ?? ''));
        if (isset(self::COMMANDS[$subCommand])) {
            return 'git ' . $command;
        }

        return $command;
    }

    /**
     * Builds a regex for one Git subcommand without permitting shell operators.
     *
     * @param string $command
     * @return string
     */
    private function buildCommandPattern(string $command): string
    {
        return '/^(?:git\s+)?' . preg_quote($command, '/')
            . '(?![^\r\n]*(?:--output(?:=|\s)|--ext-diff\b|--textconv\b|--open-files-in-pager\b))'
            . '(?:\s+[^\r\n;&|<>()`$]+)?$/i';
    }
}