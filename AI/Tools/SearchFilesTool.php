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
 * This AI tool searches files by path pattern and optional content query.
 *
 * Use this tool similarly to IDE codebase search: provide a required relative
 * file path pattern and optionally a string or regex to match file contents.
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

    public const ARG_PATH = 'path';

    public const ARG_QUERY = 'query';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $pathPattern = trim((string) ($arguments[0] ?? ''));
        $query = trim((string) ($arguments[1] ?? ''));
        $basePath = $this->getBasePathAbsolute();

        if ($pathPattern === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing path pattern.');
        }

        $pathPattern = $this->normalizePathPattern($pathPattern, $basePath, $prompt);
        $isWildcardPath = $this->containsWildcard($pathPattern);
        $absolutePath = FilePathDataType::normalize(FilePathDataType::makeAbsolute($pathPattern, $basePath, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
        $isDirectoryPath = ! $isWildcardPath && is_dir($absolutePath);
        $isFilePath = ! $isWildcardPath && is_file($absolutePath);

        if (! $isWildcardPath && ! $isDirectoryPath && ! $isFilePath) {
            return new AiToolResultString($this, $arguments, '- No matches found.', $this->getReturnDataType());
        }

        $finder = new Finder();
        try {
            $finder->files()
                ->ignoreUnreadableDirs()
                ->ignoreVCSIgnored(true)
                ->in($basePath)
                ->filter(function (
                    \SplFileInfo $file
                ) use ($basePath, $pathPattern, $isWildcardPath, $isDirectoryPath): bool {
                    $absolutePath = (string) $file->getRealPath();
                    if ($absolutePath === '') {
                        return false;
                    }

                    $relativePath = FilePathDataType::normalize($this->makeRelativePath($absolutePath, $basePath), '/');
                    if ($isWildcardPath) {
                        return FilePathDataType::matchesPattern($relativePath, $pathPattern);
                    }

                    if ($isDirectoryPath) {
                        $directoryPath = trim($pathPattern, '/');
                        return $relativePath === $directoryPath || str_starts_with($relativePath, $directoryPath . '/');
                    }

                    return $relativePath === trim($pathPattern, '/');
                });

            if ($query !== '') {
                $isRegexQuery = RegularExpressionDataType::isRegex($query);
                $searchPattern = $isRegexQuery ? $query : '/' . preg_quote($query, '/') . '/i';
                $finder->contains($searchPattern);
            }
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
                ->setName(self::ARG_PATH)
                ->setDescription('Required relative file path or wildcard pattern to search in.')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName(self::ARG_QUERY)
                ->setDescription('Optional string or regular expression to search for in file contents.'),
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
     * Validates and normalizes the required relative path pattern.
     *
     * @param string $pathPatternRelative
     * @param string $basePath
     * @param AiPromptInterface $prompt
     * @return string
     */
    protected function normalizePathPattern(string $pathPatternRelative, string $basePath, AiPromptInterface $prompt): string
    {
        if ($pathPatternRelative === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing path pattern.');
        }

        if (FilePathDataType::isAbsolute($pathPatternRelative)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: only paths relative to the configured base path are allowed.');
        }

        $pathPatternRelative = FilePathDataType::normalize($pathPatternRelative, '/');
        $this->checkPathAllowed($pathPatternRelative, $prompt);

        $staticPrefix = preg_split('/[*?\[\]{}]/', $pathPatternRelative, 2)[0] ?? '';
        if ($staticPrefix === '') {
            $staticPrefix = '.';
        }

        $absolutePrefix = FilePathDataType::normalize(
            FilePathDataType::makeAbsolute($staticPrefix, $basePath, DIRECTORY_SEPARATOR),
            DIRECTORY_SEPARATOR
        );

        if (! $this->checkPathInsideBasePath($absolutePrefix, $basePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: resolved folder is outside the configured base path.');
        }

        return trim($pathPatternRelative, '/');
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