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
    /**
     * Returns the structured answer when available, otherwise the response message.
     * 
     * @return array<array-key,mixed>
     */
    public function toArray() : array;
    
    /**
     * Returns the structured answer or a message object for textual answers.
     * 
     * @return array<array-key,mixed>
     */
    public function getJson(): array;
    /**
     * Returns the persisted conversation identity associated with this answer.
     * 
     * @return string
     */
    public function getConversationId() : string ;

    /**
     * Returns the authorized durable conversation.
     * 
     * @return AiConversationInterface
     */
    public function getConversation() : AiConversationInterface;

    /**
     * @return AiMessageInterface[] Only entries generated for this response turn.
     */
    public function getMessages() : array;
    
    /**
     * Adds a successful status message to the response.
     * 
     * @param string $message
     * @return AiResponseInterface
     */
    public function addOKStatusMessage(string $message) : AiResponseInterface;
    
    /**
     * Adds an error status message to the response.
     * 
     * @param string $message
     * @return AiResponseInterface
     */
    public function addErrorStatusMessage(string $message) : AiResponseInterface;

    /**
     * return a html part for deepchat
     * 
     * @return array<array-key,mixed> 
     */
    public function getStatusMessages() : array;
}