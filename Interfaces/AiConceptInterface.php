<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface;

/**
 * Concept are resolvers for placeholders in an AI system prompt.
 * 
 * These resolvers are instantiated by the agent in context of an AI prompt. Thus, they have access to
 * these two objects and can use them along with their own UXON configuration.
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiConceptInterface extends PlaceholderResolverInterface, iCanBeConvertedToUxon
{
    public function getPlaceholder(): string;
}