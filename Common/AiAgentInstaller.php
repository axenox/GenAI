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
        parent::__construct($selectorToInstall, MetaModelInstaller::FOLDER_NAME_MODEL . DIRECTORY_SEPARATOR . 'ETL');
        
        $this->addDataToReplace('axenox.GenAI.AI_AGENT', 'CREATED_ON', 'APP', [], 'AI/[#alias#]/01_AI_AGENT.json');
    }
}