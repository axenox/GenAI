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
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
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
 *                      "examples": ["exface/core/Docs/Getting_started/Introduction.md"]
 *                  }
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 * 
 * ## Output format
 * 
 * The tool will return the file contents in Open Knowledge Format (OKF) - as a Markdown file starting with a 
 * frontmatter block with file properties.
 * 
 * ## Partial reads
 * 
 * Large files can be read in chunks using the optional `start_with_line` and `max_lines` arguments. `start_with_line`
 * is a 1-based line number to start reading from (defaults to the first line) and `max_lines` limits how many lines
 * are returned (defaults to the rest of the file). This lets an LLM page through a file without loading it entirely.
 * 
 * ## Support for common AI instruction formats
 * 
 * This tool can include applicable AI instructions stored in the neighborhood of the requested file. For example, when
 * reading files from an app, the `.github` folder can be scanned for applicable instructions. Other formats like 
 * `Agents.md` will follow in the future.
 * 
 * ### Github Copilot instructions
 * 
 * Set `include_instructions_for_github_copilot` to `true` (the default) to include applicable Copilot instructions
 * markdown in addition to the file contents. All `.github/instructions/*.instructions.md` files of every installed
 * package are scanned and matched against the requested file using the same rules as VS Code:
 * 
 * - An instruction file matches if its `applyTo` front matter pattern matches the requested file. The pattern is
 * defined relative to the package containing the instructions (e.g. `Formulas/*.php` in the core app), but it is also
 * matched against the requested file relative to ITS OWN package. This way the formula instructions from the core also
 * apply to formula classes in other packages.
 * - An instruction file without front matter or without an `applyTo` pattern applies to EVERY file - exactly like
 * `applyTo: '**'` in VS Code.
 * 
 * Matching instructions are appended to the file contents as a separate `## Instructions` markdown chapter. If multiple
 * instruction files match, they are separated by a horizontal line.
 */
class FileReadTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    public const ARG_START_WITH_LINE = 'start_with_line';

    public const ARG_MAX_LINES = 'max_lines';

    private bool $includeInstructionsForGithubCopilot = true;

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = (string) ($arguments[0] ?? '');
        $fileInfo = $this->getFileInfo($relativePath, $this->getBasePathAbsolute(), $prompt);
        
        if (! $fileInfo->isFile()) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: target file does not exist.');
        }

        if (! $fileInfo->isReadable()) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target file is not readable.');
        }

        $language = $this->getFileLanguage($fileInfo);
        $content = $fileInfo->openFile()->read();
        if ($content === false) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read file: ' . $relativePath);
        }

        $startWithLine = $arguments[1] ?? null;
        $maxLines = $arguments[2] ?? null;
        if ($startWithLine !== null && $startWithLine !== '') {
            $startWithLine = (int) $startWithLine;
        } else {
            $startWithLine = null;
        }
        if ($maxLines !== null && $maxLines !== '') {
            $maxLines = (int) $maxLines;
        } else {
            $maxLines = null;
        }
        if ($startWithLine !== null || $maxLines !== null) {
            $content = $this->sliceLines($content, $startWithLine, $maxLines);
        }

        $result = $this->buildFrontmatter($fileInfo) . "\n\n";
        if ($language === 'markdown') {
            $result .= $content;
        } else {
            $result .= MarkdownDataType::escapeCodeBlock($content, $language);
        }

        if ($this->includeInstructionsForGithubCopilot === true) {
            $result .= $this->buildInstructionsChapter($fileInfo, $prompt);
        }

        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
    }

    /**
     * Include applicable GitHub Copilot instructions in the tool result.
     *
     * If enabled, the tool looks for `.github/instructions/*.instructions.md` files in the installed packages
     * whose `applyTo` pattern matches the requested file and appends them as a `## Instructions` chapter.
     *
     * @uxon-property include_instructions_for_github_copilot
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return FileReadTool
     */
    protected function setIncludeInstructionsForGithubCopilot(bool $value) : FileReadTool
    {
        $this->includeInstructionsForGithubCopilot = $value;
        return $this;
    }

    /**
     * Builds the `## Instructions` markdown chapter from instruction files applicable to the given file.
     *
     * Returns an empty string if no applicable instructions were found.
     *
     * @param FileInfoInterface $fileInfo
     * @param AiPromptInterface $prompt
     * @return string
     */
    protected function buildInstructionsChapter(FileInfoInterface $fileInfo, AiPromptInterface $prompt) : string
    {
        $instructions = $this->findInstructions($fileInfo);
        if (empty($instructions)) {
            return '';
        }

        $chapters = '';
        foreach ($instructions as $instructionFile) {
            $knowledgeKey = $instructionFile->getPathAbsolute();
            if ($prompt->hasKnowledge($knowledgeKey)) {
                continue;
            }
            $body = $instructionFile->openFile()->read();
            $body = trim(MarkdownDataType::stripFrontMatter($body));
            if ($body === '') {
                continue;
            }
            $chapters .= "\n\n" . MarkdownDataType::convertHeaderLevels($body, 2);
            $prompt->addKnowledge($knowledgeKey, $body);
        }

        if (empty($chapters)) {
            return '';
        }

        return "\n\n# Instructions\n\n" . $chapters . "\n";
    }
    
    /**
     * Returns a slice of the given content based on a 1-based start line and a maximum number of lines.
     *
     * @param string $content
     * @param int|null $startWithLine
     * @param int|null $maxLines
     * @return string
     */
    protected function sliceLines(string $content, ?int $startWithLine, ?int $maxLines) : string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $start = $startWithLine !== null ? max($startWithLine, 1) : 1;
        $offset = $start - 1;
        if ($maxLines !== null) {
            $slice = array_slice($lines, $offset, max($maxLines, 0));
        } else {
            $slice = array_slice($lines, $offset);
        }
        return implode("\n", $slice);
    }

    protected function getFileLanguage(FileInfoInterface $fileInfo) : ?string
    {
        $lang = null;
        $ext = mb_strtolower($fileInfo->getExtension());
        switch ($ext) {
            case 'md': $lang = 'markdown'; break;
            case 'html': $lang = 'html'; break;
            case 'php': $lang = 'php'; break;
            case 'js': $lang = 'javascript'; break;
        }
        return $lang;
    }
    
    protected function buildFrontmatter(FileInfoInterface $fileInfo) : string
    {
        return <<<FM
---
type: File
title: {$fileInfo->getFilename()}
resource: {$fileInfo->getPathRelative()}
mimetype: {$fileInfo->getMimeType()}
---
FM;
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
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Integer']))
                ->setName(self::ARG_START_WITH_LINE)
                ->setDescription('Optional 1-based line number to start reading from. Use together with max_lines to read large files in chunks.')
                ->setRequired(false),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Integer']))
                ->setName(self::ARG_MAX_LINES)
                ->setDescription('Optional maximum number of lines to read starting from start_with_line. If omitted, the file is read to the end.')
                ->setRequired(false),
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

}