<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Tasks\ResultInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiResponseInterface extends ResultInterface
{
    public function getChoices() : array;

    public function toArray() : array;
}