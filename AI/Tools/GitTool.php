<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Interfaces\AiPromptInterface;
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
        parent::checkCommandAllowed($command, $prompt);
    }

    /**
     * Builds a regex for one Git subcommand without permitting shell operators.
     *
     * @param string $command
     * @return string
     */
    private function buildCommandPattern(string $command): string
    {
        return '/^git\s+' . preg_quote($command, '/')
            . '(?![^\r\n]*(?:--output(?:=|\s)|--ext-diff\b|--textconv\b|--open-files-in-pager\b))'
            . '(?:\s+[^\r\n;&|<>()`$]+)?$/i';
    }
}
