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
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * List files and folders in a directory up to a certain depth.
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
 *          "read_folder": {
 *              "alias": "axenox.GenAI.ReadFolderTool",
 *              "description": "List files in a folder as Markdown",
 *              "use_vendor_folder_as_base": true,
 *              "allowed_paths": [
 *                  "axenox/genai/**"
 *              ],
 *              "depth": 2,
 *              "arguments": [
 *                  {
 *                      "name": "path",
 *                      "description": "Path to a folder relative to the vendor folder"
 *                  }
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 */
class FolderReadTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    /** @var int */
    private int $depth = 0;

    /** @var bool */
    private bool $excludeDotPaths = true;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = (string) ($arguments[0] ?? '');
        // Make sure the relative path for a folder ends with a slash
        $relativePath = rtrim($relativePath, '/') . '/';
        $basePath = $this->getBasePathAbsolute();
        $absolutePath = $this->getPathAbsolute($relativePath, $basePath, $prompt);
        
        if (! is_dir($absolutePath)) {
            return new AiToolResultString($this, $arguments, 'Folder does not exist', $this->getReturnDataType());
        }

        if (! is_readable($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target folder is not readable.');
        }

        $markdown = '- ' . rtrim(FilePathDataType::normalize($relativePath, '/'), '/') . '/';
        $markdown .= $this->buildMdTreeLevel($absolutePath, $basePath, 1);
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
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }

    /**
     * Maximum recursive depth for folder listing.
     *
     * Depth `1` lists direct children only. Higher values include deeper levels. Depth `0` includes all children
     * recursively without limit.
     *
     * @uxon-property depth
     * @uxon-type integer
     * @uxon-default 0
     *
     * @param int $value
     * @return FolderReadTool
     */
    protected function setDepth(int $value): FolderReadTool
    {
        $this->depth = max(0, $value);
        return $this;
    }

    /**
     * Set to FALSE to list files and folders whose names start with a dot - e.g. `.git`.
     *
     * @uxon-property exclude_dot_paths
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return FolderReadTool
     */
    protected function setExcludeDotPaths(bool $value): FolderReadTool
    {
        $this->excludeDotPaths = $value;
        return $this;
    }

    /**
     * Appends folder entries as nested Markdown bullets.
     *
     * @param string $absoluteDir
     * @param string $basePath
     * @param int $level
     * @return string
     */
    protected function buildMdTreeLevel(string $absoluteDir, string $basePath, int $level): string
    {
        if ($this->depth !== 0 && $level > $this->depth) {
            return '';
        }
        
        $entries = scandir($absoluteDir);
        if ($entries === false) {
            return '';
        }

        $items = array_values(
            array_filter(
                $entries, 
                static function (string $name): bool 
                {
                    return $name !== '.' && $name !== '..';
                }
            )
        );

        if ($this->excludeDotPaths) {
            $items = array_values(
                array_filter(
                    $items,
                    static function (string $name): bool
                    {
                        return $name === '' || $name[0] !== '.';
                    }
                )
            );
        }
        natcasesort($items);

        $markdown = '';
        foreach ($items as $name) {
            $path = $absoluteDir . DIRECTORY_SEPARATOR . $name;
            $isDir = is_dir($path) && ! is_link($path);
            $indent = str_repeat('  ', $level);
            $displayName = str_replace('`', '\\`', $name);
            $markdown .= "\n" . $indent . '- `' . $displayName . ($isDir ? '/' : '') . '`';

            if ($isDir) {
                $markdown .= $this->buildMdTreeLevel($path, $basePath, $level + 1);
            }
        }
        return $markdown;
    }
}