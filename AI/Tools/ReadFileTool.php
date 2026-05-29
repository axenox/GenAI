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
 *          "read_file": {
 *              "alias": "axenox.GenAI.ReadFileTool",
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
 * 
 * ## Support for common AI instruction formats
 * 
 * This tool can include applicable AI instructions stored in the neighborhood of the requested file. For example, when
 * reading files from an app, the `.github` folder can be scanned for applicable instructions. Other formats like 
 * `Agents.md` will follow in the future.
 * 
 * ### Github Copilot instructions
 * 
 * Set `include_instructions_for_github_copilot` to `true` to include capplicable Copilot instructions markdown in
 * addition to the file contents, if `.github/instructions/*.instructions.md` files are found in the file hierarchy 
 * above the requested file.
 * 
 * If multiple files are requested (e.g. multiple tool calls), instructions applicable to multiple files will be included
 * only once.
 * 
 * Copilot instructions will be appended to the file contents:
 * 
 * ```
 * <requested_file>
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
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = (string) ($arguments[0] ?? '');
        $basePath = $this->getBasePathAbsolute();
        $absolutePath = $this->getPathAbsolute($relativePath, $basePath, $prompt);

        if (! is_file($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: target file does not exist.');
        }

        if (! is_readable($absolutePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target file is not readable.');
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read file: ' . $relativePath);
        }

        return new AiToolResultString($this, $arguments, $content, $this->getReturnDataType());
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