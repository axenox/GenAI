<?php

namespace axenox\GenAI\Tests\PHPUnit\Unit\Widgets;

use axenox\GenAI\Widgets\AIChat;
use exface\Core\CommonLogic\Model\Model;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Widgets\InputCustom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AIChatToolbarTest extends TestCase
{
    public function testIntroRendersMultilineMarkdownAsOneNote() : void
    {
        $chat = new AIChat(UiPageFactory::createEmpty($this->createWorkbench()));
        $getIntro = new \ReflectionMethod(AIChat::class, 'getIntroMessage');
        self::assertSame(['text' => ''], json_decode($getIntro->invoke($chat), true, 512, JSON_THROW_ON_ERROR));

        $chat->importUxonObject(new UxonObject(['intro_message' => "---\r\n\r\nYou're ready.\r\n\r\n- Try **this** & see."]));
        self::assertSame(
            ['text' => "> [!NOTE]\n>\n> ---\n> \n> You're ready.\n> \n> - Try **this** & see."],
            json_decode($getIntro->invoke($chat), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testConfiguredCaptionIsVisibleUnlessExplicitlyHidden() : void
    {
        $page = UiPageFactory::createEmpty($this->createWorkbench());
        $visible = new AIChat($page);
        $visible->importUxonObject(new UxonObject(['caption' => 'Agent Designer']));
        self::assertSame('Agent Designer', $visible->getCaption());
        self::assertFalse($visible->getHideCaption() === true);

        $hidden = new AIChat($page);
        $hidden->importUxonObject(new UxonObject(['caption' => 'Agent Designer', 'hide_caption' => true]));
        self::assertTrue($hidden->getHideCaption());
    }

    #[DataProvider('toolbarConfigurations')]
    public function testChatButtonsFollowUxonConfiguration(array $uxon, array $aliases) : void
    {
        $chat = new AIChat(UiPageFactory::createEmpty($this->createWorkbench()));
        $chat->importUxonObject(new UxonObject($uxon));

        self::assertSame($aliases !== [], $chat->hasToolbars());
        $buttons = $chat->hasToolbars() ? $chat->getToolbarMain()->getButtons() : [];
        self::assertSame($aliases, array_map(static fn ($button) => $button->getActionAlias(), $buttons));
        foreach ($buttons as $button) {
            self::assertSame($chat, $button->getInputWidget());
        }
    }

    public static function toolbarConfigurations() : iterable
    {
        yield 'default' => [[], ['exface.Core.CustomFacadeScript', 'axenox.GenAI.AiConversationRateDialog']];
        yield 'only rate' => [['allow_to_start_new_conversation' => false], ['axenox.GenAI.AiConversationRateDialog']];
        yield 'only new conversation' => [['allow_to_rate_conversation' => false], ['exface.Core.CustomFacadeScript']];
        yield 'neither' => [['allow_to_start_new_conversation' => false, 'allow_to_rate_conversation' => false], []];
        yield 'custom buttons before flags' => [[
            'buttons' => [['caption' => 'Help', 'action_alias' => 'exface.Core.ShowUrl']],
            'allow_to_start_new_conversation' => false,
            'allow_to_rate_conversation' => false
        ], ['exface.Core.ShowUrl']];
    }

    public function testInputCustomExposesConfiguredToolbarAsChild() : void
    {
        $input = new InputCustom(UiPageFactory::createEmpty($this->createWorkbench()));
        self::assertFalse($input->hasToolbars());
        $input->importUxonObject(new UxonObject([
            'toolbars' => [['buttons' => [['caption' => 'Help']]]]
        ]));
        $toolbar = $input->getToolbarMain();
        self::assertSame('Toolbar', $toolbar->getWidgetType());
        self::assertContains($toolbar, iterator_to_array($input->getChildren()));
        self::assertSame($input, $toolbar->getButton(0)->getInputWidget());
    }

    private function createWorkbench() : Workbench
    {
        $workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'chat-toolbar-test',
            'WIDGET.BUTTON.HIDDEN_IF_ACCESS_DENIED' => true
        ]);
        (new ReflectionProperty(Workbench::class, 'mm'))->setValue($workbench, new Model($workbench));
        return $workbench;
    }
}
