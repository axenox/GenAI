<?php
namespace axenox\GenAI\Tests\PHPUnit\Integration\Conversation;

use axenox\GenAI\Common\DataQueries\AiQuery;
use axenox\GenAI\Exceptions\AiQueryError;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Widgets\DebugMessage;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that query errors render transport diagnostics without interpreting the response.
 */
class AiQueryErrorTest extends TestCase
{
    /**
     * @return void
     */
    public function testRendersRequestAndResponseWithoutParsingTheAnswer() : void
    {
        $workbench = new Workbench();
        $adapter = $this->createMock(HttpResponseAdapterInterface::class);
        $adapter->expects(self::never())->method('getResponseMessage');
        $query = (new AiQuery($workbench))
            ->withRequest(new Request('POST', 'https://example.test/llm', ['Content-Type' => 'text/plain'], 'request fixture'))
            ->withResponse(new Response(500, ['Content-Type' => 'text/plain'], 'response fixture'), $adapter);
        $debug = $this->createDebugMessage($workbench);

        self::assertSame($debug, (new AiQueryError($query, 'Provider failed'))->createDebugWidget($debug));
        self::assertCount(2, $debug->getTabs());
        self::assertStringContainsString('request fixture', $debug->getTabs()[0]->getWidgets()[0]->getValue());
        self::assertStringContainsString('response fixture', $debug->getTabs()[1]->getWidgets()[0]->getValue());
    }

    /**
     * @return void
     */
    public function testRendersResponseWithoutRequest() : void
    {
        $workbench = new Workbench();
        $query = (new AiQuery($workbench))->withResponse(
            new Response(500, ['Content-Type' => 'text/plain'], 'response fixture'),
            $this->createStub(HttpResponseAdapterInterface::class)
        );
        $debug = $this->createDebugMessage($workbench);

        (new AiQueryError($query, 'Provider failed'))->createDebugWidget($debug);

        self::assertCount(1, $debug->getTabs());
        self::assertSame('Data response', $debug->getTabs()[0]->getCaption());
        self::assertStringContainsString('response fixture', $debug->getTabs()[0]->getWidgets()[0]->getValue());
    }

    /**
     * @return void
     */
    public function testExplicitRequestIsAttachedToACopyOfTheQuery() : void
    {
        $workbench = new Workbench();
        $query = (new AiQuery($workbench))->withResponse(
            new Response(500, ['Content-Type' => 'text/plain'], 'response fixture'),
            $this->createStub(HttpResponseAdapterInterface::class)
        );
        $request = new Request('POST', 'https://example.test/llm', ['Content-Type' => 'text/plain'], 'explicit request fixture');
        $error = new AiQueryError($query, 'Provider failed', null, null, $request);
        $debug = $this->createDebugMessage($workbench);

        $error->createDebugWidget($debug);

        self::assertNull($query->getRequest());
        self::assertSame($request, $error->getHttpRequest());
        self::assertCount(2, $debug->getTabs());
        self::assertStringContainsString('explicit request fixture', $debug->getTabs()[0]->getWidgets()[0]->getValue());
        self::assertStringContainsString('response fixture', $debug->getTabs()[1]->getWidgets()[0]->getValue());
    }

    /**
     * @return void
     */
    public function testRendersRequestBeforeResponseArrives() : void
    {
        $workbench = new Workbench();
        $query = (new AiQuery($workbench))->withRequest(new Request('POST', 'https://example.test/llm'));
        $debug = $this->createDebugMessage($workbench);

        (new AiQueryError($query, 'Transport failed'))->createDebugWidget($debug);

        self::assertCount(1, $debug->getTabs());
        self::assertSame('Data request', $debug->getTabs()[0]->getCaption());
    }

    /**
     * @return void
     */
    public function testLeavesDebugWidgetUnchangedWithoutTransportMessages() : void
    {
        $workbench = new Workbench();
        $query = new AiQuery($workbench);
        $error = new AiQueryError($query, 'Request preparation failed');
        $debug = $this->createDebugMessage($workbench);

        self::assertSame($query, $error->getQuery());
        self::assertSame($debug, $error->createDebugWidget($debug));
        self::assertCount(0, $debug->getTabs());
    }

    /**
     * Creates a debug widget bound to the installed Core dummy object.
     *
     * @param Workbench $workbench
     * @return DebugMessage
     */
    private function createDebugMessage(Workbench $workbench) : DebugMessage
    {
        $workbench->getConfig()->setOption('DEBUG.PRETTIFY_ERRORS', false);
        $errorReporting = error_reporting();
        try {
            $workbench->start();
        } finally {
            error_reporting($errorReporting);
        }
        $debug = new DebugMessage(UiPageFactory::createBlank($workbench, ''));
        $debug->setMetaObject(MetaObjectFactory::createFromString($workbench, 'exface.Core.DUMMY'));
        return $debug;
    }
}
