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
 * This AI tool allows an LLM to read a file from selected folders.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You summarize markdown files",
 *      "tools": {
 *          "ReadFile": {
 *              "description": "Read a Markdown file",
 *              "use_vendor_folder_as_base": true,
 *              "allowed_paths": [
 *                  "axenox/*.md",
 *                  "exface/*.md"
 *              ],
 *              "arguments": [
 *                  {
 *                      "name": "path",
 *                      "description": "Path including filename and extension relative to the vendor folder",
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
class ReadFileTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $relativePath = (string) ($arguments[0] ?? '');

        if ($relativePath === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing target file path.', 'INVALID_ARGUMENTS');
        }

        if (FilePathDataType::isAbsolute($relativePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: only paths relative to the configured base path are allowed.', 'ABSOLUTE_PATH_NOT_ALLOWED');
        }

        $basePath = $this->getBasePathAbsolute();
        $absolutePath = FilePathDataType::makeAbsolute($relativePath, $basePath, DIRECTORY_SEPARATOR);

        if (! $this->isInsideBasePath($absolutePath, $basePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: file must stay inside the configured base path.', 'PATH_OUT_OF_BOUNDS');
        }

        $pathForPatternCheck = $this->makeRelativePath($absolutePath, $basePath);
        if (! $this->isAllowedPath($pathForPatternCheck)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: file path does not match any allowed_paths pattern.', 'PATH_NOT_ALLOWED');
        }

        if (! is_file($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: target file does not exist.', 'FILE_NOT_FOUND');
        }

        if (! is_readable($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target file is not readable.', 'FILE_NOT_READABLE');
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read file: ' . $pathForPatternCheck, 'FILE_READ_FAILED');
        }

        return $content;
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
                ->setDescription('Path to the file relative to the configured base path.'),
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

}