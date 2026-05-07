<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\TemplateRenderer\PlaceholderValueInvalidError;

/**
 * Includes the contents of markdown files in the rendered instructions of an agent.
 * 
 * Use this concept if part of the agent instructions should be maintained in markdown files instead of
 * being stored inline in the agent configuration. The `file_paths` property accepts paths relative to
 * the vendor folder or absolute file system paths. If `heading_level` is set, the highest heading in
 * every imported file is shifted to that level so the inserted markdown fits into the surrounding prompt.
 * 
 * ## Example
 *
 * Here is an example agent config, that will include Github Copilot instructions. `heading_level` will change the top
 * heading level of the imported file to the given level and adjust all the subheadings consequently.
 * 
 * ```
 * {
 *     "instructions": "You help write SQL migrations\n\n[#migration_instructions#]",
 *     "concepts": {
 *         "migration_instructions": {
 *             "file_paths": [
 *                  "exface/core/.github/instructions/sql-migrations.instructions.md"
 *              ],
 *             "heading_level": 2
 *         }
 *     }
 * }
 * 
 * ```
 */
class MarkdownFilesConcept extends AbstractConcept
{
    private array $filePaths = [];
    private ?string $basePath = null;
    private ?int $headingLevel = null;
    private bool $stripFrontMatter = false;

    /**
     * Renders the configured markdown file contents for this placeholder.
     * 
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractConcept::getOutput()
     */
    protected function getOutput(): string
    {
        if ($this->getFilePaths() === []) {
            throw new AiConceptConfigurationError($this, 'Cannot use the MarkdownFilesConcept without `file_paths`.');
        }

        $markdownChunks = [];
        foreach ($this->getFilePaths() as $path) {
            $markdown = $this->readMarkdownFile($path);
            if (null !== $this->getHeadingLevel()) {
                $markdown = MarkdownDataType::convertHeaderLevels($markdown, $this->getHeadingLevel());
            }
            $markdownChunks[] = trim($markdown);
        }

        return implode(
            "\n\n",
            array_values(array_filter($markdownChunks, static function (string $markdown): bool {
                return $markdown !== '';
            }))
        );
    }

    /**
     * Paths to the markdown files that should be inserted into the instructions.
     * 
     * Paths may be absolute or relative to `base_path`. If `base_path` is not configured, relative paths
     * are resolved against the vendor folder. Files are imported in the same order as they are listed here.
     * 
     * @uxon-property file_paths
     * @uxon-type string[]
     * @uxon-required true
     * @uxon-template ["exface/core/.github/instructions/example.instructions.md"]
     * 
     * @param string|string[]|UxonObject $paths
     * @return \axenox\GenAI\AI\Concepts\MarkdownFilesConcept
     */
    protected function setFilePaths($paths): MarkdownFilesConcept
    {
        if ($paths instanceof UxonObject) {
            $paths = array_values($paths->toArray());
        } elseif (is_string($paths)) {
            $paths = [$paths];
        } elseif (! is_array($paths)) {
            throw new AiConceptConfigurationError($this,'Invalid `file_paths` value for MarkdownFilesConcept: expected a string or an array of strings.');
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new AiConceptConfigurationError($this,'Invalid `file_paths` value for MarkdownFilesConcept: every file path must be a string.');
            }

            $path = trim($path);
            if ($path === '') {
                throw new AiConceptConfigurationError($this, 'Invalid `file_paths` value for MarkdownFilesConcept: empty file paths are not allowed.');
            }

            $normalized[] = $path;
        }

