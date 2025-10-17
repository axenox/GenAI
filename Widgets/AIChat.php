<?php
namespace axenox\GenAI\Widgets;

use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Facades\AiChatFacade;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Widgets\InputCustom;
use exface\Core\Interfaces\Widgets\iFillEntireContainer;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;

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

    private array $promptSuggestionsWidget = [];

    private ?AiAgentInterface $agent = null;

    private $buttons = [];

    private ?UxonObject $resetButton = null;

    private ?UxonObject $feedbackButton = null;

    private string $introMessage = '';


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
            $requestDataJs = <<<JS
                requestDetails.body.data = {
                    oId: "{$this->getMetaObject()->getId()}", 
                    rows: [
                        { {$this->getAttributeAlias()}: $("#{$this->getId()}").data("exf-value") }
                    ]
                }
    JS;

     
        }

        $suggestionsHtml = $this->getSuggestionsHTML();
        $top = $this->getButtonsHTML('top');
        $botton = $this->getButtonsHTML('botton');
        $introMessage = $this->getIntroMessage();


        //Mögliches To Do https://deepchat.dev/docs/messages/styles && https://deepchat.dev/examples/design 
        //Möglichkeit geben den Style der bubble anzupassen?
        
        return <<<HTML

        <div class="deep-chat-wrapper">
            <div
            class='exf-aichat-top'
            style="display:flex; flex-direction:row; gap:8px; align-items:center;">
                {$top}
            </div>
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

                introMessage='{$introMessage}'
            ></deep-chat>

            <div
            class='exf-aichat-top'
            style="display:flex; flex-direction:row; gap:8px; align-items:center;">
                {$botton}
            </div>

            
        </div>

        <script>

            const chat = document.getElementById('{$this->getId()}');

            let historyInitDone = false;

            chat.addEventListener('render', () => {               // Deep Chat ist fertig
                if (historyInitDone) return;
                historyInitDone = true;

                chat.history = [
                {html: `$suggestionsHtml`, role: 'user'}
                ];
            });
            function resetDeepChat(chatId) {
                var domEl = document.getElementById(chatId);
                if (domEl) {
                    domEl.conversationId = null;
                    domEl.clearMessages();
                    chat.history = [
                    {html: `$suggestionsHtml`, role: 'user'}
                    ];
                    
                }
            }
        </script>
    HTML;
    }

    protected function getSuggestionsHTML() : string 
    {
        $suggestions = $this->getPromptSuggestions();
        if(count($suggestions) > 0){
            $buttons = [];
            foreach ($suggestions as $i => $s){
                $buttons [] = ' <button class="deep-chat-button deep-chat-suggestion-button" style="margin-top:5px">'.$s.'</button>';
            }
            return '<div class=\"deep-chat-temporary-message\">' . implode("\n", $buttons) . '</div>';
        }else{
            return "";
        } 
    }

    protected function getButtonsHTML(string $position) : string 
    {
        $buttons = [];
        foreach($this->buttons as $btn){
            $buttons [] = $btn($position);
        }
        return implode("\n", $buttons);
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
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property prompt_suggestions
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template [""]
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setPromptSuggestions(UxonObject $suggestions) : AIChat
    {
        $array = $suggestions->getPropertiesAll();
        foreach ($array as $s) {
            if (!is_string($s)) {
                
                continue;
            }
        }

        $this->promptSuggestionsWidget = $array;
        return $this;
    }

    protected function getPromptSuggestionAgent() : array 
    {
        if(!$this->agent){
            $this->agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->getAgentAlias());
        }
        return $this->agent->getPromptSuggestions();
    }

    public function getPromptSuggestions() : array
    {
        $all = array_merge(
        $this->promptSuggestionsWidget ?? [],
        $this->getPromptSuggestionAgent() ?? []
        );

        return $all;

    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property reset_button
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template {"enabled": true,"position": "top"}
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setResetButton(UxonObject $var) : AIChat
    {
        $this->resetButton = $var;
        $this->buttons[] = [$this,'getResetButtonHTML'];
        return $this;
    }

    protected function getResetButtonHTML(string $position) : string
    {
        $properties = $this->resetButton->getPropertiesAll();

        $propPosition = strtolower($properties['position'] ?? '');
        $enabled = $properties['enabled'] ?? true;
        $order = ($properties['order'] ?? '1');

        if ($propPosition === strtolower($position) && $enabled) {
            return <<<HTML
                    <button style="order : {$order}" type="button" onclick="resetDeepChat('{$this->getId()}')">Reset</button>
                    HTML;
                        }

        return '';
    }


    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property feedback_window
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template {"enabled": true,"position": "top"}
     * 
     * @param UxonObject $alias
     * @return AIChat
     */
    protected function setFeedbackWindow(UxonObject $var) : AIChat
    {
        $this->feedbackButton = $var;
        
        return $this;
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property intro_message
     * @uxon-type string
     * @uxon-required true
     * 
     * 
     * @param string $alias
     * @return AIChat
     */
    protected function setIntroMessage(string $var) : AIChat
    {
        $this->introMessage = $var;
        
        return $this;
    }

    protected function getIntroMessage() : string
    {
        return '{"text": "' . $this->introMessage . '"}';
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
        $test = $this->buildHtmlDeepChat();
        return $this->buildHtmlDeepChat();
    }
}