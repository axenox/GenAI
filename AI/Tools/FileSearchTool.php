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
use exface\Core\DataTypes\RegularExpressionDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Symfony\Component\Finder\Finder;

/**
 * Searches files by folder pattern, filename pattern and optional content query.
 * 
 * Use this tool similarly to IDE codebase search: provide a required relative
 * folder pattern (`path`), an optional filename pattern (`name`) and optionally
 * a string or regex to match file contents (`query`).
 * 
 * Splitting the folder pattern from the filename pattern keeps the search fast:
 * the folder pattern is resolved with PHP's `glob()` (which handles segment
 * wildcards like `* / * /Actions` natively), so the file system walk is limited
 * to the matching folders instead of the entire base path.
 * 
 * ## Example configuration in an assistant
 * 
 * ```
 *  {
 *      "instructions": "You search project files before reading details",
 *      "tools": {
 *          "search_files": {
 *              "alias": "axenox.GenAI.SearchFilesTool",
 *              "description": "Search files by text or regex. DO NOT search over entire vendor folder! Always specify a path!",
 *              "allowed_paths": [
 *                  "axenox/**",
 *                  "exface/**"
 *              ]
 *          }
 *      }
 *  }
 * 
 * ```
 */
class FileSearchTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_PATH = 'path';

    public const ARG_NAME = 'name';

    public const ARG_QUERY = 'query';

    private bool $includeExtractLine = true;

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
                ->setDescription('Folder to search in (relative to the vendor folder). Supports wildcards: `*` matches a single path segment, `**` matches any depth. E.g. `exface/core/Actions`, `*/*/Actions` or `axenox/**`. Use as narrow patterns as possible for better performance!')
                ->setExamples(['exface/core/Actions', '*/*/Actions', 'axenox/**'])
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_NAME)
                ->setExamples(['*.php', '*Tool.php', 'Abstract*.php'])
                ->setDescription('Optional filename pattern (glob), e.g. `*.php` or `*.{js,json}`. Defaults to all files.'),
            (new ServiceParameter($self))
                ->setName(self::ARG_QUERY)
                ->setExamples(['/->(dataCreate|dataUpdate|dataDelete)\\(.*/i'])
                ->setDescription('Optional string or regular expression to search for in file contents.'),
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $folderPattern = trim((string) ($arguments[0] ?? ''));
        $namePattern = trim((string) ($arguments[1] ?? ''));
        $query = trim((string) ($arguments[2] ?? ''));
        $basePath = $this->getBasePathAbsolute();

        if ($folderPattern === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing folder path pattern.');
        }

        $folderPattern = FilePathDataType::normalize($folderPattern, '/');
        $namePattern = FilePathDataType::normalize($namePattern, '/');

        // Convenience: if no explicit filename pattern was given, but the last segment of the
        // folder pattern looks like a filename glob (e.g. "*.php"), use it as the filename pattern.
        // This keeps the tool forgiving when a single combined pattern is passed in `path`.
        if ($namePattern === '') {
            $segments = explode('/', trim($folderPattern, '/'));
            $last = end($segments);
            if ($last !== '**' && $this->looksLikeFilenamePattern($last)) {
                $namePattern = $last;
                array_pop($segments);
                $folderPattern = implode('/', $segments);
            }
        }
        if ($namePattern === '') {
            $namePattern = '*';
        }

        $folderPattern = $this->validateRelativePattern($folderPattern, $prompt);
        if (str_contains($namePattern, '/')) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid filename pattern: must not contain a directory separator.');
        }
        $this->validateRelativePattern($namePattern, $prompt);

        // A single file (no wildcards) may be passed as the folder pattern - handle it directly.
        $absoluteFolder = FilePathDataType::normalize(FilePathDataType::makeAbsolute($folderPattern, $basePath, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
        if (! $this->containsWildcard($folderPattern) && is_file($absoluteFolder)) {
            $namePattern = FilePathDataType::findFileName($folderPattern, true);
            $folderPattern = FilePathDataType::findFolderPath($folderPattern);
        }

        // The folder pattern may contain `**` (any depth). PHP's glob() cannot expand `**`, so in that
        // case we glob only the static part before the first `**` and let the Finder recurse - matching
        // the full folder pattern via a path filter. Without `**` glob resolves the exact folders and the
        // Finder only needs to look at files directly inside them (depth 0) - which is very fast.
        $isRecursive = str_contains($folderPattern, '**');
        if ($isRecursive) {
            $globPattern = $this->getPatternBeforeRecursion($folderPattern);
        } else {
            $globPattern = $folderPattern;
        }

        $searchDirs = $this->resolveSearchDirectories($globPattern, $basePath);
        if (empty($searchDirs)) {
            return new AiToolResultString($this, $arguments, '- No matches found.', $this->getReturnDataType());
        }

        $finder = new Finder();
        try {
            $finder->files()
                ->ignoreUnreadableDirs()
                ->ignoreVCSIgnored(true)
                ->in($searchDirs)
                ->name($namePattern);
            if ($isRecursive) {
                // The folder portion still has to match the full pattern (e.g. `**` in the middle).
                $folderOnly = trim($folderPattern, '/');
                $finder->filter(function (\SplFileInfo $file) use ($basePath, $folderOnly): bool {
                    $absolutePath = (string) $file->getRealPath();
                    if ($absolutePath === '') {
                        return false;
                    }
                    $relativeDir = FilePathDataType::normalize($this->makeRelativePath(dirname($absolutePath), $basePath), '/');
                    return FilePathDataType::matchesPattern($relativeDir, $folderOnly);
                });
            } else {
                // Files must sit directly inside the resolved folders.
                $finder->depth('== 0');
            }
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Search failed: ' . $e->getMessage(), null, $e);
        }

        $isRegexQuery = $query !== '' && RegularExpressionDataType::isRegex($query);
        $searchPattern = $query === '' ? null : ($isRegexQuery ? $query : '/' . preg_quote($query, '/') . '/i');

        $matchedFiles = [];
        foreach ($finder as $file) {
            $absolutePath = (string) $file->getRealPath();
            if ($absolutePath === '') {
                continue;
            }
            try {
                $relativePath = $this->makeRelativePath($absolutePath, $basePath);
            } catch (\Throwable $e) {
                continue;
            }

            // Enforce allowed_paths against the concrete file path.
            if (! $this->isPathAllowed(FilePathDataType::normalize($relativePath, '/'))) {
                continue;
            }

            $extracts = [];
            if ($query !== '') {
                $matchedLines = $this->findMatchedLines($absolutePath, (string) $searchPattern);
                // A query was given, but the file does not contain it - skip it entirely.
                if (empty($matchedLines)) {
                    continue;
                }
                if ($this->includeExtractLine === true) {
                    $extracts = $matchedLines;
                }
            }

            $matchedFiles[] = [
                'path' => $relativePath,
                'extracts' => $extracts,
            ];
        }

        if (empty($matchedFiles)) {
            return new AiToolResultString($this, $arguments, '- No matches found.', $this->getReturnDataType());
        }

        usort($matchedFiles, function (array $a, array $b): int {
            return strnatcasecmp((string) $a['path'], (string) $b['path']);
        });
        
        $workbenchPath = FilePathDataType::normalize($this->getWorkbench()->getInstallationPath());
        $basePathFromWorkbench = StringDataType::startsWith($basePath, $workbenchPath) ? StringDataType::substringAfter($basePath, $workbenchPath, $basePath) : $basePath;
        $markdown = "Search result for `$namePattern` in path `$folderPattern` within base `$basePathFromWorkbench`.";
        if ($query !== '') {
            $markdown .= " Only including files containing `$query`.\n\n";
        }
        foreach ($matchedFiles as $fileMatch) {
            $path = (string) ($fileMatch['path'] ?? '');
            $markdown .= '- `' . str_replace('`', '\\`', FilePathDataType::normalize($path, '/')) . "`\n";
            // Two trailing spaces force a hard line break so every extract stays on its own line -
            // otherwise consecutive blockquote lines collapse into a single soft-wrapped paragraph.
            foreach (($fileMatch['extracts'] ?? []) as $extractLine) {
                $markdown .= '> ' . $extractLine . "  \n";
            }
        }

        return new AiToolResultString($this, $arguments, rtrim($markdown), $this->getReturnDataType());
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
     * Include matched lines as blockquote extracts in the markdown result.
     *
     * If enabled and a content query is provided, every matched file entry will contain
     * one blockquote line per matched file line.
     *
     * @uxon-property include_extract_line
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return FileSearchTool
     */
    protected function setIncludeExtractLine(bool $value): FileSearchTool
    {
        $this->includeExtractLine = $value;
        return $this;
    }

    /**
     * Validates that a relative pattern is safe (relative, no directory traversal) and allowed.
     *
     * @param string $relativePattern
     * @param AiPromptInterface $prompt
     * @return string
     */
    protected function validateRelativePattern(string $relativePattern, AiPromptInterface $prompt): string
    {
        if (FilePathDataType::isAbsolute($relativePattern)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: only paths relative to the configured base path are allowed.');
        }
        if (in_array('..', explode('/', $relativePattern), true)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: directory traversal ("..") is not allowed.');
        }
        return trim($relativePattern, '/');
    }

    /**
     * Returns TRUE if the given segment looks like a filename glob (has a wildcard and an extension).
     *
     * E.g. `*.php`, `Auto*.php` or `*.{js,ts}` return TRUE, while `Actions` or `.github` return FALSE.
     *
     * @param string $segment
     * @return bool
     */
    protected function looksLikeFilenamePattern(string $segment): bool
    {
        return $this->containsWildcard($segment) && strpos($segment, '.') !== false;
    }

    /**
     * Returns the static portion of a folder pattern before the first `**` segment.
     *
     * E.g. `exface/**` becomes `exface`, a pattern like `a/b/Actions/**` becomes `a/b/Actions`
     * and `**` becomes an empty string.
     *
     * @param string $pattern
     * @return string
     */
    protected function getPatternBeforeRecursion(string $pattern): string
    {
        $segments = explode('/', trim($pattern, '/'));
        $static = [];
        foreach ($segments as $segment) {
            if (strpos($segment, '**') !== false) {
                break;
            }
            $static[] = $segment;
        }
        return implode('/', $static);
    }

    /**
     * Resolves a (possibly wildcarded) relative folder pattern to a list of concrete, allowed directories.
     *
     * Uses PHP's `glob()` which natively handles single-segment wildcards like `*` and `?` across path
     * separators (e.g. `* / * /Actions`), keeping the file system walk limited to matching folders. An empty
     * pattern resolves to the base path itself.
     *
     * @param string $globPattern
     * @param string $basePath
     * @return string[]
     */
    protected function resolveSearchDirectories(string $globPattern, string $basePath): array
    {
        if ($globPattern === '') {
            return [$basePath];
        }

        $absPattern = FilePathDataType::normalize(
            FilePathDataType::makeAbsolute($globPattern, $basePath, '/'),
            '/'
        );

        $dirs = glob($absPattern, GLOB_ONLYDIR | GLOB_BRACE | GLOB_NOSORT) ?: [];
        $result = [];
        foreach ($dirs as $dir) {
            $normalized = FilePathDataType::normalize($dir, DIRECTORY_SEPARATOR);
            if (! $this->checkPathInsideBasePath($normalized, $basePath)) {
                continue;
            }
            try {
                $relative = FilePathDataType::normalize($this->makeRelativePath($normalized, $basePath), '/');
            } catch (\Throwable $e) {
                continue;
            }
            if (! $this->isPathAllowed($relative)) {
                continue;
            }
            $result[] = $normalized;
        }
        return $result;
    }

    /**
     * Returns TRUE if the given path contains wildcard characters.
     *
     * @param string $path
     * @return bool
     */
    protected function containsWildcard(string $path): bool
    {
        return preg_match('/[*?\[\]{}]/', $path) === 1;
    }

    /**
     * Returns all lines of the file that match the search pattern.
     *
     * @param string $absolutePath
     * @param string $searchPattern
     * @return string[]
     */
    protected function findMatchedLines(string $absolutePath, string $searchPattern): array
    {
        $matchedLines = [];
        $file = new \SplFileObject($absolutePath, 'r');
        $lineNumber = 0;

        while (! $file->eof()) {
            $line = $file->fgets();
            $lineNumber++;

            if ($line === false || preg_match($searchPattern, $line) !== 1) {
                continue;
            }

            $lineText = trim(str_replace(["\r", "\n"], '', $line));
            $lineText = str_replace('`', '\\`', $lineText);
            $lineText = str_replace('>', '\\>', $lineText);
            $matchedLines[] = 'L' . $lineNumber . ': ' . $lineText;
        }

        return $matchedLines;
    }
}