<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use exface\Core\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;

/**
 * Generic chat assistant with configurable system prompt
 * 
 * ## Examples
 * 
 * ```
 * {
 *   "system_prompt": "
 *      You are a helpful assistant, who will answer questions about the structure of the following database. 
 *      Here is the DB schema in DBML: \n\n[#metamodel_dbml#]
 *      Answer using the following locale \"[#=User('LOCALE')#]\"
 *   ",
 *   "system_concepts": {
 *     "metamodel_bmdb": {
 *       "class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept",
 *       "object_filters": {
 *         "operator": "AND",
 *         "conditions": [
 *           {"expression": "APP__ALIAS", "comparator": "==", "value": "exface.Core"}
 *         ]
 *       }
 *     }
 *   }
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 */
class GenericAssistant implements AiAgentInterface
{
    use ImportUxonObjectTrait;

    use AliasTrait;

    private $workbench = null;

    private $systemPrompt = null;

    private $systemPromptRendered = null;

    private $concepts = [];

    private $dataConnectionAlias = null;

    private $dataConnection = null;

    private $name = null;

    private $selector = null;

    private $agentDataSheet = null;

    /**
     * 
     * @param \exface\Core\Interfaces\Selectors\AiAgentSelectorInterface $selector
     * @param \exface\Core\CommonLogic\UxonObject|null $uxon
     */
    public function __construct(AiAgentSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->workbench = $selector->getWorkbench();
        $this->selector = $selector;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        $userPromt = $prompt->getUserPrompt();
        $this->setInstructions($this->getSystemPrompt($prompt));

        $query = new OpenAiApiDataQuery($this->workbench);
        $query->setSystemPrompt($this->systemPrompt);
        $query->appendMessage($userPromt);
        if (null !== $val = $prompt->getConversationUid()) {
            $query->setConversationUid($val);
        }

        $performedQuery = $this->getConnection()->query($query);
        $this->saveConversation($prompt, $performedQuery);

        return $this->parseDataQueryResponse($prompt, $performedQuery);
    }

    public function saveConversation(AiPromptInterface $promt, AiQueryInterface $query) : self
    {
        $transaction = $this->workbench->data()->startTransaction();
        $sequenceNumber = $query->getSequenceNumber();
        try{
            $conversationId = $promt->getConversationUid();
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
            if($conversationId === null) {
                $conversation = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
                $conversation->addRow([
                    'AI_AGENT' => $this->getUid(),
                    'META_OBJECT' => $promt->getMetaObject()->getId(),
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'TITLE' => StringDataType::truncate($promt->getUserPrompt(), 50, true, true, true),
                ]);
                $conversation->dataCreate(false,$transaction);
                $conversationId = $conversation->getUidColumn()->getValue(0);

                $message->addRow([
                    'AI_CONVERSATION' => $conversationId,
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'ROLE'=> AiMessageTypeDataType::SYSTEM,
                    'MESSAGE'=> $this->systemPrompt,
                    'SEQUENCE_NUMBER' => $sequenceNumber++
                ]);
            }

            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::USER,
                'MESSAGE'=> $query->getUserPrompt(),
                'SEQUENCE_NUMBER' => $sequenceNumber++
            ]);
            
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::ASSISTANT,
                'MESSAGE'=> $query->getAnswer(),
                'SEQUENCE_NUMBER' => $sequenceNumber,
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST_PER_M_TOKENS'=> $query->getCostPerMTokens(),
                'COST' => ($query->getTokensInPrompt() + $query->getTokensInAnswer()) * $query->getCostPerMTokens() * 0.000001
            ]);
            $message->dataCreate(false,$transaction);
            
            $transaction->commit();
        } catch(\Throwable $e){
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
        return $this;
    }

    /**
     * AI concepts to be used in the system prompt
     * 
     * Each concept is basically a plugin, that generates part of the system prompt. You can use it anywhere in your
     * prompt via placeholder
     * 
     * @uxon-property concepts
     * @uxon-type \axenox\GenAI\Common\AbstractConcept
     * @uxon-template {"metamodel_bmdb": {"class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept"}}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $arrayOfConcepts
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setConcepts(UxonObject $arrayOfConcepts) : AiAgentInterface
    {
        foreach ($arrayOfConcepts as $placeholder => $uxon) {
            $this->concepts[] = AiFactory::createConceptFromUxon($this->workbench,$placeholder, $uxon);
        }
        return $this;
    }

    /**
     * 
     * @return array
     */
    protected function getConcepts(AiPromptInterface $promt) : array
    {
        return $this->concepts;
    }

    /**
     * An introduction to explain the LLM, what the assistant is supposed to do
     * 
     * @uxon-property instructions
     * @uxon-type string
     * @uxon-template You are a helpful assistant, who will answer questions about the structure of the following database. Here is the DB schema in DBML: \n\n[#metamodel_dbml#] \n\nAnswer using the following locale [#=User('LOCALE')#]
     * 
     * @param string $text
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setInstructions(string $text) : AiAgentInterface
    {
        $this->systemPrompt = $text;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $promt
     * @return string
     */
    protected function getSystemPrompt(AiPromptInterface $prompt) : string
    {
        if ($this->systemPromptRendered === null) {
            $renderer = new BracketHashStringTemplateRenderer($this->workbench);
            $renderer->addPlaceholder(new FormulaPlaceholders($this->workbench, null, null, '='));
            $renderer->addPlaceholder(new ConfigPlaceholders($this->workbench, '~config:'));
            
            foreach ($this->getConcepts($prompt) as $concept) {
                $renderer->addPlaceholder($concept);
            }
            
            $this->systemPromptRendered = $renderer->render($this->systemPrompt ?? '');
        }
        return $this->systemPromptRendered;
    }

    /**
     * 
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = new UxonObject();
        // TODO
        return $uxon;
    } 

    /**
     * 
     * @return \exface\Core\Interfaces\DataSources\DataConnectionInterface
     */
    protected function getConnection() : DataConnectionInterface
    {
        if ($this->dataConnection === null) {
            $this->dataConnection = DataConnectionFactory::createFromModel($this->workbench, $this->dataConnectionAlias);
        }
        return $this->dataConnection;
    }
    
    /**
     * 
     * @param string $selector
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setDataConnectionAlias(string $selector) : AiAgentInterface
    {
        $this->dataConnectionAlias = $selector;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery $query
     * @return \axenox\GenAI\Common\AiResponse
     */
    protected function parseDataQueryResponse(AiPromptInterface $prompt, OpenAiApiDataQuery $query) : AiResponse
    {
        return new AiResponse($prompt, $query->getAnswer());
    }

    /**
     * 
     * @param string $alias
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setAlias(string $alias) : AiAgentInterface
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\Selectors\AliasSelectorInterface
     */
    public function getSelector() : AliasSelectorInterface
    {
        return $this->selector;
    }

    /**
     * 
     * @param string $name
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setName(string $name) : AiAgentInterface
    {
        $this->name = $name;
        return $this;
    }

    protected function getModelData() : DataSheetInterface
    {
        if ($this->agentDataSheet === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT');
            $sheet->getColumns()->addFromSystemAttributes();
            $sheet->getColumns()->addMultiple([
                'NAME'
            ]);
            $sheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $this->getSelector()->toString(), ComparatorDataType::EQUALS);
            $sheet->dataRead();
            $this->agentDataSheet = $sheet;
        }
        return $this->agentDataSheet;
    }

    public function getUid() : string
    {
        return $this->getModelData()->getCellValue('UID', 0);
    }

    public function getName() : string
    {
        return $this->getModelData()->getCellValue('NAME', 0);
    }
}