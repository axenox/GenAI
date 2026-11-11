<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\ResultData;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\UI5Facade\Facades\UI5Facade;
use axenox\GenAI\Interfaces\AiConversationInterface;

/**
 * Contains an agent answer and the exact generated messages for its response turn.
 * 
 * @author GitHub Copilot
 */
class AiResponse extends ResultData implements AiResponseInterface
{
    private $message = null;
    private $conversationId = null;
    private $rawJson = null;

    private ?AiConversationInterface $conversation = null;
    private array $messages = [];
    
    private array $statusMessages = [];

    /**
     * Associates the answer and optional structured response with the originating prompt.
     * 
     * @param TaskInterface $prompt
     * @param string|null $answer
     * @param string|null $conversationId
     * @param array<string,mixed>|null $rawJson
     * @return void
     */
    public function __construct(TaskInterface $prompt, string $answer = null, ?string $conversationId = null, array $rawJson = null)
    {
        parent::__construct($prompt);
        $this->message = $answer;
        $this->conversationId = $conversationId;
        $this->rawJson = $rawJson;
    }

    /**
     * Returns the structured answer when available, otherwise the response message.
     * 
     * @return array<array-key,mixed>
     */
    public function toArray() : array
    {
        return $this->rawJson ?? $this->message ?? [];
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Tasks\ResultMessage::getMessage()
     */
    public function getMessage() : string 
    {
        return $this->message;
    }
    
    /**
     * Returns the structured answer or a message object for textual answers.
     * 
     * @return array<array-key,mixed>
     */
    public function getJson(): array
    {
        if(!$this->rawJson){
            return ["message" => $this->message];
        }else {
            return $this->rawJson;
        }
    }
    
    /**
     * Returns the persisted conversation identity associated with this answer.
     * 
     * @return string
     */
    public function getConversationId() : string 
    {
        return $this->conversationId;
    }

    /**
     * {@inheritDoc}
     * @see AiResponseInterface::getMessages()
     */
    public function getMessages() : array
    {
        return $this->messages;
    }

    /**
     * {@inheritDoc}
     * @see AiResponseInterface::getConversation()
     */
    public function getConversation() : AiConversationInterface
    {
        if ($this->conversation === null) {
            throw new \LogicException('Unavailable response has no persisted conversation.');
        }
        return $this->conversation;
    }
    
    /**
     * Returns designer-facing status notices separately from the retained answer.
     * 
     * @return list<array{role:string,html:string}>
     */
    public function getStatusMessages(): array
    {
        return $this->statusMessages;
    }
    
    /**
     * Adds a designer-facing status message without changing the retained answer.
     * 
     * @param string $text
     * @param string $color
     * @return self
     */
    protected function addSatusMessage(string $text,string $color) : self
    {
        $this->statusMessages[] = [
            'role' => 'ai',
            'html' => '<div style="color:' . $color . '; font-weight:500;">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>'
        ];;
        return $this;
    }
    
    //TODO Change to Sematic Colors
    /**
     * Adds an error status message to the response.
     * 
     * @param string $message
     * @return self
     */
    public function addErrorStatusMessage(string $message) : self
    {
        $this->addSatusMessage($message, 'red');
        return $this;
    }
    
    //TODO Change to Sematic Colors
    /**
     * Adds a successful status message to the response.
     * 
     * @param string $message
     * @return self
     */
    public function addOKStatusMessage(string $message) : self
    {
        $this->addSatusMessage($message, 'green');
        return $this;
    }

    /**
     * Captures the exact generated message identities for this turn, including live result overlays.
     * 
     * @param AiConversationInterface $conversation
     * @param \axenox\GenAI\Interfaces\AiMessageInterface[] $messages
     * @return AiResponse
     */
    public function setConversation(AiConversationInterface $conversation, array $messages) : AiResponse
    {
        $this->conversation = $conversation;
        $this->messages = $messages;
        return $this;
    }
}