<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Interface for AI concept selectors.
 * 
 * A concept can be identified by 
 * - file path
 * - qualified class name of the app's PHP class
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiConceptSelectorInterface extends PrototypeSelectorInterface
{}