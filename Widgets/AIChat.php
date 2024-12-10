<?php
namespace axenox\GenAI\Widgets;

use axenox\GenAI\Facades\AiChatFacade;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Widgets\InputCustom;
use exface\Core\Interfaces\Widgets\iFillEntireContainer;

/**
 * 
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class AIChat extends InputCustom implements iFillEntireContainer
{
    private $aiChatFacade = null;

    private $agentAlias = null;

    protected function buildHtmlDeepChat() : string
    {
        return <<<HTML

        <deep-chat 
            id='{$this->getId()}'
            class='exf-aichat'
            connect='{
                "url": "{$this->getAiChatFacade()->buildUrlToFacade()}/{$this->getAgentAlias()}/deepchat",
                "method": "POST",
                "additionalBodyProps": {
                    "object": "{$this->getMetaObject()->getAliasWithNamespace()}",
                    "page": "{$this->getPage()->getAliasWithNamespace()}"
                }
            }'
            responseInterceptor  = 'function (message) {
                var domEl = document.getElementById({$this->getId()});
                domEl.conversationId = message.conversation; 
                return message; 
            }'

            requestInterceptor = 'function (requestDetails) {
                var domEl = document.getElementById({$this->getId()});
                requestDetails.body = {
                    prompt: requestDetails.body.messages, 
                    conversation : domEl.conversationId
                }; 
                return requestDetails;
            }'
        ></deep-chat>
HTML;
    }

    protected function setAgentAlias(string $alias) : AIChat
    {
        $this->agentAlias = $alias;
        return $this;
    }

    public function getAgentAlias() : string
    {
        return $this->agentAlias;
    }

    protected function getAiChatFacade() : AiChatFacade
    {
        if ($this->aiChatFacade === null) {
            $this->aiChatFacade = FacadeFactory::createFromString(AiChatFacade::class, $this->getWorkbench());
        }
        return $this->aiChatFacade;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iFillEntireContainer::getAlternativeContainerForOrphanedSiblings()
     */
    public function getAlternativeContainerForOrphanedSiblings() : ?iContainOtherWidgets
    {
        if ($this->getParent() && $this->getParent() instanceof iContainOtherWidgets) {
            return $this->getParent();
        }
        
        return null;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getHtml() : ?string
    {
        return $this->buildHtmlDeepChat();
    }

    public function getHtmlHeadTags(bool $addIncludes = true) : array
    {
        $includes = parent::getHtmlHeadTags();
        array_unshift($includes, '<script type="module" src="vendor/npm-asset/deep-chat/dist/deepChat.bundle.js"></script>');
        return $includes;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getScriptToGetValue() : ?string
    {
        // TODO
        return parent::getScriptToGetValue();
    }
    
    /**
     * 
     * @param string $valueJs
     * @return string|NULL
     */
    public function getScriptToSetValue(string $valueJs) : ?string
    {
        // TODO
        return parent::getScriptToSetValue($valueJs);
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getCssClass() : ?string
    {
        return 'exf-aichat';
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getScriptToEnable() : ?string
    {
        // TODO
        return parent::getScriptToEnable();
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getScriptToDisable() : ?string
    {
        // TODO
        return parent::getScriptToDisable();
    }
    
    /**
     * 
     * @param string $fnOnChangeJs
     * @return string|NULL
     */
    public function getScriptToAttachOnChange(string $fnOnChangeJs) : ?string
    {
        // TODO
        return parent::getScriptToAttachOnChange($fnOnChangeJs);
    }

    public function getScriptToResize() : ?string
    {
        return parent::getScriptToResize() . <<<JS
        
            setTimeout(function(jqSelf){
                var jqParent = jqSelf.parent();
                var iHeightP = jqParent.innerHeight();
                var iWidthP = jqParent.innerWidth();
                if (iHeightP > 0) {
                    jqSelf.height(iHeightP);
                }
                if (iWidthP > 0) {
                    jqSelf.width(iWidthP);
                }
            }, 100, $('#{$this->getId()}'));
JS;
    }
}