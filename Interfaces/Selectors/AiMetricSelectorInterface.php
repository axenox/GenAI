<?php
namespace axenox\GenAI\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Interface for AI test metrics selectors.
 * 
 * A tool can be identified by 
 * - file path
 * - qualified class name of the app's PHP class
 * - alias (metric name)
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiMetricSelectorInterface extends PrototypeSelectorInterface, AliasSelectorInterface
{}