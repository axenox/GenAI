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

    private $prompt = Null;

    public function __construct(WorkbenchInterface $workbench, string $placeholder, AiPromptInterface $prompt, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        $this->placeholder = $placeholder;
        $this->prompt = $prompt;
        
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    public function getWorkbench() : WorkbenchInterface
    {
        return $this->workbench;
    }

    protected function getPlaceholder() : string
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
        $uxon = new UxonObject([
            'class' => '\\' . __CLASS__
        ]);
        // TODO
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
}