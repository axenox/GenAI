<?php
namespace axenox\GenAI\Common\Messages;

use exface\Core\CommonLogic\Filesystem\InMemoryFile;
use exface\Core\Exceptions\RuntimeException;

/**
 * An attachment of a historical message, that cannot be loaded anymore
 * 
 * The conversation creates it for attachments, that are missing, inaccessible or modified. It
 * keeps the file name and MIME type for designers, but `exists()` returns FALSE and reading it
 * throws an exception. API adapters replace such attachments by a short text note.
 * 
 * @author Andrej Kabachnik
 */
class UnavailableAttachment extends InMemoryFile
{
    private string $reason;

    /**
     * @param string $path
     * @param string|null $mimeType
     * @param string $reason Explanation for designers, why the file is unavailable
     */
    public function __construct(string $path, ?string $mimeType = null, string $reason = 'Attachment unavailable')
    {
        parent::__construct('', $path, $mimeType ?? 'application/octet-stream');
        $this->reason = $reason;
    }

    /**
     * {@inheritDoc}
     * @see InMemoryFile::exists()
     */
    public function exists() : bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     * @see InMemoryFile::isReadable()
     */
    public function isReadable() : bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     * @see InMemoryFile::read()
     */
    public function read() : string
    {
        throw new RuntimeException('Cannot read attachment "' . $this->getFilename() . '": ' . $this->reason);
    }

    /**
     * {@inheritDoc}
     * @see InMemoryFile::getSize()
     */
    public function getSize() : ?int
    {
        return null;
    }

    /**
     * {@inheritDoc}
     * @see InMemoryFile::getMd5()
     */
    public function getMd5() : ?string
    {
        return null;
    }

    /**
     * Returns the explanation, why the file is unavailable
     * 
     * @return string
     */
    public function getReason() : string
    {
        return $this->reason;
    }
}
