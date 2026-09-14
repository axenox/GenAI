<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\CodeDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\TaskFactory;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Tasks\ResultDataInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\TemplateRenderers\TemplateRendererInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;
use exface\Core\Templates\Placeholders\TranslationPlaceholders;

/**
 * Call a predefined action
 */
class CallActionTool extends AbstractAiTool
{
    private ?UxonObject $actionUxon = null;
    private ?UxonObject $taskUxon = null;
    
    /**
     * @inheritDoc
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $namedArgs = [];
        foreach ($this->getArguments() as $i => $param) {
            $namedArgs[$param->getName()] = $arguments[$i] ?? null;
        }
        $workbench = $this->getWorkbench();
        $renderer = new BracketHashStringTemplateRenderer($workbench);
        $renderer->addPlaceholder(new FormulaPlaceholders($workbench));
        $renderer->addPlaceholder(new TranslationPlaceholders($workbench));
        $renderer->addPlaceholder(new ConfigPlaceholders($workbench));
        $renderer->addPlaceholder(new ArrayPlaceholders($namedArgs));
        $renderer->setIgnoreUnknownPlaceholders(true);
        
        $action = $this->getAction($renderer);
        $task = $this->getTask($renderer);
        
        $result = $action->handle($task);
        
        switch (true) {
            case $result instanceof ResultDataInterface:
                $resultString = $result->getData()->exportUxonObject()->toJson(true);
                $dataType = DataTypeFactory::createFromPrototype($this->getWorkbench(), JsonDataType::class);
                break;
            default:
                $resultString = $result->getMessage();
                $dataType = DataTypeFactory::createFromPrototype($this->getWorkbench(), StringDataType::class);
        }
        
        return new AiToolResultString($this, $arguments, $resultString, $dataType);
    }
    
    protected function getAction(BracketHashStringTemplateRenderer $renderer) : ActionInterface
    {
        $json = $this->actionUxon->toJson();
        $uxon = UxonObject::fromJson($renderer->render($json));
        $action = ActionFactory::createFromUxon($this->getWorkbench(), $uxon);
        return $action;
    }

    /**
     * The action to be called
     * 
     * @uxon-property action
     * @uxon-type \exface\Core\CommonLogic\AbstractAction
     * @uxon-template {"alias": ""}
     * 
     * @param UxonObject $uxon
     * @return $this
     */
    protected function setAction(UxonObject $uxon) : CallActionTool
    {
        $this->actionUxon = $uxon;
        return $this;
    }

    /**
     * The task to be executed
     * 
     * @uxon-property task
     * @uxon-type \exface\Core\CommonLogic\AbstractTask
     * @uxon-template {"alias": ""}
     * 
     * @param BracketHashStringTemplateRenderer $renderer
     * @return $this
     */
    protected function getTask(BracketHashStringTemplateRenderer $renderer) : TaskInterface
    {
        if ($this->taskUxon === null) {
            $task = new GenericTask($this->getWorkbench());
        } else {
            $json = $this->taskUxon->toJson();
            $uxon = UxonObject::fromJson($renderer->render($json));
            $task = TaskFactory::createFromUxon($this->getWorkbench(), $uxon);
            if ($task->hasInputData()) {
                $task->setInputDataTrusted(true);
            }
        }
        return $task;
    }

    /**
     * The task to be called
     *
     * @uxon-property task
     * @uxon-type \exface\Core\CommonLogic\Tasks\GenericTask
     * @uxon-template {"arguments": {"": ""}}
     *
     * @param UxonObject $uxon
     * @return $this
     */
    protected function setTask(UxonObject $uxon) : CallActionTool
    {
        $this->taskUxon = $uxon;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), CodeDataType::class);
    }

    /**
     * @inheritDoc
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        return [];
    }
}