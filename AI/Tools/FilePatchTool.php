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
 * Applies patches to files using SEARCH/REPLACE blocks.
 * 
 * This is a more effective alternative to the `FileWriteTool` for editing existing files: instead of
 * rewriting the entire file, the LLM only sends the snippets that must change. This saves tokens, reduces
 * the risk of accidentally dropping unrelated parts of the file and makes diffs easy to review.
 * 
 * ## Patch format
 * 
 * A patch consists of one or more SEARCH/REPLACE blocks. Each block looks like this:
 * 
 * ```
 * <<<<<<< SEARCH
 * exact lines from the current file
 * =======
 * replacement lines
 * >>>>>>> REPLACE
 * ```
 * 
 * Rules the LLM must follow:
 * 
 * - The SEARCH section must match the current file content EXACTLY (including indentation and whitespace).
 * - Keep SEARCH blocks small and include just enough surrounding context to be unique within the file.
 * - Blocks are applied in the order they appear. Each SEARCH replaces the FIRST remaining occurrence.
 * - To insert new content at the end of a file, use an empty SEARCH section.
 * - To create a brand new file, use a single block with an empty SEARCH section and the full content as REPLACE.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You help edit Markdown documentation",
 *      "tools": {
 *          "patch_file": {
 *              "alias": "axenox.GenAI.FilePatchTool",
 *              "description": "Apply a SEARCH/REPLACE patch to a Markdown file",
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
 *                  }, {
 *                       "name": "patch",
 *                       "description": "One or more SEARCH/REPLACE blocks to apply to the file",
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
class FilePatchTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    public const ARG_PATCH = 'patch';

    private const MARKER_SEARCH = '<<<<<<< SEARCH';

    private const MARKER_DIVIDER = '=======';

    private const MARKER_REPLACE = '>>>>>>> REPLACE';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $relativePath = (string) ($arguments[0] ?? '');
        $patch = (string) ($arguments[1] ?? '');

        $blocks = $this->parsePatch($patch, $prompt);
        if (empty($blocks)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid patch: no SEARCH/REPLACE blocks found. Each block must use the markers "' . self::MARKER_SEARCH . '", "' . self::MARKER_DIVIDER . '" and "' . self::MARKER_REPLACE . '".');
        }

        $basePath = $this->getBasePathAbsolute();
        $fileInfo = $this->getFileInfo($relativePath, $basePath, $prompt);
        $absolutePath = $fileInfo->getPathAbsolute();

        $fileExists = $fileInfo->isFile();
        if ($fileExists === true && ! $fileInfo->isReadable()) {
            throw new AiToolRuntimeError($this, $prompt, 'Access denied: target file is not readable.');
        }
        $content = $fileExists ? $fileInfo->openFile()->read() : '';
        if ($content === false) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to read file: ' . $relativePath);
        }

        $newContent = $this->applyBlocks($content, $blocks, $relativePath, $prompt);

        try {
            $this->getWorkbench()->filemanager()->dumpFile($absolutePath, $newContent);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Failed to write file: ' . $relativePath . '. ' . $e->getMessage(), null, $e);
        }

        $verb = $fileExists ? 'patched' : 'created';
        $message = 'File ' . $verb . ': ' . $relativePath . ' (' . count($blocks) . ' block(s) applied)';
        return new AiToolResultString($this, $arguments, $message, $this->getReturnDataType());
    }

    /**
     * Parses a patch string into an array of [search, replace] block pairs.
     *
     * @param string $patch
     * @param AiPromptInterface $prompt
     * @throws AiToolRuntimeError
     * @return array<int,array{0:string,1:string}>
     */
    protected function parsePatch(string $patch, AiPromptInterface $prompt) : array
    {
        // Normalize line endings to LF for reliable marker detection.
        $normalized = str_replace(["\r\n", "\r"], "\n", $patch);
        $lines = explode("\n", $normalized);

        $blocks = [];
        $state = 'outside';
        $search = [];
        $replace = [];
        foreach ($lines as $line) {
            $trimmed = rtrim($line, " \t");
            switch (true) {
                case $trimmed === self::MARKER_SEARCH:
                    if ($state !== 'outside') {
                        throw new AiToolRuntimeError($this, $prompt, 'Invalid patch: unexpected "' . self::MARKER_SEARCH . '" marker before the previous block was closed.');
                    }
                    $state = 'search';
                    $search = [];
                    $replace = [];
                    break;
                case $trimmed === self::MARKER_DIVIDER && $state === 'search':
                    $state = 'replace';
                    break;
                case $trimmed === self::MARKER_REPLACE:
                    if ($state !== 'replace') {
                        throw new AiToolRuntimeError($this, $prompt, 'Invalid patch: "' . self::MARKER_REPLACE . '" marker without a matching "' . self::MARKER_DIVIDER . '" divider.');
                    }
                    $blocks[] = [implode("\n", $search), implode("\n", $replace)];
                    $state = 'outside';
                    break;
                case $state === 'search':
                    $search[] = $line;
                    break;
                case $state === 'replace':
                    $replace[] = $line;
                    break;
                // Lines outside any block are ignored (e.g. surrounding prose or blank lines).
            }
        }

        if ($state !== 'outside') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid patch: the last block is not closed with a "' . self::MARKER_REPLACE . '" marker.');
        }

        return $blocks;
    }

    /**
     * Applies all SEARCH/REPLACE blocks to the given content and returns the result.
     *
     * @param string $content
     * @param array<int,array{0:string,1:string}> $blocks
     * @param string $relativePath
     * @param AiPromptInterface $prompt
     * @throws AiToolRuntimeError
     * @return string
     */
    protected function applyBlocks(string $content, array $blocks, string $relativePath, AiPromptInterface $prompt) : string
    {
        // Work with LF internally to keep matching consistent, remember the original style for the final output.
        $usesCrLf = strpos($content, "\r\n") !== false;
        $working = str_replace(["\r\n", "\r"], "\n", $content);

        foreach ($blocks as $i => [$search, $replace]) {
            $blockNo = $i + 1;
            // Empty SEARCH means "append" (or "create" if the file is empty).
            if ($search === '') {
                if ($working === '') {
                    $working = $replace;
                } else {
                    $working = rtrim($working, "\n") . "\n" . $replace;
                }
                continue;
            }

            $pos = $this->findSearchPosition($working, $search);
            if ($pos === null) {
                throw new AiToolRuntimeError($this, $prompt, 'Failed to apply patch block #' . $blockNo . ' to "' . $relativePath . '": the SEARCH text was not found. Make sure it matches the current file content exactly.');
            }

            $working = substr($working, 0, $pos)
                . $replace
                . substr($working, $pos + strlen($search));
        }

        if ($usesCrLf === true) {
            $working = str_replace("\n", "\r\n", $working);
        }

        return $working;
    }

    /**
     * Finds the byte position of the SEARCH text within the content.
     *
     * Tries an exact match first and falls back to an indentation-insensitive match per line, so the LLM does
     * not have to reproduce leading whitespace byte-for-byte.
     *
     * @param string $content
     * @param string $search
     * @return int|null
     */
    protected function findSearchPosition(string $content, string $search) : ?int
    {
        $pos = strpos($content, $search);
        if ($pos !== false) {
            return $pos;
        }

        // Fallback: match the sequence of lines ignoring leading/trailing whitespace on each line.
        $contentLines = explode("\n", $content);
        $searchLines = explode("\n", $search);
        $needle = array_map('trim', $searchLines);
        $count = count($needle);

        $offsets = [];
        $offset = 0;
        foreach ($contentLines as $line) {
            $offsets[] = $offset;
            $offset += strlen($line) + 1; // +1 for the newline
        }

        $haystack = array_map('trim', $contentLines);
        $max = count($haystack) - $count;
        for ($i = 0; $i <= $max; $i++) {
            $match = true;
            for ($j = 0; $j < $count; $j++) {
                if ($haystack[$i + $j] !== $needle[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match === true) {
                return $offsets[$i];
            }
        }

        return null;
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
                ->setName(self::ARG_PATCH)
                ->setDescription('One or more SEARCH/REPLACE blocks. Each block: "' . self::MARKER_SEARCH . '" line, the exact text to find, a "' . self::MARKER_DIVIDER . '" divider, the replacement text and a closing "' . self::MARKER_REPLACE . '" line. Use an empty SEARCH section to append content or to create a new file.'),
        ];
    }
    
    public function getRules(): ?string
    {
        return <<<MD

- The SEARCH section must match the current file content EXACTLY (including indentation and whitespace).
- Keep SEARCH blocks small and include just enough surrounding context to be unique within the file.
- Blocks are applied in the order they appear. Each SEARCH replaces the FIRST remaining occurrence.
- To insert new content at the end of a file, use an empty SEARCH section.
- To create a brand new file, use a single block with an empty SEARCH section and the full content as REPLACE.

MD;

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