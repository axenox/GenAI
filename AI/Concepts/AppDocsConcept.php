<?php
namespace axenox\GenAI\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use exface\Core\Exceptions\TemplateRenderer\PlaceholderValueInvalidError;

class AppDocsConcept extends AbstractConcept
{
    private $appAlias = null;

    private $depth = 0;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface::resolve()
     */ 
    public function resolve(array $placeholders) : array
    {
        $phVals = [];
        $phVals[$this->getPlaceholder()] = $this->buildMarkdownDocs();
        return $phVals;
    }

    /**
     * Alias of the app to get the docs from
     * 
     * @uxon-property app_alias
     * @uxon-type metamodel:app
     * 
     * @param string $alias
     * @return \axenox\GenAI\AI\Concepts\AppDocsConcept
     */
    protected function setAppAlias(string $alias): AppDocsConcept
    {
        $this->appAlias = $alias;
        return $this;
    }

    protected function getAppAlias() : string
    {
        return $this->appAlias;
    }

    /**
     * Determines the depth of the file reading
     * 
     * @uxon-property depth
     * @uxon-type number
     * 
     * @param int $depth
     * @return AppDocsConcept
     */
    protected function setDepth(int $depth): AppDocsConcept
    {
        $this->depth = $depth;
        return $this;
    }

    protected function getDepth() : int
    {
        return $this->depth;
    }

    protected function buildMarkdownDocs() : string
    {
        $app = $this->getWorkbench()->getApp($this->getAppAlias());
        $pathToApp = $app->getDirectoryAbsolutePath();
        $pathToDocs = $pathToApp . DIRECTORY_SEPARATOR . 'Docs';
        if (! file_exists($pathToDocs)) {
            throw new PlaceholderValueInvalidError($this->getPlaceholder(), 'Docs not found for app "' . $this->getAppAlias() . '"');
        }
        if ($this->depth < 0 || !file_exists($pathToDocs)) {
            return "";
        }
        $pathToIndex = $pathToDocs . DIRECTORY_SEPARATOR . 'index.md';

        $content = $this->extractLinks($pathToIndex, $pathToDocs, $this->depth);
        
        $baseUrl = $app->getDirectory();
        $content = $this->rebaseRelativeLinks($content, $baseUrl);

        // Tutorials/... -> exface/Core/Docs/Tutorials...
        return $content;
    }

    protected function rebaseRelativeLinks(string $html, string $baseUrl) : string
    {
        $base = rtrim($baseUrl, "/\\") . '/';
        $pattern = '/\(([^\)]+)\)/';
    
        $callback = function ($matches) use ($base) {
            if(str_starts_with($matches[1], 'http'))
                return '(' . $matches[1] . ')';

            $link = $matches[1];
            $newLink = $base . $link;
            return '(' . $newLink . ')';
        };

        $html = preg_replace_callback($pattern, $callback, $html);
        return $html;
    }

    protected function extractLinks($filePath, $basePath, $depth, $currentDepth = 2) {
        if ($depth < 0 || !file_exists($filePath)) {
            return "";
        }
        
        $content = file_get_contents($filePath);
        
        $pattern = '/\[(.*?)\]\((.*?)\)/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        $output = "";
        
        foreach ($matches as $match) {
            $linkedFile = $match[2];
            if (str_starts_with($match[2], 'http')){
                $output .= str_repeat("#", $currentDepth) . "- " . $match[1] . " (" . $linkedFile . ")\n";
                continue;
            }
            if(str_starts_with($match[1], '<kbd>')){
                continue;
            }

            $normalizedPath = dirname($filePath) . DIRECTORY_SEPARATOR . $linkedFile;
            $fullPath = realpath($normalizedPath);

            $relativePath = strstr($fullPath, 'Docs\\') ?: $linkedFile;

            if (isset($processedLinks[$relativePath])) {
                continue;
            }
            $processedLinks[$relativePath] = true;
            
            $output .= str_repeat("#", $currentDepth) . "- " . $match[1] . " (" . $relativePath . ")\n";
            
            if ($fullPath && pathinfo($fullPath, PATHINFO_EXTENSION) === 'md') {
                $output .= $this->extractLinks($fullPath, dirname($fullPath), $depth - 1, $currentDepth + 1);
            }
        }
        
        return $output;
    }
}