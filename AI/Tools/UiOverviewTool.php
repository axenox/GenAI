<?php
namespace axenox\GenAI\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\UiPageTreeFactory;
use exface\Core\Interfaces\Actions\iShowDialog;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\UiPageTreeNodeInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Widgets\Button;

/**
 * Get an overview of the main menu of an app with all its submenus, available actions, inner dialogs, etc.
 * 
 * This tool is useful to get an overview of the UI of an app. It shows all screens available to the user
 * and describes them briefly. It produces a markdown document with two main parts:
 * 
 * - **Main menu** - the complete server menu (same structure as the `NavMenu` widget) with a link to
 * every page. The links are page URLs, so an agent can pass them to the `UiWidgetInfoTool` to get more
 * details about any page it is interested in.
 * - **Screens of the app of interest** - a detailed chapter for every page of the given app and for every
 * dialog that a user can open from those pages by pressing a button. Each screen chapter lists the meta
 * objects shown on the screen and all buttons available to the user. Dialogs are documented recursively
 * up to the configured `depth`.
 */
class UiOverviewTool extends AbstractAiTool
{
    public const ARG_APP = 'app';
    public const ARG_DEPTH = 'depth';

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $appAlias = trim((string) ($arguments[0] ?? ''));
        if ($appAlias === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: app');
        }
        $depth = (int) ($arguments[1] ?? 5);

        $appOfInterest = $this->getWorkbench()->getApp($appAlias);
        $appAliasNs = $appOfInterest->getAliasWithNamespace();

        // Build the complete main menu the same way the NavMenu widget does when showing all pages -
        // starting from the default server root page and expanding all levels.
        $tree = UiPageTreeFactory::createFromRoot($this->getWorkbench());
        $rootNodes = $tree->getRootNodes();

        $md = '# UI overview of app ' . $appAliasNs . "\n\n";
        $md .= 'The **Main menu** section below lists all pages available in the menu with a link to each page. '
            . 'Use these URLs with the UI widget info tool to get more details about any page. '
            . 'The **Screens** section describes the pages of app `' . $appAliasNs . '` and the dialogs reachable '
            . "from them in more detail.\n\n";

        // Main menu
        $md .= "## Main menu\n\n";
        if (empty($rootNodes)) {
            $md .= "_The menu is empty._\n\n";
        } else {
            $md .= $this->renderMenu($rootNodes, 0) . "\n";
        }

        // Detailed screens of the app of interest
        $appNodes = [];
        $this->collectAppNodes($rootNodes, $appAliasNs, $appNodes);

        $md .= '## Screens of app ' . $appAliasNs . "\n\n";
        if (empty($appNodes)) {
            $md .= "_No menu pages found for this app._\n";
        } else {
            foreach ($appNodes as $node) {
                $md .= $this->describePageNode($node, $depth);
            }
        }

