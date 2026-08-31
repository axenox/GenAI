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
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Validates the syntax of a PHP file without executing it.
 * 
 * The file path is validated using the shared file access settings `base_path`,
 * `use_vendor_folder_as_base` and `allowed_paths`. The tool always invokes the
 * current PHP binary in lint mode; agents cannot provide executable names or
 * command-line options.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 * {
 *     "tools": {
 *         "lint_php": {
 *             "alias": "axenox.GenAI.DevLintPHPTool",
 *             "description": "Validate PHP syntax after changing a PHP file",
 *             "allowed_paths": ["axenox/genai/AI/Tools/*.php"]
 *         }
 *     }
 * }
 * 
 * ```
 */
class DevLintPHPTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = trim((string) ($arguments[0] ?? ''));
        $fileInfo = $this->getFileInfo($relativePath, $this->getBasePathAbsolute(), $prompt);

        if (! $fileInfo->isFile()) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: target PHP file does not exist.');
        }
        if (! $fileInfo->isReadable()) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target PHP file is not readable.');
        }
        if (strtolower($fileInfo->getExtension()) !== 'php') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: PHP lint only accepts files with the .php extension.');
        }

        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($fileInfo->getPathAbsolute());
        try {
            $output = '';
            $generator = CliCommandRunner::runCliCommand($command, [], 60, null, false, [255]);
            foreach ($generator as $chunk) {
                $output .= (string) $chunk;
            }
        } catch (\Throwable $exception) {
            throw new AiToolRuntimeError($this, $prompt, 'PHP lint failed to run: ' . $exception->getMessage(), null, $exception);
        }

        $output = trim($output);
        if ($output === '') {
            $output = '(no output)';
        }

        return new AiToolResultString(
            $this,
            $arguments,
            MarkdownDataType::escapeCodeBlock($output, 'text'),
            $this->getReturnDataType()
        );
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
                ->setName(self::ARG_PATH)
                ->setDescription('Path to the PHP file relative to the configured base path.')
                ->setRequired(true),
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
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getRules()
     */
    public function getRules(): ?string
    {
        $rules = [];
        $rules[] = 'Lint PHP files after creating or changing them and before considering the implementation complete.';
        $rules[] = 'Pass a `.php` file path relative to the configured base path: ' . $this->getBasePathDescription() . '.';
        $rules[] = 'Never pass an absolute path or command-line options.';

        $allowedPaths = $this->getAllowedPathPatterns();
        if ($allowedPaths !== []) {
            $rules[] = 'The file path must match one of these patterns:';
            foreach ($allowedPaths as $pattern) {
                $rules[] = '- `' . $pattern . '`';
            }
        } else {
            $rules[] = 'No additional allowed-path pattern is configured; stay within the configured base path.';
        }

        return implode("\n", $rules);
    }
}
