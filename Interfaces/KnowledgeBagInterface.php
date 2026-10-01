<?php
namespace axenox\GenAI\Interfaces;

/**
 * Provides keyed knowledge that can be shared across operations on the same context.
 *
 * @author Andrej Kabachnik
 */
interface KnowledgeBagInterface
{
    /**
     * Returns whether knowledge with the given key is already available.
     *
     * @param string $key
     * @return bool
     */
    public function hasKnowledge(string $key) : bool;

    /**
     * Adds knowledge under the given key.
     *
     * @param string $key
     * @param string $content
     * @return KnowledgeBagInterface
     */
    public function addKnowledge(string $key, string $content) : KnowledgeBagInterface;

    /**
     * Returns all currently available knowledge indexed by key.
     *
     * @return array
     */
    public function getKnowledge() : array;
}