        return new AiToolResultString($this, $arguments, $md, $this->getReturnDataType());
    }

    /**
     * Renders the menu tree as a nested markdown list with a link and description for every page.
     * 
     * @param UiPageTreeNodeInterface[] $nodes
     * @param int $level
     * @return string
     */
    protected function renderMenu(array $nodes, int $level): string
    {
        $md = '';
        $indent = str_repeat('  ', $level);
        foreach ($nodes as $node) {
            $url = $node->getPageAlias() . '.html';
            $line = $indent . '- [' . $node->getName() . '](' . $url . ')';
            $descr = $node->getDescription() ?? $node->getIntro();
            if ($descr !== null && $descr !== '') {
                $line .= ' - ' . $this->oneLine($descr);
            }
            $md .= $line . "\n";
            if ($node->hasChildNodes()) {
                $md .= $this->renderMenu($node->getChildNodes(), $level + 1);
            }
        }
        return $md;
    }

    /**
     * Recursively collects all menu nodes that belong to the given app.
     * 
     * @param UiPageTreeNodeInterface[] $nodes
     * @param string $appAliasNs
     * @param UiPageTreeNodeInterface[] $result
     * @return void
     */
    protected function collectAppNodes(array $nodes, string $appAliasNs, array &$result): void
    {
        foreach ($nodes as $node) {
            try {
                if ($node->hasApp() && strcasecmp($node->getApp()->getAliasWithNamespace(), $appAliasNs) === 0) {
                    $result[] = $node;
                }
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
            }
            if ($node->hasChildNodes()) {
                $this->collectAppNodes($node->getChildNodes(), $appAliasNs, $result);
            }
        }
    }

    /**
     * Describes a single page (its root widget) and all dialogs reachable from it.
     * 
     * @param UiPageTreeNodeInterface $node
     * @param int $depth
     * @return string
     */
    protected function describePageNode(UiPageTreeNodeInterface $node, int $depth): string
    {
        try {
            $page = $node->getPage();
            $rootWidget = $page->getWidgetRoot();
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            return '### Page "' . $node->getName() . "\"\n\n_Could not load page: " . $e->getMessage() . "_\n\n";
        }

        $title = 'Page "' . $node->getName() . '"';
        $context = 'URL: `' . $node->getPageAlias() . '.html`';
        $descr = $node->getDescription() ?? $node->getIntro();
        $visited = [];
        return $this->describeScreen($rootWidget, $title, $context, $descr, 3, $depth, $visited);
    }

    /**
     * Describes a single UI screen (a page root widget or a dialog widget) as a markdown chapter.
     * 
     * Lists the objects shown on the screen and all buttons available to the user. For every button that
     * opens a dialog, the dialog is documented recursively as a nested chapter until `$depth` reaches 0.
     * 
     * @param WidgetInterface $screen
     * @param string $title
     * @param string|null $context
     * @param string|null $description
     * @param int $headingLevel
     * @param int $depth
     * @param string[] $visited
     * @return string
     */
    protected function describeScreen(WidgetInterface $screen, string $title, ?string $context, ?string $description, int $headingLevel, int $depth, array &$visited): string
    {
        $md = str_repeat('#', $headingLevel) . ' ' . $title . "\n\n";
        if ($context !== null && $context !== '') {
            $md .= $context . "\n\n";
        }
        if ($description !== null && $description !== '') {
            $md .= $this->oneLine($description) . "\n\n";
        }

        // Objects shown on this screen
        $objects = $this->collectObjects($screen);
        if (! empty($objects)) {
            $md .= "Objects shown:\n";
            foreach ($objects as $objLine) {
                $md .= '- ' . $objLine . "\n";
            }
            $md .= "\n";
        }

        // Buttons available to the user on this screen
        $buttons = $this->collectButtons($screen);
        $dialogs = [];
        if (! empty($buttons)) {
            $md .= "Buttons:\n";
            foreach ($buttons as $button) {
                $caption = $button->getCaption();
                if ($caption === null || $caption === '') {
                    $caption = $button->getWidgetType();
                }
                $line = '- **' . $this->oneLine($caption) . '**';
                $action = $button->hasAction() ? $button->getAction() : null;
                if ($action !== null) {
                    $line .= ' - action `' . $action->getAliasWithNamespace() . '`';
                    if ($action instanceof iShowDialog) {
                        $line .= ', opens a dialog';
                        try {
                            $dialog = $action->getDialogWidget();
                            if ($dialog !== null) {
                                $dialogs[] = [$button, $dialog];
                            }
                        } catch (\Throwable $e) {
                            $this->getWorkbench()->getLogger()->logException($e);
                        }
                    }
                }
                $md .= $line . "\n";
            }
            $md .= "\n";
        }

        // Recurse into dialogs opened from the buttons of this screen
        if ($depth > 0) {
            foreach ($dialogs as [$button, $dialog]) {
                $dialogId = $dialog->getId();
                if (in_array($dialogId, $visited, true)) {
                    continue;
                }
                $visited[] = $dialogId;
                $dialogCaption = $dialog->getCaption();
                if ($dialogCaption === null || $dialogCaption === '') {
                    $dialogCaption = $button->getCaption() ?? $dialog->getWidgetType();
                }
                $dialogTitle = 'Dialog "' . $this->oneLine($dialogCaption) . '"';
                $btnCaption = $button->getCaption() ?? '';
                $dialogContext = 'Opened from ' . trim($title) . ' via button "' . $this->oneLine($btnCaption) . '"';
                $md .= $this->describeScreen($dialog, $dialogTitle, $dialogContext, null, $headingLevel + 1, $depth - 1, $visited);
            }
        }

        return $md;
    }

    /**
     * Collects all button widgets contained in the given screen widget tree.
     * 
     * Only widgets within the same id space are traversed, so buttons of dialogs opened from this
     * screen are not included here - they are documented separately when the dialog is described.
     * 
     * @param WidgetInterface $screen
     * @return Button[]
     */
    protected function collectButtons(WidgetInterface $screen): array
    {
        $buttons = [];
        foreach ($screen->getChildrenRecursive() as $child) {
            if ($child instanceof Button) {
                $buttons[] = $child;
            }
        }
        return $buttons;
    }

    /**
     * Collects a unique, human readable list of the meta objects shown on the given screen.
     * 
     * @param WidgetInterface $screen
     * @return string[]
     */
    protected function collectObjects(WidgetInterface $screen): array
    {
        $names = [];
        $seen = [];
        $collect = function (WidgetInterface $widget) use (&$names, &$seen) {
            try {
                $obj = $widget->getMetaObject();
            } catch (\Throwable $e) {
                return;
            }
            $key = $obj->getAliasWithNamespace();
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $names[] = $obj->getName() . ' (`' . $key . '`)';
            }
        };
        $collect($screen);
        foreach ($screen->getChildrenRecursive() as $child) {
            $collect($child);
        }
        return $names;
    }

    /**
     * Collapses a multi-line text into a single trimmed line for use in markdown lists.
     * 
     * @param string $text
     * @return string
     */
    protected function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
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
                ->setName(self::ARG_APP)
                ->setDescription('Alias of the app of interest. Its pages will be described in detail, while pages outside of this app only appear in the main menu with their names and URLs.')
                ->setRequired(true)
                ->setExamples([
                    'exface.Core',
                    'axenox.GenAI'
                ]),
            (new ServiceParameter($self))
                ->setName(self::ARG_DEPTH)
                ->setDescription('How deep to follow dialogs opened by buttons inside the pages of the app of interest.')
                ->setDefaultValue(5)
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