<?php
namespace axenox\GenAI\Interfaces;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
interface AiConceptInterface extends PlaceholderResolverInterface, iCanBeConvertedToUxon
{
    public function setOutput(string $output) : AiConceptInterface;
}