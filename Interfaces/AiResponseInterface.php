<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Tasks\ResultDataInterface;
use axenox\GenAI\Common\AiToolCallResponse;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiResponseInterface extends ResultDataInterface
{
    public function toArray() : array;
    
    public function getJson(): array;
    public function getConversationId() : string ;

    //ToolResponse

    /**
     * @return AiToolCallResponse[]
     */
    public function getToolCallResponses(): array;
    
    public function addOKStatusMessage(string $message) : AiResponseInterface;
    
    public function addErrorStatusMessage(string $message) : AiResponseInterface;

    /**
     * return a html part for deepchat
     * 
     * @return array 
     */
    public function getStatusMessages() : array;
}