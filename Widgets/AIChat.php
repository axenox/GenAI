<?php
namespace axenox\GenAI\Widgets;

use axenox\GenAI\Facades\AiChatFacade;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Widgets\InputCustom;
use exface\Core\Interfaces\Widgets\iFillEntireContainer;

/**
 * Chat with AI assistants
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class AIChat extends InputCustom implements iFillEntireContainer
{
    private $aiChatFacade = null;

    private $agentAlias = null;

    protected function init()
    {
        $this->setHideCaption(true);
        $this->setHtmlHeadTags(['<script type="module" src="vendor/npm-asset/deep-chat/dist/deepChat.bundle.js"></script>']);
        $this->setCssClass('exf-aichat');
        $this->setScriptToResize(<<<JS
        
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
JS);
        
        // Get/set value
        $this->setScriptToSetValue("$('#{$this->getId()}').data('exf-value', [#~mValue#])");
        $this->setScriptToGetValue("$('#{$this->getId()}').data('exf-value')");

        // Disable/enable
        $this->setScriptToDisable("$('#{$this->getId()}')[0].disableSubmitButton()");
        $this->setScriptToEnable("$('#{$this->getId()}')[0].disableSubmitButton(false)");
    }

    protected function buildHtmlDeepChat() : string
    {
        if ($this->isBoundToAttribute()) {
            // Use only double quotes here as single quotes produce JS parser errors in the interceptors
            $requestDataJs = <<<JS

                requestDetails.body.data = {
                    oId: "{$this->getMetaObject()->getId()}", 
                    rows: [
                        { {$this->getAttributeAlias()}: $("#{$this->getId()}").data("exf-value") }
                    ]
                }
JS;
        }
        return <<<HTML

        <deep-chat 
            id='{$this->getId()}'
            class='exf-aichat'
            connect='{
                "url": "{$this->getAiChatFacade()->buildUrlToFacade()}/{$this->getAgentAlias()}/deepchat",
                "method": "POST",
                "additionalBodyProps": {
                    "object": "{$this->getMetaObject()->getAliasWithNamespace()}",
                    "page": "{$this->getPage()->getAliasWithNamespace()}",
                    "widget": "{$this->getId()}"
                }
            }'
            responseInterceptor  = 'function (message) {
                var domEl = document.getElementById("{$this->getId()}");
                domEl.conversationId = message.conversation; 
                return message; 
            }'

            requestInterceptor = 'function (requestDetails) {
                var domEl = document.getElementById("{$this->getId()}");
                requestDetails.body.conversation = domEl.conversationId;
                {$requestDataJs};
                return requestDetails;
            }'
        ></deep-chat>
HTML;
    }

    /**
     * Alias of the agent to chat with - with namespace for agents from apps and without for local agents
     * 
     * @uxon-property agent_alias
     * @uxon-type string
     * @uxon-required true
     * 
     * @param string $alias
     * @return AIChat
     */
    protected function setAgentAlias(string $alias) : AIChat
    {
        $this->agentAlias = $alias;
        return $this;
    }

    /**
     * 
     * @return string
     */
    public function getAgentAlias() : string
    {
        return $this->agentAlias;
    }

    /**
     * 
     * @return AiChatFacade
     */
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
     * Override getHtml() here to render the DeepChat domElement after all changes to widget and facade
     * element were definitely applied. Calling it inside init() is too early!
     * 
     * @see \exface\Core\Widgets\InputCustom::getHtml()
     */
    public function getHtml() : ?string
    {
        return $this->buildHtmlDeepChat();
    }
}