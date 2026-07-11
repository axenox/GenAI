<?php

namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Get detailed information about any model component: object, action, page, etc.
 * 
 * 
 */
class ModelComponentInfoTool extends AbstractAiTool
{
    /**
     *
     * @var string
     */
    const ARG_COMPONENT = 'component';
    
    /**
     *
     * @var string
     */
    const ARG_SELECTOR = 'selector';

    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($component, $selector) = $arguments;
        
        $registry = $this->getWorkbench()->getComponentRegistry();
        switch (true) {
            case null !== $markdown = $registry->getDocsForSelector($component, $selector):
                break;
            default:
                throw new AiToolRuntimeWarning($this, $prompt, 'No documentation found for component ' . $component . ' with selector ' . $selector);
        }
        
        return new AiToolResultString($this, $arguments, $markdown, $this->getReturnDataType());
    }

    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_COMPONENT)
                ->setDescription('Component type (e.g. `action`) or the corresponding')
                ->setDataType(new UxonObject([
                    'alias' => 'exface.Core.GenericStringEnum',
                    'values' => array_combine($workbench->getComponentRegistry()->getComponentKeys(), $workbench->getComponentRegistry()->getComponentKeys())
                ])),
            (new ServiceParameter($self))
                ->setName(self::ARG_SELECTOR)
                ->setDescription('Component selector to describe - typically the alias of the component with an app namespace if available')
                ->setExamples([
                    'exface.Core.MESSAGE',
                    'exface.Core.ReadData'
                ])
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}