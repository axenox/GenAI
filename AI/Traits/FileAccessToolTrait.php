<?php
namespace axenox\GenAI\AI\Traits;

use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiPromptInterface;
use exface\Core\CommonLogic\Filesystem\LocalFileInfo;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;

/**
 * Shared file access configuration and path validation for AI file tools.
 * 
 * NOTE: all directory separators are normalized to `/` internally!
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
     * Cache of discovered instruction files: [absolutePath => ['file' => FileInfoInterface, 'frontmatter' => array]].
     * `null` means the vendor folders have not been scanned yet.
     *
     * @var array<string,array>|null
     */
    private static ?array $instructionFiles = null;

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
            return FilePathDataType::normalize($defaultBasePath);
        }

        if (FilePathDataType::isAbsolute($this->basePath)) {
            return FilePathDataType::normalize($this->basePath);
        }

        return FilePathDataType::normalize(
            FilePathDataType::makeAbsolute($this->basePath, $defaultBasePath)
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

        $absolutePath = FilePathDataType::makeAbsolute($relativePath, $basePath);
        $this->checkPathInsideBasePath($absolutePath, $basePath);
        
        return $absolutePath;
    }

    protected function getFileInfo(string $relativePath, string $basePath, AiPromptInterface $prompt) : FileInfoInterface
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
        
        $fileInfo = new LocalFileInfo($relativePath, $basePath, '/');
        $absolutePath = $fileInfo->getPathAbsolute();
        $this->checkPathInsideBasePath($absolutePath, $basePath);

        return $fileInfo;
    }
    
    /**
     * Finds AI instruction files (e.g. `.github/instructions/*.instructions.md`) applicable to the given file.
     *
     * Instruction files are discovered once across all installed packages (cached statically) and matched
     * against the requested file using their `applyTo` frontmatter pattern. An `applyTo` pattern is defined
     * relative to the package containing the instructions (e.g. `Formulas/*.php` in the core app), but is
     * matched against the path of the requested file relative to ITS OWN package. This way the formula
     * instructions from the core also apply to formula classes in other packages.
     *
     * Instruction files without front matter or without an `applyTo` pattern apply to every file - just like
     * `applyTo: '**'` in VS Code.
     *
     * @param FileInfoInterface $fileInfo
     * @return array<string,FileInfoInterface> [absolutePathToInstructionFile => FileInfoInterface]
     */
    protected function findInstructions(FileInfoInterface $fileInfo) : array
    {
        if (self::$instructionFiles === null) {
            $this->loadInstructionFiles();
        }
        
        if (empty(self::$instructionFiles)) {
            return [];
        }

        $absolutePath = FilePathDataType::normalize($fileInfo->getPathAbsolute(), '/');
        $packageRelativePath = $this->getPackageRelativePath($absolutePath);

        $matched = [];
        foreach (self::$instructionFiles as $path => $cached) {
            $applyTo = $cached['frontmatter']['applyTo'] ?? null;
            // No `applyTo` (or an empty one) means the instructions apply to every file - just like in VS Code.
            if ($applyTo === null || $applyTo === '') {
                $matched[$path] = $cached['file'];
                continue;
            }
            $patterns = is_array($applyTo) ? $applyTo : preg_split('/\s*,\s*/', (string) $applyTo);
            foreach ($patterns as $pattern) {
                if ($pattern === '') {
                    continue;
                }
                if (($packageRelativePath !== null && $this->matchesInstructionPattern($packageRelativePath, $pattern))
                    || $this->matchesInstructionPattern($absolutePath, $pattern)
                ) {
                    $matched[$path] = $cached['file'];
                    break;
                }
            }
        }
        return $matched;
    }

    /**
     * Lazily scans all installed packages for instruction files and caches their frontmatter.
     *
     * Only the frontmatter is read here (not the body) to keep the scan as cheap as possible - the body
     * of an instruction file is read on demand once it is known to be applicable.
     *
     * @return void
     */
    protected function loadInstructionFiles() : void
    {
        self::$instructionFiles = [];

        $vendorPath = FilePathDataType::normalize($this->getWorkbench()->filemanager()->getPathToVendorFolder(), '/');
        $vendorNeedle = rtrim($vendorPath, '/') . '/';
        // Structure: vendor/{vendor}/{package}/.github/instructions/{name}.instructions.md
        $pattern = $vendorPath . '/*/*/.github/instructions/*.instructions.md';
        foreach (glob($pattern, GLOB_NOSORT) ?: [] as $file) {
            // A file without front matter (and thus without `applyTo`) applies to every file - just like in VS Code.
            $frontmatter = MarkdownDataType::readFrontMatterFromFile($file) ?? [];
            if (array_key_exists('applyTo', $frontmatter) === true) {
                $applyTo = $frontmatter['applyTo'];
                switch (true) {
                    case is_string($applyTo):
                        $frontmatter['applyTo'] = FilePathDataType::normalize(trim((string) $applyTo), '/');
                        break;
                    // TODO can applyTo be an array?
                    default:
                        throw new RuntimeException('Invalid instruction file "' . $file . '": "applyTo" frontmatter must be a string or array of strings.');
                }
            }
            $absPath = FilePathDataType::normalize($file, '/');
            $relativeToVendor = ltrim(mb_substr($absPath, strlen($vendorNeedle)), '/');
            self::$instructionFiles[$absPath] = [
                'file' => new LocalFileInfo($relativeToVendor, $vendorPath, '/'),
                'frontmatter' => $frontmatter
            ];
        }
    }

    /**
     * Returns the path of a vendor file relative to its package root or `null` if it is not inside a package.
     *
     * E.g. `.../vendor/exface/core/Formulas/Concat.php` becomes `Formulas/Concat.php`.
     *
     * @param string $absolutePath
     * @return string|null
     */
    protected function getPackageRelativePath(string $absolutePath) : ?string
    {
        $vendorPath = FilePathDataType::normalize($this->getWorkbench()->filemanager()->getPathToVendorFolder(), '/');
        $needle = rtrim($vendorPath, '/') . '/';
        if (stripos($absolutePath, $needle) !== 0) {
            return null;
        }
        $relToVendor = ltrim(substr($absolutePath, strlen($needle)), '/');
        $parts = explode('/', $relToVendor);
        if (count($parts) <= 2) {
            return null;
        }
        return implode('/', array_slice($parts, 2));
    }

    /**
     * Matches a path against an `applyTo` glob pattern, also honouring the VS Code `**` prefix semantics.
     *
     * @param string $path
     * @param string $pattern
     * @return bool
     */
    protected function matchesInstructionPattern(string $path, string $pattern) : bool
    {
        if (FilePathDataType::matchesPattern($path, $pattern)) {
            return true;
        }
        // A leading "**/" should also match files located directly at the root (e.g. "**/*.php" matching "Foo.php")
        if (strpos($pattern, '**/') === 0) {
            return FilePathDataType::matchesPattern($path, substr($pattern, 3));
        }
        return false;
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
     * Returns TRUE if the given relative path is allowed by `allowed_paths` (or if no restriction is set).
     *
     * @param string $relativePath
     * @return bool
     */
    protected function isPathAllowed(string $relativePath): bool
    {
        if (empty($this->allowedPaths)) {
            return true;
        }

        foreach ($this->allowedPaths as $pattern) {
            if (FilePathDataType::matchesPattern($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $relativePath
     * @return void
     */
    protected function checkPathAllowed(string $relativePath, AiPromptInterface $prompt): void
    {
        if ($this->isPathAllowed($relativePath)) {
            return;
        }

        throw new AiToolRuntimeError($this, $prompt, 'Invalid path: folder path does not match any allowed_paths pattern.');
    }
}