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
use axenox\GenAI\Widgets\parts\MessageRoles;
use exface\Core\Interfaces\Actions\ActionInterface;

/**
 * Chat with an AI agent inside a page or dialog.
 * 
 * Set `agent_alias` to choose the agent. Place the chat in a split panel to
 * show it alongside other widgets; the chat follows the width of its pane.
 * The toolbar offers New conversation and Rate by default.
 *
 * ```json
 * {
 *     "widget_type": "axenox.GenAI.AIChat",
 *     "object_alias": "exface.Core.DUMMY",
 *     "agent_alias": "axenox.GenAI.workbench_ai_assistant"
 * }
 *
 * ```
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

    private const RATE_ACTION = 'axenox.GenAI.AiConversationRateDialog';

    private bool $allowToStartNewConversation = true;

    private bool $allowToRateConversation = true;

    private bool $chatButtonsInitialized = false;

    private string $introMessage = '';

    private ?UxonObject $messageStyles = null;

    private bool $uploadEnabled = false;

    private array $allowedFileExtensions = [];


    /**
     * {@inheritDoc}
     * @see \exface\Core\Widgets\InputCustom::init()
     */
    protected function init()
    {
        $this->setIncludeJsModules(['vendor/axenox/genai/Facades/js/AIChatMarkdown.js']);
        $this->setCssClass('exf-aichat');
        
        $this->setScriptToResize(<<<JS
        
            setTimeout(function(jqSelf){
                var jqParent = jqSelf.parent();
                var iHeightP = jqParent.innerHeight();
                if (iHeightP > 0 && iHeightP > (jqSelf.height() + 4)) {
                    jqSelf.height(iHeightP);
                }
            }, 100, $('#{$this->getIdOfDeepChat()}'));
JS);
        
        // Get/set value
        $this->setScriptToSetValue("$('#{$this->getIdOfDeepChat()}').data('exf-value', [#~mValue#])");
        $this->setScriptToGetValue("$('#{$this->getIdOfDeepChat()}').data('exf-value')");

        // Disable/enable
        $this->setScriptToDisable($this->buildJsSetSubmitDisabled(true));
        $this->setScriptToEnable($this->buildJsSetSubmitDisabled(false));
    }

    private function buildJsSetSubmitDisabled(bool $disabled) : string
    {
        $chatId = json_encode($this->getIdOfDeepChat());
        $disabledJs = json_encode($disabled);
        return <<<JS
(function (chat) {
    if (!chat) return;
    chat.exfSubmitDisabled = {$disabledJs};
    chat.disableSubmitButton?.(chat.exfSubmitDisabled);
})(document.getElementById({$chatId}));
JS;
    }

    /**
     * Include responsive chat styles even when the widget defines custom CSS.
     *
     * {@inheritDoc}
     * @see \exface\Core\Widgets\InputCustom::getCss()
     */
    public function getCss() : ?string
    {
        $customCss = parent::getCss();

        return <<<CSS
.deep-chat-wrapper > deep-chat {
    display: block;
    box-sizing: border-box;
    width: 100%;
    border: none !important;
}

{$customCss}
CSS;
    }

    protected function getIdOfDeepChat() : string
    {
        return $this->getId() . '_deepchat';
    }

    /**
     * {@inheritDoc}
     */
    public function hasToolbars() : bool
    {
        if (! $this->chatButtonsInitialized) {
            $this->chatButtonsInitialized = true;
            $allowNew = $this->allowToStartNewConversation;
            $allowRate = $this->allowToRateConversation;
            if ($allowNew || $allowRate) {
                $toolbar = $this->getToolbarMain();
                if ($allowNew) {
                    $toolbar->addButton($toolbar->createButton(new UxonObject([
                        'caption' => 'New conversation',
                        'icon' => 'plus',
                        'action' => [
                            'alias' => 'exface.Core.CustomFacadeScript',
                            'script' => "document.getElementById(" . json_encode($this->getIdOfDeepChat()) . ")?.startNewConversation?.();"
                        ]
                    ])));
                }
                if ($allowRate) {
                    $button = $toolbar->createButton(new UxonObject([
                        'object_alias' => 'axenox.GenAI.AI_CONVERSATION',
                        'action' => [
                            'alias' => self::RATE_ACTION,
                            'prefill_data_refresh' => 'always'
                        ]
                    ]));
                    $button->setInputWidget($this);
                    $toolbar->addButton($button);
                }
            }
        }
        return parent::hasToolbars();
    }

    /**
     * Supply only the live conversation UID to the rating dialog. Other actions get the regular input data.
     */
    public function getScriptToGetData(?ActionInterface $action) : ?string
    {
        if ($action === null || strcasecmp($action->getAliasWithNamespace(), self::RATE_ACTION) !== 0) {
            return $action === null ? null : parent::getScriptToGetData($action);
        }
        $chatId = json_encode($this->getIdOfDeepChat());
        $objectId = json_encode($action->getMetaObject()->getId());
        return <<<JS
(function () {
    const uid = document.getElementById({$chatId})?.conversationId;
    return {oId: {$objectId}, rows: uid ? [{UID: uid}] : []};
})()
JS;
    }

    /**
     * Builds the chat element and opts its text messages into Markian rendering.
     */
    protected function buildHtmlDeepChat() : string
    {
        $introMessage = htmlspecialchars($this->getIntroMessage(), ENT_QUOTES, 'UTF-8');
        return <<<HTML

        <div class="deep-chat-wrapper" style="height: 100%">
            <deep-chat 
                mixedFiles='{$this->getMixedFilesAttributeValue()}'
                id='{$this->getIdOfDeepChat()}'
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
                errorMessages='{
                    "displayServiceErrorMessages": true,
                    "overrides": {
                    "default": "Fehler bitte erneut versuchen",
                    "service": "Dienstfehler",
                    "speechToText": "Spracherkennung fehlgeschlagen"
                    }
                }'

                introMessage='{$introMessage}'
                remarkable='{"exfMarkian": true}'
                chatStyle='{"background": "transparent", "border": "none"}'
                messageStyles='{
                    "default": {
                      "shared": {
                        "bubble": { "maxWidth": "90%" }
                      }
                    },
                    "html": {
                        "shared": {
                            "bubble": {
                                "backgroundColor": "unset", 
                                "padding": "0px"
                            }
                        }
                    }
                  }'
            ></deep-chat>

        </div>
    HTML;
    }

    /**
     * Initializes the chat history, reset control and code copy buttons.
     */
    protected function buildJsDeepChatInit() : string
    {
        $suggestions = '';
        foreach ($this->getPromptSuggestions() as $s){
            $suggestions .= ($suggestions ? ', ' : '') . "{ html: `<button class=\"deep-chat-button deep-chat-suggestion-button\" style=\"border-style: dashed\">{$s}</button>`, role: 'ai' }";
        }
        $chatId = json_encode($this->getIdOfDeepChat());
        $requestDataJs = '';
        if ($this->isBoundToAttribute()) {
            $objectId = json_encode($this->getMetaObject()->getId());
            $attribute = json_encode($this->getAttributeAlias());
            $requestDataJs = <<<JS
                requestDetails.body.data = {
                    oId: {$objectId},
                    rows: [{ [{$attribute}]: $(chat).data('exf-value') }]
                };
JS;
        }

        return <<<JS

        (function () { 
            const chat = document.getElementById({$chatId});
            if (!chat) {
              console.error("AIChat element not found in DOM");
              return;
            }
            
            function initChat(chat) {
                if (chat.startNewConversation) return;
                const applySubmitState = () => {
                    if (chat.exfSubmitDisabled !== undefined) {
                        chat.disableSubmitButton(chat.exfSubmitDisabled);
                    }
                };
                chat.addEventListener('render', applySubmitState, {once: true});
                applySubmitState();
                chat.history = [{$suggestions}];
                chat.requestInterceptor = (requestDetails) => {
                    requestDetails.body.conversation = chat.conversationId ?? null;
                    {$requestDataJs}
                    return requestDetails;
                };
                chat.responseInterceptor = (message) => {
                    if (message.errorMessage && !message.error) {
                        message.error = message.errorMessage;
                    }
                    if (!message.error && message.conversation) {
                        chat.conversationId = message.conversation;
                    }
                    return [message, ...(Array.isArray(message.additionalMessages) ? message.additionalMessages : [])];
                };
                chat.startNewConversation = () => {
                    // A late reply stays attached to the old Deep Chat instance.
                    const replacement = chat.cloneNode(false);
                    replacement.exfSubmitDisabled = chat.exfSubmitDisabled;
                    $(replacement).data('exf-value', $(chat).data('exf-value'));
                    chat.copyObserver?.disconnect();
                    initChat(replacement);
                    chat.replaceWith(replacement);
                    {$this->getScriptToResize()}
                };
                initPreCopyObserver(chat);
            }
            
            function addCopyButtonsToPre(root) {
                if (!root || !root.querySelectorAll) {
                    return;
                }
            
                root.querySelectorAll('pre').forEach((pre) => {
                    // Markian may still replace the source block with highlighted code or a diagram.
                    if (pre.dataset.copyButtonInitialized === 'true' || pre.closest('exf-ai-markdown[data-rendering]')) {
                        return;
                    }
                    pre.dataset.copyButtonInitialized = 'true';
            
                    const parent = pre.parentNode;
                    if (!parent) {
                        return;
                    }
                    
                    pre.style.marginTop = '2px';
            
                    const wrapper = document.createElement('div');
                    wrapper.style.display = 'flex';
                    wrapper.style.flexDirection = 'column';
                    wrapper.style.alignItems = 'stretch';
                    wrapper.style.gap = '6px';
                    wrapper.style.margin = '8px 0';
            
                    const toolbar = document.createElement('div');
                    toolbar.style.display = 'flex';
                    toolbar.style.justifyContent = 'flex-end';
                    toolbar.style.alignItems = 'center';
            
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = 'Copy';
                    button.setAttribute('aria-label', 'Code kopieren');
                    button.style.display = 'inline-flex';
                    button.style.alignItems = 'center';
                    button.style.justifyContent = 'center';
                    button.style.height = '28px';
                    button.style.padding = '0 12px';
                    button.style.fontSize = '12px';
                    button.style.fontWeight = '500';
                    button.style.lineHeight = '1';
                    //button.style.boxShadow = '2px 2px 4px rgba(0,0,0,0.35)';
                    button.style.borderRadius = '8px';
                    button.style.cursor = 'pointer';
                    button.style.background = '#111';
                    button.style.color = '#fff';
                    //button.style.boxShadow = '0 2px 8px rgba(0,0,0,0.35)';
                    button.style.border = 'none';
                    button.style.outline = 'none';
                    button.style.backgroundClip = 'padding-box';
                    wrapper.style.gap = '0px';
                    toolbar.style.marginBottom = '2px';
                    
                    button.addEventListener('mouseenter', () => {
                        button.style.background = '#1b1b1b';
                    });
            
                    button.addEventListener('mouseleave', () => {
                        button.style.background = '#111';
                    });
            
                    button.addEventListener('click', async () => {
                        const codeEl = pre.querySelector('code');
                        const textToCopy = codeEl ? codeEl.innerText : pre.innerText.trim();
            
                        try {
                            await navigator.clipboard.writeText(textToCopy);
                            const originalText = button.textContent;
                            button.textContent = 'Copied';
                            setTimeout(() => {
                                button.textContent = originalText;
                            }, 1500);
                        } catch (error) {
                            console.error('Copy failed', error);
                            button.textContent = 'Error';
                            setTimeout(() => {
                                button.textContent = 'Copy';
                            }, 1500);
                        }
                    });
            
                    toolbar.appendChild(button);
            
                    parent.insertBefore(wrapper, pre);
                    wrapper.appendChild(toolbar);
                    wrapper.appendChild(pre);
                });
            }
            
            function initPreCopyObserver(chatEl) {
                if (!chatEl || chatEl.copyObserverInitDone) {
                    return;
                }
            
                const tryAttachObserver = () => {
                    const root = chatEl.shadowRoot;
                    if (!root) {
                        return false;
                    }
            
                    addCopyButtonsToPre(root);
            
                    const observer = new MutationObserver(() => {
                        addCopyButtonsToPre(root);
                    });
            
                    observer.observe(root, {
                        childList: true,
                        subtree: true,
                        attributes: true,
                        attributeFilter: ['data-rendering']
                    });
            
                    chatEl.copyObserverInitDone = true;
                    chatEl.copyObserver = observer;
                    return true;
                };
            
                if (tryAttachObserver()) {
                    return;
                }
            
                const waitForShadowRoot = () => {
                    if (tryAttachObserver()) {
                        return;
                    }
                    if (chatEl.isConnected) setTimeout(waitForShadowRoot, 100);
                };
            
                waitForShadowRoot();
            }
            
            customElements.whenDefined('deep-chat').then(() => {
                if (chat.isConnected) initChat(chat);
            });
        
        })();        


JS;

    }

    /**
     * Alias of the agent to chat with - with namespace for agents from apps and without for local agents
     * 
     * @uxon-property agent_alias
     * @uxon-type metamodel:axenox.GenAI.AI_AGENT:ALIAS_WITH_NS
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
     * @uxon-type array
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

    protected function getPromptSuggestionsFromAgent() : array 
    {
        if(! $this->agent){
            $this->agent = AiFactory::createAgentFromString($this->getWorkbench(), $this->getAgentAlias());
        }
        return $this->agent->getPromptSuggestions();
    }

    public function getPromptSuggestions() : array
    {
        $all = array_merge(
        $this->promptSuggestionsWidget ?? [],
            $this->getPromptSuggestionsFromAgent() ?? []
        );

        return $all;

    }


    /**
     * Show the New conversation button to let users start a separate chat with the same agent.
     * 
     * @uxon-property allow_to_start_new_conversation
     * @uxon-type boolean
     * @uxon-default true
     */
    protected function setAllowToStartNewConversation(bool $value) : AIChat
    {
        $this->allowToStartNewConversation = $value;
        return $this;
    }

    /**
     * Show the Rate button to let users add or edit a rating for the current conversation.
     * 
     * @uxon-property allow_to_rate_conversation
     * @uxon-type boolean
     * @uxon-default true
     */
    protected function setAllowToRateConversation(bool $value) : AIChat
    {
        $this->allowToRateConversation = $value;
        return $this;
    }

    /**
     * Show an introductory Markdown note at the start of each new conversation.
     * 
     * @uxon-property intro_message
     * @uxon-type string
     */
    protected function setIntroMessage(string $var) : AIChat
    {
        $this->introMessage = $var;
        
        return $this;
    }

    /**
     * Enable or disable uploads.
     *
     * @uxon-property upload_enabled
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return AIChat
     */
    public function setUploadEnabled(bool $value) : AIChat
    {
        $this->uploadEnabled = $value;
        return $this;
    }

    /**
     * Configure allowed upload file extensions.
     *
     * @uxon-property allowed_file_extensions
     * @uxon-type array
     * @uxon-required false
     * @uxon-template [".pdf"]
     *
     * @param array $value
     * @return AIChat
     */
    protected function setAllowedFileExtensions(array $value) : AIChat
    {
        $this->allowedFileExtensions = $this->normalizeAllowedFileExtensions($value);
        return $this;
    }

    /**
     * Normalize allowed file extensions to .ext lowercase strings without duplicates.
     *
     * @param mixed $formats
     * @return array
     */
    private function normalizeAllowedFileExtensions($formats) : array
    {
        if (is_string($formats)) {
            $formats = preg_split('/[\s,;]+/', $formats) ?: [];
        }

        if (! is_array($formats)) {
            return [];
        }

        $normalized = [];
        foreach ($formats as $format) {
            if (! is_string($format)) {
                continue;
            }

            $ext = trim(strtolower($format));
            if ($ext === '') {
                continue;
            }
            if ($ext[0] !== '.') {
                $ext = '.' . $ext;
            }
            if (! preg_match('/^\.[a-z0-9]+$/', $ext)) {
                continue;
            }

            $normalized[$ext] = true;
        }

        return array_keys($normalized);
    }

    protected function getMixedFilesAttributeValue() : string
    {
        if (! $this->uploadEnabled) {
            return 'false';
        }

        if (empty($this->allowedFileExtensions)) {
            return 'true';
        }

        $config = [
            'files' => [
                'acceptedFormats' => implode(',', $this->allowedFileExtensions)
            ]
        ];

        return json_encode($config, JSON_UNESCAPED_SLASHES) ?: 'true';
    }

    protected function getIntroMessage() : string
    {
        $text = $this->introMessage;
        if (trim($text) !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $text = "> [!NOTE]\n>\n> " . implode("\n> ", $lines);
        }
        return json_encode(['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * defines examples of suggestions for the Prompt
     * 
     * @uxon-property message_styles
     * @uxon-type UxonObject
     * @uxon-required true
     * @uxon-template {}
     * 
     * @param UxonObject $uxon
     * @return AIChat
     */
    protected function setMessageStyles(UxonObject $var) : AIChat
    {
        //$test = new MessageRoles($this, $var);
        $this->messageStyles = $var;
        return $this;
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

    /**
     *
     * @see InputCustom::getScriptToInit()
     */
    public function getScriptToInit() : ?string
    {
        return $this->buildJsDeepChatInit() . parent::getScriptToInit();
    }
}