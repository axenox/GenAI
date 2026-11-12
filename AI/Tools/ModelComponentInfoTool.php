<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\ModelComponentDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Get detailed information about any model component: object, action, page, etc.
 * 
 * ```json
 * {"alias": "axenox.GenAI.ModelComponentInfoTool"}
 * 
 * ```
 * 
 * 
 * 
 * @author GitHub Copilot
 */
class ModelComponentInfoTool extends AbstractAiTool
{
    const ARG_COMPONENT = 'component';
    const ARG_SELECTOR = 'selector';

    /**
     * Executes the configured tool and returns its typed outcome.
     * 
     * @throws AiToolRuntimeWarning
     * @param AiTaskHandlerInterface $agent
     * @param TaskInterface $prompt
     * @param array<array-key,mixed> $arguments
     * @return AiToolResultInterface
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($component, $selector) = $arguments;
        $component = trim(mb_strtolower($component));
        
        $registry = $this->getWorkbench()->getComponentRegistry();
        switch (true) {
            case null !== $markdown = $registry->getDocsForSelector($component, $selector):
                break;
            default:
                throw new AiToolRuntimeWarning($this, $prompt, 'No documentation found for component ' . $component . ' with selector ' . $selector);
        }
        
        return new AiToolResultString($this->getWorkbench(), $markdown, $this->getReturnDataType());
    }

    /**
     * Builds the default argument definitions for this tool.
     * 
     * @param WorkbenchInterface $workbench
     * @return array<array-key,mixed>
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_COMPONENT)
                ->setDescription('Component type (e.g. `action`) or the corresponding')
                ->setDataType(new UxonObject([
                    'alias' => ModelComponentDataType::class
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