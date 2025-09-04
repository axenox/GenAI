<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;
use exface\Core\Interfaces\Selectors\VersionedSelectorInterface;

/**
 * Interface for AI agent selectors.
 * 
 * An agent can be identified by 
 * - fully qualified alias (with vendor and app prefix)
 * - fully qualified alias with a version or version constraint
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiAgentSelectorInterface extends AliasSelectorInterface, VersionedSelectorInterface
{
    /**
     * Returns the namespaced alias without the version
     * 
     * @return string
     */
    public function getAliasWithNamespace() : string;
}