        $this->filePaths = $normalized;
        return $this;
    }

    /**
     * Returns the configured markdown file paths.
     * 
     * @return string[]
     */
    protected function getFilePaths(): array
    {
        return $this->filePaths;
    }

    /**
     * Base folder used to resolve relative markdown file paths.
     * 
     * If this property is not set, the vendor folder is used. The value itself may be absolute or
     * relative to the vendor folder.
     * 
     * @uxon-property base_path
     * @uxon-type string
     * 
     * @param string $pathRelativeOrAbsolute
     * @return \axenox\GenAI\AI\Concepts\MarkdownFilesConcept
     */
    protected function setBasePath(string $pathRelativeOrAbsolute): MarkdownFilesConcept
    {
        $pathRelativeOrAbsolute = trim($pathRelativeOrAbsolute);
        if ($pathRelativeOrAbsolute === '') {
            throw new AiConceptConfigurationError($this, 'Invalid `base_path` value for MarkdownFilesConcept: empty paths are not allowed.');
        }

        $this->basePath = $pathRelativeOrAbsolute;
        return $this;
    }

    /**
     * Returns the configured base path or the vendor folder by default.
     * 
     * @return string
     */
    protected function getBasePath(): string
    {
        return FilePathDataType::makeAbsolute(
            $this->basePath ?? '.',
            $this->getWorkbench()->filemanager()->getPathToVendorFolder(),
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Heading level to apply to the highest heading found in every imported markdown file.
     * 
     * Use this property if the imported markdown should start with `##` or `###` headings when it is
     * embedded below other instruction sections.
     * 
     * @uxon-property heading_level
     * @uxon-type integer
     * 
     * @param int $level
     * @return \axenox\GenAI\AI\Concepts\MarkdownFilesConcept
     */
    protected function setHeadingLevel(int $level): MarkdownFilesConcept
    {
        if ($level < 1) {
            throw new AiConceptConfigurationError($this, 'Invalid `heading_level` value for MarkdownFilesConcept: heading levels must be greater than 0.');
        }

        $this->headingLevel = $level;
        return $this;
    }

    /**
     * Returns the configured target heading level.
     * 
     * @return int|null
     */
    protected function getHeadingLevel(): ?int
    {
        return $this->headingLevel;
    }

    /**
     * Reads a configured markdown file.
     * 
     * @param string $pathRelativeOrAbsolute
     * @return string
     */
    protected function readMarkdownFile(string $pathRelativeOrAbsolute): string
    {
        $filePath = $this->resolveFilePath($pathRelativeOrAbsolute);
        if (! is_file($filePath)) {
            throw new PlaceholderValueInvalidError(
                $this->getPlaceholder(),
                'Markdown file not found: "' . $pathRelativeOrAbsolute . '".',
                null,
                null,
                null,
                $pathRelativeOrAbsolute
            );
        }

        $markdown = file_get_contents($filePath);
        if ($markdown === false) {
            throw new PlaceholderValueInvalidError(
                $this->getPlaceholder(),
                'Markdown file could not be read: "' . $pathRelativeOrAbsolute . '".',
                null,
                null,
                null,
                $pathRelativeOrAbsolute
            );
        }
        
        if ($this->willStripFrontMatter()) {
            $markdown = MarkdownDataType::stripFrontMatter($markdown);
        }

        return $markdown;
    }

    /**
     * Resolves a configured path to an absolute file system path.
     * 
     * Relative paths are resolved against `base_path`, which defaults to the vendor folder.
     * 
     * @param string $pathRelativeOrAbsolute
     * @return string
     */
    protected function resolveFilePath(string $pathRelativeOrAbsolute): string
    {
        return FilePathDataType::makeAbsolute(
            $pathRelativeOrAbsolute,
            $this->getBasePath(),
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Set to TRUE to remove any YAML-style front matter
     * 
     * @uxon-property strip_front_matter
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $trueOrFalse
     * @return $this
     */
    protected function setStripFrontMatter(bool $trueOrFalse): MarkdownFilesConcept
    {
        $this->stripFrontMatter = $trueOrFalse;
        return $this;
    }

    /**
     * @return bool
     */
    protected function willStripFrontMatter() : bool
    {
        return $this->stripFrontMatter;
    }
}