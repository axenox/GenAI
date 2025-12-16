<?php
namespace axenox\GenAI\Common;

use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Uxon\AiConceptUxonSchema;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Interfaces\AiConceptInterface;
use exface\Core\Interfaces\WorkbenchInterface;

abstract class AbstractConcept implements AiConceptInterface
{
    use ImportUxonObjectTrait;

    private $workbench = null;

    private $placeholder = null;

    private $prompt = null;

    private $uxon = null;
    
    private $output = null;

    public function __construct(WorkbenchInterface $workbench, string $placeholder, AiPromptInterface $prompt, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        $this->placeholder = $placeholder;
        $this->prompt = $prompt;
        $this->uxon = $uxon;
        
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     * Resolves the placeholder value for this renderer.
     *
     * Classes that inherit from this one must define the output that will be returned here.
     * If a custom output was already set for example for testing through runTest
     * this method returns that string instead of generating a new result.
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\TemplateRenderers\PlaceholderResolverInterface::resolve()
     */

    public function resolve(array $placeholders) : array
    {
        $phVals = [];
        if($this->hasPrescribedOutput()){
            $phVals[$this->getPlaceholder()] = $this->output;
        }else{
            $phVals[$this->getPlaceholder()] = $this->getOutput();
        }

        return $phVals;
    }
        
    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    public function getPlaceholder() : string
    {
        return $this->placeholder;
    }

    protected function getPrompt() : AiPromptInterface
    {
        return $this->prompt;
    }

    /**
     * 
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        
        $uxon = $this->uxon;
        if(!$uxon->hasProperty("output")){
            $uxon->setProperty(
                "output",
                $this->getOutput()
            );
            //cache output
            $this->setOutput($this->getOutput());
            
        }
        
        return $uxon;
    }

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return AiConceptUxonSchema::class;
    }

    public function getTools() : array
    {
        return [];
    }
    
    public function setOutput(string $output) : AiConceptInterface
    {
        $this->output = $output;
        return $this;
    }

    
    public function hasPrescribedOutput() : bool
    {
        if ($this->output === null) {
            return false;
        } else {
            return true;
        }
    }
    
    abstract public function getOutput() : string;
}