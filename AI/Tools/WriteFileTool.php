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
 * This AI tool allows an LLM create/overwrite a file in selected folders.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You help write Markdown documentation",
 *      "tools": {
 *          "WriteFile": {
 *              "description": "Create/overwrite a Markdown file",
 *              "use_vendor_folder_as_base": true,
 *              "allowed_paths": [
 *                  "axenox/*.md",
 *                  "exface/*.md",
 *              ],
 *              "arguments": [
 *                  {
 *                      "name": "path",
 *                      "description": "Path including filename and extension relative to the vendor folder",
 *                      "data_type": {
 *                          "alias": "exface.Core.String"
 *                      }
 *                  }, {
 *                       "name": "content",
 *                       "description": "Text content to write into the target file",
 *                       "data_type": {
 *                           "alias": "exface.Core.String"
 *                       }
 *                   }
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 */
class WriteFileTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    public const ARG_CONTENT = 'content';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        $relativePath = (string) ($arguments[0] ?? '');
        $content = (string) ($arguments[1] ?? '');

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

        $this->getWorkbench()->filemanager()->dumpFile($absolutePath, $content);
        return 'File written: ' . $pathForPatternCheck;
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
            (new ServiceParameter($self))
                ->setName(self::ARG_CONTENT)
                ->setDescription('Text content to write into the target file.'),
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