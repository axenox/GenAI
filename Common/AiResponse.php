<?php
namespace axenox\GenAI\Common;
use exface\Core\CommonLogic\Tasks\ResultData;
use axenox\GenAI\Interfaces\AiResponseInterface;
use axenox\GenAI\Interfaces\AiConversationInterface;
use axenox\GenAI\Interfaces\AiMessageInterface;
use axenox\GenAI\Common\ApiAdapters\AiMessageSnapshot;
use exface\Core\Interfaces\Tasks\TaskInterface;

class AiResponse extends ResultData implements AiResponseInterface
{
    private $message = null;
    private $conversationId = null;
    private $rawJson = null;

    private ?AiConversationInterface $conversation = null;
    /** @var AiMessageInterface[] */
    private array $messages = [];
    
    private array $statusMessages = [];

    public function __construct(TaskInterface $prompt, string $answer = null, ?string $conversationId = null, array $rawJson = null)
    {
        parent::__construct($prompt);
        $this->message = $answer;
        $this->conversationId = $conversationId;
        $this->rawJson = $rawJson;
    }

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
    
    public function getJson(): array
    {
        if(!$this->rawJson){
            return ["message" => $this->message];
        }else {
            return $this->rawJson;
        }
    }
    
    public function getConversationId() : string 
    {
        return $this->conversationId;
    }

    /** Returns the history owner without loading its other turns. */
    public function getConversation() : ?AiConversationInterface
    {
        return $this->conversation;
    }

    /** Attaches the live conversation, including unsaved messages after storage failure. */
    public function setConversation(AiConversationInterface $conversation) : self
    {
        $this->conversation = $conversation;
        $this->conversationId = $conversation->getConversationId();
        return $this;
    }

    /** Returns only messages captured during this execution. */
    public function getMessages() : array
    {
        return array_map(static fn(AiMessageInterface $message) => clone $message, $this->messages);
    }

    /** Captures a detached current-turn message set. */
    public function setMessages(array $messages, array $toolResults = []) : self
    {
        $grouped = $toolResults !== [] && is_array(reset($toolResults));
        $this->messages = array_map(fn(AiMessageInterface $message) => new AiMessageSnapshot(
            $this->getWorkbench(),
            $message,
            $grouped ? ($toolResults[$message->getUid()] ?? []) : $toolResults
        ), $messages);
        return $this;
    }
    
    public function getStatusMessages(): array
    {
        return $this->statusMessages;
    }
    
    protected function addSatusMessage(string $text,string $color) : self
    {
        $this->statusMessages[] = [
            'role' => 'ai',
            'html' => '<div style="color:' . $color . '; font-weight:500;">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>'
        ];;
        return $this;
    }
    
    //TODO Change to Sematic Colors
    public function addErrorStatusMessage(string $message) : self
    {
        $this->addSatusMessage($message, 'red');
        return $this;
    }
    
    //TODO Change to Sematic Colors
    public function addOKStatusMessage(string $message) : self
    {
        $this->addSatusMessage($message, 'green');
        return $this;
    }

}