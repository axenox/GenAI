<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Tasks\ResultInterface;
use axenox\GenAI\Common\AiToolCallResponse;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiResponseInterface extends ResultInterface
{
    public function toArray() : array;
    public function getConversationId() : string ;

    //ToolResponse

    /**
     * @return AiToolCallResponse[]
     */
    public function getToolCallResponses(): array;
}