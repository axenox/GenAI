<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface;

/**
 * AI concepts are auto-generated pieces of AI prompts, that add knowledge about the environment around the conversation.
 * 
 * Technically, AI concepts are placeholder resolvers, that get configured in the UXON model of an agent or a skill.
 * They replace the respective placeholder in the prompt with a generated text in the context of the current
 * AI prompt. In the end, the context has access to the agent and the prompt and can use information from them to
 * render the placeholder. 
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiConceptInterface extends PlaceholderResolverInterface, iCanBeConvertedToUxon
{
    public function getPlaceholder(): string;

    /**
     * Returns an array of tool UXONs suggested by this concept
     * 
     * @return UxonObject[]
     */
    public function getToolModels() : array;
}