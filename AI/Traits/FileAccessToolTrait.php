<?php
namespace axenox\GenAI\AI\Traits;

use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;

/**
 * Shared file access configuration and path validation for AI file tools.
 */
trait FileAccessToolTrait
{
    /** @var string|null */
    private ?string $basePath = null;

    /** @var bool */
    private bool $useVendorFolderAsBase = true;

    /** @var string[] */
    private array $allowedPaths = [];

    /**
     * Base path for all files.
     *
     * If this value is relative, it is resolved against the default base path
     * selected by `use_vendor_folder_as_base`.
     *
     * @uxon-property base_path
     * @uxon-type string
     *
     * @param string $value
     * @return self
     */
    protected function setBasePath(string $value): self
    {
        $this->basePath = FilePathDataType::normalize($value, DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * Use the installation vendor folder as base path.
     *
     * @uxon-property use_vendor_folder_as_base
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return self
     */
    protected function setUseVendorFolderAsBase(bool $value): self
    {
        $this->useVendorFolderAsBase = $value;
        return $this;
    }

    /**
     * Restrict accessible files using wildcard patterns.
     *
     * Patterns are matched against the normalized relative path (using `/` as
     * separator) with `FilePathDataType::matchesPattern()`.
     *
     * @uxon-property allowed_paths
     * @uxon-type string[]
     * @uxon-template ["exface/*.md"]
     *
     * @param UxonObject $value
     * @return self
     */
    protected function setAllowedPaths(UxonObject $value): self
    {
        $this->allowedPaths = [];
        foreach ($value as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $this->allowedPaths[] = FilePathDataType::normalize($pattern, '/');
        }
        return $this;
    }

    /**
     * @return string
     */
    protected function getBasePathAbsolute(): string
    {
        $fm = $this->getWorkbench()->filemanager();

        if ($this->useVendorFolderAsBase === true) {
            $defaultBasePath = $fm->getPathToVendorFolder();
        } else {
            $defaultBasePath = $fm->getPathToBaseFolder();
        }

        if ($this->basePath === null || trim($this->basePath) === '') {
            return FilePathDataType::normalize($defaultBasePath, DIRECTORY_SEPARATOR);
        }

        if (FilePathDataType::isAbsolute($this->basePath)) {
            return FilePathDataType::normalize($this->basePath, DIRECTORY_SEPARATOR);
        }

        return FilePathDataType::normalize(
            FilePathDataType::makeAbsolute($this->basePath, $defaultBasePath, DIRECTORY_SEPARATOR),
            DIRECTORY_SEPARATOR
        );
    }
    
    protected function getPathAbsolute(string $relativePath, string $basePath, AiPromptInterface $prompt) : string
    {
        if ($relativePath === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid arguments: missing target folder path.');
        }
        $lastChar = mb_substr($relativePath, -1);
        $relativePath = FilePathDataType::normalize($relativePath, '/');
        if ($lastChar === '/' || $lastChar === '\\') {
            $relativePath .= '/';
        }

        if (FilePathDataType::isAbsolute($relativePath)) {
            throw new AiToolRuntimeError($this, $prompt, 'Invalid path: only paths relative to the configured base path are allowed.');
        }
        
        $this->checkPathAllowed($relativePath, $prompt);

        $absolutePath = FilePathDataType::makeAbsolute($relativePath, $basePath, DIRECTORY_SEPARATOR);
        $this->checkPathInsideBasePath($absolutePath, $basePath);
        
        return $absolutePath;
    }

    /**
     * @param string $absolutePath
     * @param string $basePath
     * @return bool
     */
    protected function checkPathInsideBasePath(string $absolutePath, string $basePath): bool
    {
        $commonBase = FilePathDataType::findCommonBase([$absolutePath, $basePath]);
        if ($commonBase === null) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($commonBase, $basePath) === 0;
        }

        return $commonBase === $basePath;
    }

    /**
     * @param string $absolutePath
     * @param string $basePath
     * @throws RuntimeException
     * @return string
     */
    protected function makeRelativePath(string $absolutePath, string $basePath): string
    {
        $baseNorm = FilePathDataType::normalize($basePath, '/');
        $targetNorm = FilePathDataType::normalize($absolutePath, '/');
        $needle = rtrim($baseNorm, '/') . '/';
        $relative = StringDataType::substringAfter($targetNorm, $needle, null);
        if ($relative === null || $relative === '') {
            throw new RuntimeException('Cannot derive relative path from "' . $targetNorm . '" and base "' . $baseNorm . '".');
        }
        return ltrim($relative, '/');
    }

    /**
     * @param string $relativePath
     * @return void
     */
    protected function checkPathAllowed(string $relativePath, AiPromptInterface $prompt): void
    {
        if (empty($this->allowedPaths)) {
            return;
        }

        foreach ($this->allowedPaths as $pattern) {
            if (FilePathDataType::matchesPattern($relativePath, $pattern)) {
                return;
            }
        }

        throw new AiToolRuntimeError($this, $prompt, 'Invalid path: folder path does not match any allowed_paths pattern.');
    }
}