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
use exface\Core\Contexts\DebugContext;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\AbstractAjaxFacade\AbstractAjaxFacade;
use exface\Core\Facades\AbstractHttpFacade\FacadeResolver;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\UiWidgetMarkdownPrinter;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Facades\HtmlPageFacadeInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\UI5Facade\Facades\Elements\UI5AbstractElement;
use exface\UI5Facade\Exceptions\UI5ControllerNotInitializedException;
use GuzzleHttp\Psr7\Uri;

/**
 * Get UXON models and documentation for a UI page, dialog, or a specific widget.
 *
 * Use this tool when an agent needs structured UI knowledge for a given ExFace URL
 * or page alias. The tool resolves the URL via the facade resolver, loads the target
 * page, validates server-side facade rendering, and prints widget information using
 * the UI widget markdown printer.
 * 
 * Behavior:
 * - If only `url` is provided, the root widget of the resolved page is documented.
 * - If `widget_id` is provided, the tool documents only that widget from the page.
 * - AJAX facades must render the resolved widget without throwing an error.
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
            return $this->createErrorResult($prompt, $arguments, 'Missing required argument: url');
        }
        $uri = $this->normalizePageUri(new Uri($url));
        $widgetMarkdown = null;

        try {
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
            $webapp = null;
            // UI5 normally initializes its Webapp in the HTTP request pipeline; direct validation must do it explicitly.
            if ($facade instanceof AbstractAjaxFacade && method_exists($facade, 'initWebapp') && method_exists($facade, 'getWebapp')) {
                try {
                    $webapp = $facade->getWebapp();
                } catch (\TypeError $e) {
                    $webapp = $facade->initWebapp($page->getAliasWithNamespace());
                }
            }
            if ($widgetId === null && $facade instanceof HtmlPageFacadeInterface) {
                $widget = $facade->findUrlWidget($uri);
            }

            $widgetMarkdown = (new UiWidgetMarkdownPrinter($widget))->getMarkdown();

            // UI5 buildJs() skips webapp root widgets, so build the controller and view directly to validate them.
            if ($webapp !== null && method_exists($webapp, 'getControllerForWidget')) {
                $element = $facade->getElement($widget);
                $controllerExists = false;
                if ($element instanceof UI5AbstractElement) {
                    try {
                        $element->getController();
                        $controllerExists = true;
                    } catch (UI5ControllerNotInitializedException $e) {
                        // The first validation call must create and build the controller.
                    }
                }
                if (! $controllerExists) {
                    $controller = $webapp->getControllerForWidget($widget);
                    $controller->buildJsController();
                    $controller->getView()->buildJsView();
                }
            } elseif ($facade instanceof AbstractAjaxFacade) {
                $facade->buildHtmlHead($widget, true);
                $facade->buildHtmlBody($widget);
            }

            return new AiToolResultString($this, $arguments, $widgetMarkdown, $this->getReturnDataType());
        } catch (ExceptionInterface $e) {
            return $this->createInvalidPageResult(
                $prompt,
                $arguments,
                $url,
                $e,
                $widgetMarkdown
            );
        }
    }

    /**
     * Logs the page error and returns it as an expected validation finding.
     *
     * @param AiPromptInterface $prompt
     * @param array $arguments
     * @param string $url
     * @param ExceptionInterface $pageError
    * @param string|null $widgetMarkdown
     * @return AiToolResultInterface
     */
    private function createInvalidPageResult(
        AiPromptInterface $prompt,
        array $arguments,
        string $url,
        ExceptionInterface $pageError,
        ?string $widgetMarkdown = null
    ): AiToolResultInterface {
        $this->getWorkbench()->getLogger()->logException($pageError);

        $urlMarkdown = MarkdownDataType::escapeString($url);
        $errorMarkdown = MarkdownDataType::escapeString($pageError->getMessage());
        if ($widgetMarkdown === null) {
            $widgetMarkdown = '';
        } else {
            $widgetMarkdown = "---\n\n" . $widgetMarkdown;
        }
        $resultMessage = <<<MD
# Building the widget failed

- **URL:** `{$urlMarkdown}`
- **Error:** {$errorMarkdown}
- **Log-ID:** {$this->buildLogLink($pageError->getId())}

    {$widgetMarkdown}
MD;
        $warning = new AiToolRuntimeWarning($this, $prompt, $resultMessage, null, $pageError);
        // A bad page is a successful validation finding, not evidence that this tool is broken.
        $warning->setLogLevel(LoggerInterface::WARNING);

        return new AiToolResultString(
            $this,
            $arguments,
            $resultMessage,
            $this->getReturnDataType(),
            [],
            [$warning]
        );
    }

    /**
     * Creates and logs a failed tool result.
     *
     * @param AiPromptInterface $prompt
     * @param array $arguments
     * @param string $message
     * @param \Throwable|null $previous
     * @return AiToolResultInterface
     */
    private function createErrorResult(
        AiPromptInterface $prompt,
        array $arguments,
        string $message,
        ?\Throwable $previous = null
    ): AiToolResultInterface {
        $error = new AiToolRuntimeError($this, $prompt, $message, null, $previous);
        $this->getWorkbench()->getLogger()->logException($error);
        $resultMessage = $message . ' See ' . $this->buildLogLink($error->getId()) . '.';
        return new AiToolResultString($this, $arguments, $resultMessage, $this->getReturnDataType(), [], [$error]);
    }

    /**
     * Builds a Markdown link to the debug log entry.
     *
     * @param string $logId
     * @return string
     */
    private function buildLogLink(string $logId): string
    {
        return '[' . $logId . '](' . DebugContext::buildUrlToLogId($logId) . ')';
    }

    /**
    * Converts a relative page alias to the path format expected by the facade resolver.
     *
     * @param Uri $uri
     * @return Uri
     */
    private function normalizePageUri(Uri $uri): Uri
    {
        $path = $uri->getPath();
        if ($path !== '' && $uri->getScheme() === '' && strpos($path, '/') === false) {
            if (! str_ends_with(strtolower($path), '.html')) {
                $path .= '.html';
            }
            return $uri->withPath('/' . $path);
        }
        return $uri;
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