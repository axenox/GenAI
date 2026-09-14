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
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\UiPageTreeFactory;
use exface\Core\Interfaces\Actions\iShowDialog;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Model\UiPageTreeNodeInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iHaveContextualHelp;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Widgets\Button;
use exface\Core\Widgets\ButtonGroup;
use exface\Core\Widgets\DataToolbar;
use exface\Core\Widgets\WidgetConfigurator;

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

    private ?AiPromptInterface $activePrompt = null;
    private array $warnings = [];
    private array $warningKeys = [];

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $this->activePrompt = $prompt;
        $this->warnings = [];
        $this->warningKeys = [];
        $appAlias = trim((string) ($arguments[0] ?? ''));
        if ($appAlias === '') {
            throw new AiToolRuntimeError($this, $prompt, 'Missing required argument: app');
        }
        $depth = (int) ($arguments[1] ?? 1);

        $appOfInterest = $this->getWorkbench()->getApp($appAlias);
        $appAliasNs = $appOfInterest->getAliasWithNamespace();

        // Build the complete main menu the same way the NavMenu widget does when showing all pages -
        // starting from the default server root page and expanding all levels.
        try {
            $tree = UiPageTreeFactory::createFromRoot($this->getWorkbench());
            $rootNodes = $tree->getRootNodes();
        } catch (\Throwable $e) {
            $this->addWarning('Could not load the main menu', $e);
            $rootNodes = [];
        }

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

        $result = new AiToolResultString($this, $arguments, $md, $this->getReturnDataType(), [], $this->warnings);
        $this->activePrompt = null;
        return $result;
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
        $pending = [];
        // Reverse before pushing so the explicit LIFO stack preserves the menu's original order.
        foreach (array_reverse($nodes) as $node) {
            $pending[] = [$node, $level];
        }
        $seen = [];
        while (($item = array_pop($pending)) !== null) {
            [$node, $nodeLevel] = $item;
            $nodeKey = spl_object_hash($node);
            // Malformed menu data must not create an endless cycle.
            if (isset($seen[$nodeKey])) {
                continue;
            }
            $seen[$nodeKey] = true;
            try {
                $indent = str_repeat('  ', $nodeLevel);
                $url = $node->getPageAlias() . '.html';
                $line = $indent . '- [' . $node->getName() . '](' . $url . ')';
                $descr = $node->getDescription() ?? $node->getIntro();
                if ($descr !== null && $descr !== '') {
                    $line .= ' - ' . $this->oneLine($descr);
                }
                $md .= $line . "\n";
            } catch (\Throwable $e) {
                $this->addWarning('Could not render a main-menu entry; the entry was skipped', $e);
            }
            try {
                if ($node->hasChildNodes()) {
                    foreach (array_reverse($node->getChildNodes()) as $childNode) {
                        $pending[] = [$childNode, $nodeLevel + 1];
                    }
                }
            } catch (\Throwable $e) {
                $this->addWarning('Could not read child entries from a main-menu entry', $e);
            }
        }
        return $md;
    }

    /**
    * Collects all menu nodes that belong to the given app without recursive calls.
     * 
     * @param UiPageTreeNodeInterface[] $nodes
     * @param string $appAliasNs
     * @param UiPageTreeNodeInterface[] $result
     * @return void
     */
    protected function collectAppNodes(array $nodes, string $appAliasNs, array &$result): void
    {
        // Use an explicit stack to avoid growing the PHP call stack for deeply nested menus.
        $pending = array_reverse($nodes);
        $seen = [];
        while (($node = array_pop($pending)) !== null) {
            $nodeKey = spl_object_hash($node);
            if (isset($seen[$nodeKey])) {
                continue;
            }
            $seen[$nodeKey] = true;
            try {
                if ($node->hasApp() && strcasecmp($node->getApp()->getAliasWithNamespace(), $appAliasNs) === 0) {
                    $result[] = $node;
                }
            } catch (\Throwable $e) {
                $this->addWarning('Could not inspect a menu entry while collecting app pages', $e);
            }
            try {
                if ($node->hasChildNodes()) {
                    foreach (array_reverse($node->getChildNodes()) as $childNode) {
                        $pending[] = $childNode;
                    }
                }
            } catch (\Throwable $e) {
                $this->addWarning('Could not read child entries while collecting app pages', $e);
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
            $this->addWarning('Could not load a page; the page details were skipped', $e);
            return "### Unavailable page\n\n_Could not load this page; rendering continued._\n\n";
        }

        try {
            $title = 'Page "' . $node->getName() . '"';
            $context = 'URL: `' . $node->getPageAlias() . '.html`';
            $descr = $node->getDescription() ?? $node->getIntro();
            $visited = [];
            return $this->describeScreen($rootWidget, $title, $context, $descr, 3, $depth, $visited);
        } catch (\Throwable $e) {
            $this->addWarning('Could not render a page completely; the remaining page details were skipped', $e);
            return "### Partially unavailable page\n\n_Could not render this page completely; rendering continued._\n\n";
        }
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
        try {
            $objects = $this->collectObjects($screen);
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            $objects = [];
        }
        if (! empty($objects)) {
            $md .= "Objects shown:\n";
            foreach ($objects as $objLine) {
                $md .= '- ' . $objLine . "\n";
            }
            $md .= "\n";
        }

        // Buttons available to the user on this screen
        try {
            $buttons = $this->collectButtons($screen);
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            $buttons = [];
        }
        $dialogs = [];
        if (! empty($buttons)) {
            $md .= "Buttons by input widget:\n\n";
            foreach ($this->groupButtonsByInputWidget($buttons) as $group) {
                $md .= '**' . $group['label'] . "**\n";
                foreach ($group['buttons'] as $button) {
                    try {
                        $action = $button->hasAction() ? $button->getAction() : null;
                        $caption = $button->getCaption();
                        if ($caption === null || $caption === '') {
                            $caption = $button->getWidgetType();
                        }
                        $line = '- **' . $this->oneLine($caption) . '**';
                        if ($action !== null) {
                            $line .= ' - action `' . $action->getAliasWithNamespace() . '`';
                            if ($action instanceof iShowDialog) {
                                $line .= ', opens a dialog';
                                // Resolving the dialog instantiates its widget tree, so do it only when it will be rendered.
                                if ($depth > 0) {
                                    $dialog = $action->getDialogWidget();
                                    if ($dialog !== null) {
                                        $dialogs[] = [$button, $dialog];
                                    }
                                }
                            }
                        }
                        $md .= $line . "\n";
                    } catch (\Throwable $e) {
                        $this->addWarning('Could not inspect a button or its action; the button was skipped', $e);
                    }
                }
                $md .= "\n";
            }
        }

        // Recurse into dialogs opened from the buttons of this screen
        if ($depth > 0) {
            foreach ($dialogs as [$button, $dialog]) {
                try {
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
                } catch (\Throwable $e) {
                    $this->addWarning('Could not render a dialog; the dialog was skipped', $e);
                }
            }
        }

        return $md;
    }

    /**
     * Collects all button widgets contained in the given screen widget tree.
     * 
     * Only widgets within the same id space are traversed, so buttons of dialogs opened from this
     * screen are not included here - they are documented separately when the dialog is described.
     * Buttons inside configurators are omitted because this generated UI is the same for every
     * configured widget and does not describe app-specific behavior. Automatically included global,
     * search, reset and contextual-help actions are omitted for the same reason.
     * 
     * @param WidgetInterface $screen
     * @return Button[]
     */
    protected function collectButtons(WidgetInterface $screen): array
    {
        $buttons = [];
        foreach ($this->getScreenWidgets($screen) as $child) {
            try {
                if ($child instanceof Button && ! $this->isInsideConfigurator($child) && ! $this->isAutoIncludedAction($child)) {
                    $buttons[] = $child;
                }
            } catch (\Throwable $e) {
                $this->addWarning('Could not inspect a widget while collecting buttons; the widget was skipped', $e);
            }
        }
        return $buttons;
    }

    /**
     * Iterates over a screen without recursive calls or descending into action-owned button children. Prevent infinite recursion by keeping track of already seen widgets.
     * Otherwise, when using the normal recursive approach in larger apps, even depth 0 can exceed the stack size and cause issues.
     *
     * @param WidgetInterface $screen
     * @return \Generator|WidgetInterface[]
     */
    protected function getScreenWidgets(WidgetInterface $screen): \Generator
    {
        $pending = [];
        try {
            foreach ($screen->getChildren() as $child) {
                $pending[] = $child;
            }
        } catch (\Throwable $e) {
            // A broken root subtree must not prevent the remaining overview from being rendered.
            $this->getWorkbench()->getLogger()->logException($e);
        }
        $seen = [];
        for ($position = 0; isset($pending[$position]); $position++) {
            $widget = $pending[$position];
            $key = spl_object_hash($widget);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            yield $widget;

            // Button children are action-owned widgets such as dialogs; those are handled by the depth-controlled path.
            if ($widget instanceof Button) {
                continue;
            }
            try {
                foreach ($widget->getChildren() as $child) {
                    $pending[] = $child;
                }
            } catch (\Throwable $e) {
                // Skip only this broken subtree and continue with widgets already queued from its siblings.
                $this->getWorkbench()->getLogger()->logException($e);
            }
        }
    }

    /**
     * Returns TRUE if the widget belongs to a generated configurator subtree.
     *
     * @param WidgetInterface $widget
     * @return bool
     */
    protected function isInsideConfigurator(WidgetInterface $widget): bool
    {
        while ($widget->hasParent()) {
            $widget = $widget->getParent();
            if ($widget instanceof WidgetConfigurator) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns TRUE if the button was automatically included by a standard widget.
     *
     * This excludes the following repetitive framework-generated controls:
     *
     * - global actions in a DataToolbar's dedicated global-actions button group,
     * - search and reset actions in a DataToolbar's dedicated search-actions button group,
     * - the contextual-help button generated by a widget implementing iHaveContextualHelp.
     *
     * The check compares the actual generated button and button-group instances. Manually configured
     * buttons are therefore retained even if they use the same action aliases.
     *
     * @param Button $button
     * @return bool
     */
    protected function isAutoIncludedAction(Button $button): bool
    {
        $widget = $button;
        while ($widget->hasParent()) {
            $widget = $widget->getParent();
            if ($widget instanceof iHaveContextualHelp && $widget->getHelpButton() === $button) {
                return true;
            }
            if ($widget instanceof ButtonGroup && $widget->hasParent()) {
                $toolbar = $widget->getParent();
                if ($toolbar instanceof DataToolbar
                    && ($toolbar->getButtonGroupForGlobalActions() === $widget
                        || $toolbar->getButtonGroupForSearchActions() === $widget)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Groups buttons by their effective input widget and sorts the groups by label.
     *
     * @param Button[] $buttons
     * @return array[]
     */
    protected function groupButtonsByInputWidget(array $buttons): array
    {
        $groups = [];
        foreach ($buttons as $button) {
            try {
                $inputWidget = $button->getInputWidget();
                $key = spl_object_hash($inputWidget);
                $label = $this->describeInputWidget($inputWidget);
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
                $key = 'unknown';
                $label = 'Unknown input widget';
            }
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $label,
                    'buttons' => []
                ];
            }
            $groups[$key]['buttons'][] = $button;
        }
        uasort($groups, function (array $left, array $right) {
            return strcasecmp($left['label'], $right['label']);
        });
        return array_values($groups);
    }

    /**
     * Builds a concise label identifying an action input widget.
     *
     * @param WidgetInterface $widget
     * @return string
     */
    protected function describeInputWidget(WidgetInterface $widget): string
    {
        $label = '`' . $widget->getWidgetType() . '`';
        $caption = $widget->getCaption();
        if ($caption !== null && $caption !== '') {
            $label .= ' "' . $this->oneLine($caption) . '"';
        }
        $id = $widget->getId();
        if ($id !== null && $id !== '') {
            $label .= ' (`' . $id . '`)';
        }
        try {
            $label .= ' - object `' . $widget->getMetaObject()->getAliasWithNamespace() . '`';
        } catch (\Throwable $e) {
            // Some structural widgets do not have a meta object.
        }
        return $label;
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
        foreach ($this->getScreenWidgets($screen) as $child) {
            $collect($child);
        }
        return $names;
    }

    /**
     * Records and logs a recoverable rendering problem.
     *
     * @param string $message
     * @param \Throwable $previous
     * @return void
     */
    protected function addWarning(string $message, \Throwable $previous): void
    {
        $warningKey = $message . "\0" . get_class($previous) . "\0" . $previous->getMessage();
        if (isset($this->warningKeys[$warningKey])) {
            return;
        }
        $this->warningKeys[$warningKey] = true;
        if ($this->activePrompt === null) {
            $this->getWorkbench()->getLogger()->logException($previous);
            return;
        }
        $warning = new AiToolRuntimeWarning($this, $this->activePrompt, $message, null, $previous);
        $this->getWorkbench()->getLogger()->logException($warning);
        $this->warnings[] = $warning;
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
                ->setDescription('How deep to follow dialogs opened by buttons inside the pages of the app of interest. Higher values can produce very extensive output and incur significant processing and AI costs.')
                ->setDefaultValue(1)
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