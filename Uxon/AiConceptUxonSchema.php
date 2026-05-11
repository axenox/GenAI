<?php
namespace axenox\GenAI\Uxon;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Common\Selectors\AiConceptSelector;
use axenox\GenAI\Exceptions\AiConceptNotFoundError;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Uxon\UxonSchema;

/**
 * UXON-schema class for AI prompt concepts
 *
 * @see UxonSchema for general information.
 *
 * @author Andrej Kabachnik
 *
 */
class AiConceptUxonSchema extends UxonSchema
{
    public static function getSchemaName() : string
    {
        return 'AI concept';
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getPrototypeClass()
     */
    public function getPrototypeClass(UxonObject $uxon, array $path, string $rootPrototypeClass = null) : string
    {
        $class = $rootPrototypeClass ?? $this->getDefaultPrototypeClass();
        
        foreach ($uxon as $key => $value) {
            if (strcasecmp($key, 'class') === 0) {
                $selector = new AiConceptSelector($this->getWorkbench(), $value);
                break;
            }
            if (strcasecmp($key, 'alias') === 0) {
                $selector = new AiConceptSelector($this->getWorkbench(), $value);
                break;
            }
        }
        
        if ($selector !== null) {
            try {
                $class = AiFactory::findConceptClass($selector);
                if ($class !== null && trim($selector->toString()) !== '') {
                    $class = '\\' . ltrim($class, '\\');
                }
            } catch (AiConceptNotFoundError $e) {
                // If the concept class cannot be found, we will just use the default prototype class.
            }
        }
        
        if (count($path) > 1) {
            return parent::getPrototypeClass($uxon, $path, $class);
        }
        
        return $class;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Uxon\UxonSchema::getDefaultPrototypeClass()
     */
    protected function getDefaultPrototypeClass() : string
    {
        return '\\' . AbstractConcept::class;
    }
}