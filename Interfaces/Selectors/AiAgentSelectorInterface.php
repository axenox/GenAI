<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Interface for AI agent selectors.
 * 
 * An agent can be identified by 
 * - fully qualified alias (with vendor and app prefix)
 * - file path or qualified class name of the app's PHP class (if there is one)
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiAgentSelectorInterface extends AliasSelectorInterface, PrototypeSelectorInterface
{}