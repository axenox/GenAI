<?php
namespace axenox\GenAI\Common;


/**
 * Base class for reading documents
 */
class LinkRebaser 
{
    protected array $processedLinks = [];

    public function getTableOfContents(string $content, string $filePath, string $basePath, int $depth, int $currentDepth = 2): string 
    {
        if ($depth < 0) {
            return "";
        }

        $pattern = '/\[(.*?)\]\((.*?)\)/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        $output = "";

        foreach ($matches as $match) {
            $linkedFile = $match[2];

            if ($this->isExternalLink($linkedFile)) {
                $output .= $this->formatLink($match[1], $linkedFile, $currentDepth);
                continue;
            }
            
            if ($this->isKeyboardShortcut($match[1])) {
                continue;
            }

            $relativePath = $this->getRelativePath($filePath, $linkedFile, $basePath);
            
            if (isset($this->processedLinks[$relativePath])) {
                continue;
            }
            
            $this->processedLinks[$relativePath] = true;
            $output .= $this->formatLink($match[1], $relativePath, $currentDepth);
            
            $fullPath = realpath(dirname($filePath) . DIRECTORY_SEPARATOR . $linkedFile);
            
            if ($fullPath && pathinfo($fullPath, PATHINFO_EXTENSION) === 'md') {
                $fileReader = new FileReader();
                $newContent = $fileReader->readFile($fullPath);
                $output .= $this->getTableOfContents($newContent, $fullPath, dirname($fullPath), $depth - 1, $currentDepth + 1);
            }
        }

        return $output;
    }

    public function rebaseRelativeLinks(string $content, string $filePath, string $basePath, int $depth, int $currentDepth = 2): string
    {

        if ($depth < 0) {
            return "";
        }


        $pattern = '/
            (?P<bang>!)?                              # optional ! fuer Bilder
            \[(?P<text>[^\]]*)\]                      # Linktext
            \(
                \s*(?P<url>[^)\s]+)                   # Ziel ohne Leerzeichen bis Klammer
                (?:\s+"(?P<title>[^"]*)")?            # optionaler Title
            \)
        /x';

        $dirOfFile = dirname($filePath);

        $cb = function(array $m) use ($filePath, $basePath, $dirOfFile) {
            $text  = $m['text'];
            $url   = $m['url'];
            $title = isset($m['title']) ? $m['title'] : null;

            if ($this->isKeyboardShortcut($text)) {
                return $m[0];
            }


            if ($this->isExternalLink($url) || $this->isPureAnchor($url) || $this->isDataLike($url)) {
                return $m[0];
            }


            $fragment = '';
            if (strpos($url, '#') !== false) {
                [$url, $frag] = explode('#', $url, 2);
                $fragment = '#'.$frag;
            }


            $rebased = $this->getRelativePath($filePath, $url, $basePath);


            if (!$rebased || $rebased === $url) {
                $candidate = realpath($dirOfFile . DIRECTORY_SEPARATOR . $url);
                if ($candidate !== false) {
                    $base = 'api' . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $basePath . DIRECTORY_SEPARATOR;
                    $pos = strpos($candidate, 'Docs' . DIRECTORY_SEPARATOR);
                    if ($pos !== false) {
                        $rebased = $base . substr($candidate, $pos);
                    } else {
                        $rebased = $url; 
                    }
                }
            }

            $rebased = str_replace('\\', '/', $rebased);

            $titlePart = $title !== null && $title !== '' ? ' "'.$title.'"' : '';
            $bang = !empty($m['bang']) ? '!' : '';

            return $bang . '['.$text.']('.$rebased.$fragment.$titlePart.')';
        };

        return preg_replace_callback($pattern, $cb, $content);
    }

    protected function isExternalLink(string $link) : bool 
    {
        return str_starts_with($link, 'http');
    }

    protected function isKeyboardShortcut(string $text) : bool 
    {
        return str_starts_with($text, '<kbd>');
    }

    private function isPureAnchor(string $url): bool
    {
        return $url !== '' && $url[0] === '#';
    }

    private function isDataLike(string $url): bool
    {
        return (bool)preg_match('#^(data:|about:)#i', $url);
    }

    protected function formatLink(string $text, string $link, int $depth) : string 
    {
        return str_repeat("#", $depth) . "- " . $text . " (" . $link . ")\n";
    }

    protected function getRelativePath(string $filePath, string $linkedFile, string $basePath) : string 
    {
        $normalizedPath = dirname($filePath) . DIRECTORY_SEPARATOR . $linkedFile;
        $fullPath = realpath($normalizedPath);
        $base = 'api'. DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $basePath . '\\';
        return strstr($fullPath, 'Docs\\') ? $base . strstr($fullPath, 'Docs\\') : $linkedFile;
    }
}
