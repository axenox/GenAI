<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\DataSources\DataQueryInterface;

/**
 * Interface for messages exchanged with the AI.
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiMessageInterface
{
    public function getText() : string;
    
    public function getRole() : string;
}