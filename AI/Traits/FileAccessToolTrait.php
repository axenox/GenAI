<?php
namespace axenox\GenAI\AI\Traits;

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

    /**
     * @param string $absolutePath
     * @param string $basePath
     * @return bool
     */
    protected function isInsideBasePath(string $absolutePath, string $basePath): bool
    {
        $baseNorm = FilePathDataType::normalize($basePath, '/');
        $targetNorm = FilePathDataType::normalize($absolutePath, '/');
        $commonBase = FilePathDataType::findCommonBase([$baseNorm, $targetNorm]);
        if ($commonBase === null) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($commonBase, $baseNorm) === 0;
        }

        return $commonBase === $baseNorm;
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
     * @return bool
     */
    protected function isAllowedPath(string $relativePath): bool
    {
        if (empty($this->allowedPaths)) {
            return true;
        }

        $path = FilePathDataType::normalize($relativePath, '/');
        foreach ($this->allowedPaths as $pattern) {
            if (FilePathDataType::matchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }
}