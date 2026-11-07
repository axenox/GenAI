<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\CommonLogic\UxonObject;

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
    public function getUid() : string;
    public function getConversationId() : string;
    public function getSequenceNumber() : int;
    /** Returns a detached semantic payload, independently of monitoring display text. */
    public function getData() : UxonObject;
    /** @return AiToolCallInterface[] */
    public function getCalls() : array;
    /** @return array<string, AiToolResultInterface> */
    public function getToolResults() : array;
    /** Indicates incomplete reconstruction, not execution failure or process liveness. */
    public function isBroken() : bool;
}