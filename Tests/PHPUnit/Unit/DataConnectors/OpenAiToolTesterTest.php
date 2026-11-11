<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\DataConnectors;

use axenox\GenAI\AI\Tools\FileReadTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\Common\Messages\InputMessage;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\DataConnectors\OpenAiToolTester;
use axenox\GenAI\Interfaces\AiToolCallMessageInterface;
use exface\Core\CommonLogic\Selectors\DataConnectorSelector;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies repeated tool tests in one conversation without executing tools or calling an LLM.
 */
class OpenAiToolTesterTest extends TestCase
{
    /**
     * Supplies both API formats supported by the tool tester.
     *
     * @return iterable<string,array{string}>
     */
    public static function apiTypes() : iterable
    {
        yield 'responses' => ['responses'];
        yield 'completions' => ['completions'];
    }

    /**
     * A corrected prompt executes again and returns only its own results, not a previous failure.
     *
     * @param string $apiType
     * @return void
     */
    #[DataProvider('apiTypes')]
    public function testNewPromptStartsANewToolTest(string $apiType) : void
    {
        $workbench = new Workbench();
        $connector = new OpenAiToolTester(
            new DataConnectorSelector($workbench, OpenAiToolTester::class),
            new UxonObject(['api_type' => $apiType])
        );
        $performQuery = new \ReflectionMethod($connector, 'performQuery');
        $performQuery->setAccessible(true);
        $query = new AiQuery($workbench, null, [new InputMessage("FileReadTool('missing.php')")]);
        $query->addTool(new FileReadTool($workbench, new UxonObject(['name' => 'FileReadTool'])));

        $first = $performQuery->invoke($connector, $query)->getResponseMessage();
        self::assertInstanceOf(AiToolCallMessageInterface::class, $first);
        self::assertSame(['path' => 'missing.php'], $first->getCalls()[0]->getArguments());
        $first = $first->withToolResults([
            $first->getCalls()[0]->getCallId() => new AiToolResultString($workbench, 'Old missing-file error')
        ]);
        $query->appendMessage($first);
        $firstAnswer = $performQuery->invoke($connector, $query);
        self::assertFalse($firstAnswer->hasToolCalls());
        self::assertStringContainsString('Old missing-file error', $firstAnswer->getResponseMessage()->getText());
        $query->appendMessage($firstAnswer->getResponseMessage());
        $query->appendMessage(new InputMessage("FileReadTool('corrected.php') FileReadTool('another.php')"));

        $second = $performQuery->invoke($connector, $query)->getResponseMessage();
        self::assertInstanceOf(AiToolCallMessageInterface::class, $second);
        self::assertCount(2, $second->getCalls());
        self::assertSame(['path' => 'corrected.php'], $second->getCalls()[0]->getArguments());
        self::assertSame(['path' => 'another.php'], $second->getCalls()[1]->getArguments());
        $query->appendMessage($second->withToolResults([
            $second->getCalls()[0]->getCallId() => new AiToolResultString($workbench, 'Corrected file contents'),
            $second->getCalls()[1]->getCallId() => new AiToolResultString($workbench, 'Another file contents')
        ]));

        $secondAnswer = $performQuery->invoke($connector, $query);
        self::assertFalse($secondAnswer->hasToolCalls());
        $text = $secondAnswer->getResponseMessage()->getText();
        self::assertStringContainsString('Corrected file contents', $text);
        self::assertStringContainsString('Another file contents', $text);
        self::assertStringNotContainsString('Old missing-file error', $text);
    }

    /**
     * Current supplementary instructions survive even if the same appendix appeared in an earlier turn.
     *
     * @param string $apiType
     * @return void
     */
    #[DataProvider('apiTypes')]
    public function testRetainsRepeatedAppendicesWithoutChangingOriginalContext(string $apiType) : void
    {
        $workbench = new Workbench();
        $connector = new OpenAiToolTester(
            new DataConnectorSelector($workbench, OpenAiToolTester::class),
            new UxonObject(['api_type' => $apiType])
        );
        $performQuery = new \ReflectionMethod($connector, 'performQuery');
        $performQuery->setAccessible(true);
        $messages = [
            new InputMessage("FileReadTool('old.php')"),
            new ToolCallMessage('', [new AiToolCall('FileReadTool', 'old', ['path' => 'old.php'])], [
                'old' => new AiToolResultString($workbench, 'Old contents', null, ['Shared instructions'])
            ]),
            new InputMessage("FileReadTool('current.php')"),
            new ToolCallMessage('', [new AiToolCall('FileReadTool', 'current', ['path' => 'current.php'])], [
                'current' => new AiToolResultString($workbench, 'Current contents', null, ['Shared instructions'])
            ])
        ];
        $query = new AiQuery($workbench, null, $messages);
        $answer = $performQuery->invoke($connector, $query);

        self::assertFalse($answer->hasToolCalls());
        $text = $answer->getResponseMessage()->getText();
        self::assertStringContainsString('Current contents', $text);
        self::assertStringContainsString('Shared instructions', $text);
        self::assertStringNotContainsString('Old contents', $text);
        self::assertSame($messages, $query->getMessages());
        self::assertSame($messages, $answer->getMessages());
    }
}
