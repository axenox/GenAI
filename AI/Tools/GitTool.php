<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
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
Run one Git command at a time and use the result returned by this tool directly.
Do not use shell operators, variable or command substitutions, or options that write files or invoke external programs.
Place search patterns containing characters such as `|` or parentheses inside matching double quotes.
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
        $tokens = $this->parseSafeCommandTokens($command);
        if ($tokens === null) {
            throw new AiToolRuntimeError(
                $this,
                $prompt,
                'Command contains unsafe shell syntax or unmatched quotes. Run one Git command at a time, remove shell operators and substitutions, and place search patterns containing "|" or parentheses inside matching double quotes.'
            );
        }
        foreach ($tokens as $token) {
            if ($this->isBlockedOption($token)) {
                throw new AiToolRuntimeError(
                    $this,
                    $prompt,
                    'Command contains a Git option that may write files or invoke an external program. Remove that option and use the GitTool result returned directly by the command.'
                );
            }
        }
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
            . '(?:\s+[^\r\n]+)?$/i';
    }

    /**
     * Splits a command into arguments while rejecting shell control operators.
     *
     * Shell metacharacters are accepted inside double-quoted arguments so Git
     * search patterns can use alternation and grouping. Variable and command
     * substitution remain blocked in every context.
     *
     * @param string $command
     * @return string[]|null
     */
    private function parseSafeCommandTokens(string $command): ?array
    {
        $tokens = [];
        $token = '';
        $tokenStarted = false;
        $quote = null;
        $length = strlen($command);

        for ($position = 0; $position < $length; $position++) {
            $character = $command[$position];
            if ($character === "\r" || $character === "\n") {
                return null;
            }

            if ($quote === null) {
                if (ctype_space($character)) {
                    if ($tokenStarted) {
                        $tokens[] = $token;
                        $token = '';
                        $tokenStarted = false;
                    }
                    continue;
                }
                if ($character === '"' || $character === "'") {
                    $quote = $character;
                    $tokenStarted = true;
                    continue;
                }
                if (strpos(';&|<>()`$', $character) !== false) {
                    return null;
                }
                $token .= $character;
                $tokenStarted = true;
                continue;
            }

            if ($character === $quote) {
                $quote = null;
                continue;
            }
            if ($character === '$' || $character === '`') {
                return null;
            }
            if ($quote === "'" && strpos(';&|<>()', $character) !== false) {
                return null;
            }
            if ($quote === '"' && $character === '\\' && $position + 1 < $length) {
                $token .= $character . $command[++$position];
                continue;
            }
            $token .= $character;
        }

        if ($quote !== null) {
            return null;
        }
        if ($tokenStarted) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Returns TRUE for Git options that can write files or invoke external programs.
     *
     * @param string $token
     * @return bool
     */
    private function isBlockedOption(string $token): bool
    {
        foreach (['--output', '--ext-diff', '--textconv', '--open-files-in-pager'] as $option) {
            if ($token === $option || strpos($token, $option . '=') === 0) {
                return true;
            }
        }

        return preg_match('/^-O(?:.+)?$/', $token) === 1;
    }
}