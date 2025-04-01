<?php
namespace axenox\GenAI\Common;


/**
 * Base class for reading documents
 */
class FileReader 
{
    public function readFile(string $filePath): string {
        if (! file_exists($filePath)) {
            return 'ERROR: file not found!';
        }
        $md = file_get_contents($filePath);
        return $md;
    }
}
