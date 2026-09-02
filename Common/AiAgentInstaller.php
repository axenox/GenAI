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
        $this->addDataToReplace('axenox.GenAI.AI_AUTONOMOUS', 'CREATED_ON', 'APP', [], '[#AI_AGENT__ALIAS#]/04_AI_AUTONOMOUS.json');
        $this->addDataToReplace('axenox.GenAI.AI_AGENT_VERSION_SKILL', 'CREATED_ON', 'AI_AGENT_VERSION__AI_AGENT__APP', [], '[#AI_AGENT_VERSION__AI_AGENT__ALIAS#]/05_AI_AGENT_VERSION_SKILL.json');
        $this->addDataToReplace('axenox.GenAI.AI_SKILL', 'CREATED_ON', 'APP', [], 'Skill_[#ALIAS#].json');
    }

    /**
     * Groups separate skill files into one installation sheet and installs it first.
     *
     * @param string $absolutePath
     * @return array
     */
    protected function readDataSheetUxonsFromFolder(string $absolutePath) : array
    {
        $sheets = parent::readDataSheetUxonsFromFolder($absolutePath);
        $groupedSheets = [];

        foreach ($sheets as $key => $sheetUxon) {
            if (
                $sheetUxon->getProperty('object_alias') === 'axenox.GenAI.AI_SKILL'
                && strpos($key, '00_AI_SKILL.json@') !== 0
            ) {
                $key = '00_AI_SKILL.json@' . $key;
            }
            $groupedSheets[$key] = $sheetUxon;
        }

        return $groupedSheets;
    }
}