<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Exceptions\AiToolRuntimeWarning;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Exceptions\UiPage\UiPageNotFoundError;
use exface\Core\Facades\AbstractHttpFacade\FacadeResolver;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\UiWidgetMarkdownPrinter;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Facades\HtmlPageFacadeInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Psr7\Uri;

/**
 * Returns markdown documentation for a UI page, dialog, or a specific widget.
 *
 * Use this tool when an agent needs structured UI knowledge for a given ExFace URL
 * or page alias. The tool resolves the URL via the facade resolver, loads the target
 * page, and prints widget information using the UI widget markdown printer.
 *
 * Behavior:
 * - If only `url` is provided, the root widget of the resolved page is documented.
 * - If `widget_id` is provided, the tool documents only that widget from the page.
 * - The result is returned as markdown data, suitable for inclusion in agent context.
 *
 * Typical use cases:
 * - Explain what widgets are available on a page before generating actions.
 * - Inspect a specific widget to identify expressions, structure, and configuration.
 * - Provide up-to-date UI context for assistants that need page-aware guidance.
 *
 * Example arguments:
 * - `["exface.core.administration"]`
 * - `["exface.core.objects.html?filter_ALIAS=", "my_widget_id"]`
 */
class UiWidgetInfoTool extends AbstractAiTool
{
    public const ARG_URL = 'url';
    public const ARG_WIDGET_ID = 'widget_id';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $url = trim((string) ($arguments[0] ?? ''));
        $widgetId = null !== ($arguments[1] ?? null) ? trim((string) $arguments[1]) : null;
        if ($url === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: url');
        }
        $uri = new Uri($url);
        
        // Extract page widget from the URL using the same FacadeResolver, that is used in FacadeResolverMiddleware to
        // do the global routing to the correct facade. This ensures, we know exactly which facade is responsible for
        // rendering this URL.
        $resolver = new FacadeResolver($this->getWorkbench(), $uri);
        $page = $resolver->getPage();
        if ($widgetId !== null) {
            $widget = $page->getWidget($widgetId);
        } else {
            $widget = $page->getWidgetRoot();
        }
        
        // Ask the facade, what widget it would render for this URL - that could also be a different one because
        // facades are free to build their URLs as they like. In particular, the UI5 facade has its own complicated
        // routing
        $facade = $resolver->getFacade();
        if ($facade instanceof HtmlPageFacadeInterface) {
            try {
                $widget = $facade->findUrlWidget($uri);
            } catch (UiPageNotFoundError|FacadeRoutingError $e) {
                throw new AiToolRuntimeWarning($this, $prompt, 'Cannot find UI page in URL `' . $url . '`. ' . $e->getMessage());
            }
        }         
        
        $printer = new UiWidgetMarkdownPrinter($widget);
        return new AiToolResultString($this, $arguments, $printer->getMarkdown(), $this->getReturnDataType());
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_URL)
                ->setDescription('URL or page alias')
                ->setRequired(true)
                ->setExamples([
                    'exface.core.administration',
                    'exface.core.administration.html',
                    'exface.core.objects.html?filter_ALIAS='
                ]),
            (new ServiceParameter($self))
                ->setName(self::ARG_WIDGET_ID)
                ->setDescription('Id of the widget on the page if not the root widget is required')
                ->setRequired(false)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}