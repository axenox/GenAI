<?php
namespace axenox\GenAI\Common;

use exface\Core\Interfaces\Selectors\SelectorInterface;
use exface\Core\Interfaces\InstallerContainerInterface;
use exface\Core\CommonLogic\AppInstallers\DataInstaller;
use exface\Core\CommonLogic\AppInstallers\MetaModelInstaller;

/**
 * Makes sure data flows and their steps are exported with the apps metamodel
 * 
 * @author Andrej Kabachnik
 *
 */
class AiAgentInstaller extends DataInstaller
{
    /**
     * 
     * @param SelectorInterface $selectorToInstall
     * @param InstallerContainerInterface $installerContainer
     */
    public function __construct(SelectorInterface $selectorToInstall)
    {
        parent::__construct($selectorToInstall, MetaModelInstaller::FOLDER_NAME_MODEL . DIRECTORY_SEPARATOR . 'AI');
        
        $this->addDataToReplace('axenox.GenAI.AI_AGENT', 'CREATED_ON', 'APP', [], '[#ALIAS#]/01_AI_AGENT.json');
        $this->addDataToReplace('axenox.GenAI.AI_AGENT_VERSION', 'CREATED_ON', 'AI_AGENT__APP', [], '[#AI_AGENT__ALIAS#]/02_AI_AGENT_VERSION.json');
        $this->addDataToReplace('axenox.GenAI.AI_TEST_CASE', 'CREATED_ON', 'APP', [], '[#AI_AGENT__ALIAS#]/03_AI_TEST_CASE.json');
    }
}