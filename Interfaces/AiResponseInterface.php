<?php
namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\Tasks\ResultDataInterface;

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

    /** Returns the history owner, if associated with a conversation. */
    public function getConversation() : ?AiConversationInterface;

    /** @return AiMessageInterface[] Messages produced during this execution only. */
    public function getMessages() : array;
    
    public function addOKStatusMessage(string $message) : AiResponseInterface;
    
    public function addErrorStatusMessage(string $message) : AiResponseInterface;

    /**
     * return a html part for deepchat
     * 
     * @return array 
     */
    public function getStatusMessages() : array;
}