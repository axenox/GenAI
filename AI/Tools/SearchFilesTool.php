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
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Symfony\Component\Finder\Finder;

/**
 * This AI tool searches file contents in a folder and returns matching file paths.
 *
 * Use this tool similarly to IDE codebase search: provide a query and optionally
 * restrict the search scope with a folder path or wildcard folder pattern.
 *
 * ## Example configuration in an assistant
 *
 * ```
 *  {
 *      "instructions": "You search project files before reading details",
 *      "tools": {
 *          "search_files": {
 *              "alias": "axenox.GenAI.SearchFilesTool",
 *              "description": "Search files by text or regex",
 *              "use_vendor_folder_as_base": true,
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
class SearchFilesTool extends AbstractAiTool
{
    use FileAccessToolTrait;

    public const ARG_QUERY = 'query';

    public const ARG_FOLDER = 'folder';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $query = trim((string) ($arguments[0] ?? ''));
        $folderPattern = trim((string) ($arguments[1] ?? ''));
        $basePath = $this->getBasePathAbsolute();

        if ($query === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing search query.');
        }

        $isRegexQuery = RegularExpressionDataType::isRegex($query);
        $searchPattern = $isRegexQuery ? $query : '/' . preg_quote($query, '/') . '/i';
        $searchFolders = $this->resolveSearchFolders($folderPattern, $basePath, $prompt);

        if (empty($searchFolders)) {
            return new AiToolResultString($this, $arguments, '- No matches found.', $this->getReturnDataType());
        }

        $finder = new Finder();
        try {
            $finder->files()
                ->ignoreUnreadableDirs()
                ->ignoreVCSIgnored(true)
                ->contains($searchPattern)
                ->in($searchFolders);
        } catch (\Throwable $e) {
            throw new AiToolRuntimeError($this, $prompt, 'Search failed: ' . $e->getMessage(), null, $e);
        }

        $relativePaths = [];
        foreach ($finder as $file) {
            $absolutePath = (string) $file->getRealPath();
            if ($absolutePath === '') {
                continue;
            }
            try {
                $relativePaths[] = $this->makeRelativePath($absolutePath, $basePath);
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (empty($relativePaths)) {
            return new AiToolResultString($this, $arguments, '- No matches found.', $this->getReturnDataType());
        }

        natcasesort($relativePaths);
        $markdown = '';
        foreach ($relativePaths as $path) {
            $markdown .= '- `' . str_replace('`', '\\`', FilePathDataType::normalize((string) $path, '/')) . "`\n";
        }

        return new AiToolResultString($this, $arguments, rtrim($markdown), $this->getReturnDataType());
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
                ->setName(self::ARG_QUERY)
                ->setDescription('String or regular expression to search for in file contents.'),
            (new ServiceParameter($self))
                ->setName(self::ARG_FOLDER)
                ->setDescription('Optional folder path or wildcard pattern relative to the configured base path.'),
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
     * Resolves folder input into existing absolute folders to search in.
     *
     * @param string $folderPatternRelative
     * @param string $basePath
     * @param AiPromptInterface $prompt
     * @return string[]
     */
    protected function resolveSearchFolders(string $folderPatternRelative, string $basePath, AiPromptInterface $prompt): array
    {
        if ($folderPatternRelative === '') {
            return [$basePath];
        }

        if (FilePathDataType::isAbsolute($folderPatternRelative)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: only paths relative to the configured base path are allowed.');
        }

        $folderPatternRelative = FilePathDataType::normalize($folderPatternRelative, '/');
        $this->checkPathAllowed($folderPatternRelative, $prompt);

        $absolutePattern = FilePathDataType::makeAbsolute($folderPatternRelative, $basePath, DIRECTORY_SEPARATOR);
        $absolutePattern = FilePathDataType::normalize($absolutePattern, DIRECTORY_SEPARATOR);

        if ($this->containsWildcard($absolutePattern)) {
            $dirs = glob($absolutePattern, GLOB_ONLYDIR);
            if ($dirs === false) {
                return [];
            }

            $existing = [];
            foreach ($dirs as $dir) {
                $dirNormalized = FilePathDataType::normalize($dir, DIRECTORY_SEPARATOR);
                if (is_dir($dirNormalized) && $this->checkPathInsideBasePath($dirNormalized, $basePath)) {
                    $existing[] = $dirNormalized;
                }
            }
            return array_values(array_unique($existing));
        }

        if (! is_dir($absolutePattern)) {
            return [];
        }

        if (! $this->checkPathInsideBasePath($absolutePattern, $basePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: resolved folder is outside the configured base path.');
        }

        return [$absolutePattern];
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
}