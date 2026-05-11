<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Interface for AI concept selectors.
 * 
 * A concept can be identified by 
 * - namespaced alias
 * - file path
 * - qualified class name of the app's PHP class
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiConceptSelectorInterface extends PrototypeSelectorInterface, AliasSelectorInterface
{}