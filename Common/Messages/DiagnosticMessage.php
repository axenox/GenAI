<?php
namespace axenox\GenAI\Common\Messages;

/**
 * A message for designers only - e.g. a warning or an error. It is never sent to an LLM.
 * 
 * The conversation also uses this class for unknown message types found in the history, so
 * that they remain visible to designers without confusing any API adapter.
 * 
 * @author Andrej Kabachnik
 */
class DiagnosticMessage extends AbstractAiMessage
{
    /**
     * @param string $type AiMessageTypeDataType value - e.g. WARNING or ERROR
     * @param string $text
     */
    public function __construct(string $type, string $text)
    {
        parent::__construct($type, $text);
    }
}
