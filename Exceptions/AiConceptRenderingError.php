<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\Exceptions\TemplateRenderer\TemplateRendererRuntimeError;

/**
 * Exception thrown if placeholders in the instructions of an AI agents could not be rendered
 * 
 * @author Andrej Kabachnik
 */
class AiConceptRenderingError extends TemplateRendererRuntimeError
{
    
}