<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\AI\Traits\FileAccessToolTrait;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to list files and folders in a directory.
 *
 * Use this tool to inspect folder contents in a controlled scope using
 * `base_path`, `use_vendor_folder_as_base` and `allowed_paths`.
 *
 * ## Example configuration in an assistant
 *
 * ```
 *  {
 *      "instructions": "You summarize project structure",
 *      "tools": {
 *          "ReadFolder": {
 *              "description": "List files in a folder as Markdown",
 *              "use_vendor_folder_as_base": true,
 *              "allowed_paths": [
 *                  "axenox/genai/**"
 *              ],
 *              "depth": 2,
 *              "arguments": [
 *                  {
 *                      "name": "path",
 *                      "description": "Path to a folder relative to the vendor folder",
 *                      "data_type": {
 *                          "alias": "exface.Core.String"
 *                      }
 *                  }
 *              ]
 *          }
 *      }
 *  }
 *
 * ```
 */
class ReadFolderTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    /** @var int */
    private int $depth = 1;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $relativePath = (string) ($arguments[0] ?? '');

        if ($relativePath === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing target folder path.', 'INVALID_ARGUMENTS');
        }

        if (FilePathDataType::isAbsolute($relativePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: only paths relative to the configured base path are allowed.', 'ABSOLUTE_PATH_NOT_ALLOWED');
        }

        $basePath = $this->getBasePathAbsolute();
        $absolutePath = FilePathDataType::makeAbsolute($relativePath, $basePath, DIRECTORY_SEPARATOR);

        if (! $this->isInsideBasePath($absolutePath, $basePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: folder must stay inside the configured base path.', 'PATH_OUT_OF_BOUNDS');
        }

        $pathForPatternCheck = $this->makeRelativePath($absolutePath, $basePath);
        if (! $this->isAllowedPath($pathForPatternCheck)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: folder path does not match any allowed_paths pattern.', 'PATH_NOT_ALLOWED');
        }

        if (! is_dir($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: target folder does not exist.', 'FOLDER_NOT_FOUND');
        }

        if (! is_readable($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target folder is not readable.', 'FOLDER_NOT_READABLE');
        }

        $lines = [];
        $title = rtrim(FilePathDataType::normalize($pathForPatternCheck, '/'), '/');
        $lines[] = '- `' . ($title === '' ? '.' : $title) . '/`';

        $this->appendDirectoryTree($absolutePath, $basePath, 1, $lines);

        return implode(PHP_EOL, $lines);
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
                ->setDescription('Path to the folder relative to the configured base path.'),
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
    }

    /**
     * Maximum recursive depth for folder listing.
     *
     * Depth `1` lists direct children only. Higher values include deeper levels.
     *
     * @uxon-property depth
     * @uxon-type integer
     * @uxon-default 1
     *
     * @param int $value
     * @return ReadFolderTool
     */
    protected function setDepth(int $value): ReadFolderTool
    {
        $this->depth = max(1, $value);
        return $this;
    }

    /**
     * Appends folder entries as nested Markdown bullets.
     *
     * @param string $absoluteDir
     * @param string $basePath
     * @param int $level
     * @param string[] $lines
     * @return void
     */
    protected function appendDirectoryTree(string $absoluteDir, string $basePath, int $level, array &$lines): void
    {
        if ($level > $this->depth) {
            return;
        }

        $entries = scandir($absoluteDir);
        if ($entries === false) {
            return;
        }

        $items = array_values(array_filter($entries, static function (string $name): bool {
            return $name !== '.' && $name !== '..';
        }));
        natcasesort($items);

        foreach ($items as $name) {
            $path = $absoluteDir . DIRECTORY_SEPARATOR . $name;
            $relativePath = $this->makeRelativePath($path, $basePath);
            if (! $this->isAllowedPath($relativePath)) {
                continue;
            }

            $isDir = is_dir($path) && ! is_link($path);
            $indent = str_repeat('  ', $level);
            $displayName = str_replace('`', '\\`', $name);
            $lines[] = $indent . '- `' . $displayName . ($isDir ? '/' : '') . '`';

            if ($isDir) {
                $this->appendDirectoryTree($path, $basePath, $level + 1, $lines);
            }
        }
    }
}