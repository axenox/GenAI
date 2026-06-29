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
 *          "write_file": {
 *              "alias": "axenox.GenAI.WriteFileTool",
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
class FileWriteTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    public const ARG_CONTENT = 'content';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = (string) ($arguments[0] ?? '');
        $content = (string) ($arguments[1] ?? '');
        $basePath = $this->getBasePathAbsolute();
        $absolutePath = $this->getPathAbsolute($relativePath, $basePath, $prompt);

        try {
            $this->getWorkbench()->filemanager()->dumpFile($absolutePath, $content);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to write file: ' . $relativePath . '. ' . $e->getMessage(), null, $e);
        }
        
        $message = 'File saved: ' . $relativePath;
        return new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
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