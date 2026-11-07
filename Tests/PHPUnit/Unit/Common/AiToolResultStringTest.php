<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Common;

use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Factories\AiToolResultFactory;
use exface\Core\CommonLogic\Model\Message;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\CodeDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\InternalError;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Model\MessageInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

class AiToolResultStringTest extends TestCase
{
    private Workbench $workbench;

    protected function setUp(): void
    {
        $this->workbench = new Workbench(['DEBUG.LIVE_CLASS_AUTOLOADER' => false]);
    }

    public function testRoundTripRetainsValuesMetadataAndRendering(): void
    {
        $type = DataTypeFactory::createFromPrototype($this->workbench, MarkdownDataType::class);
        $warning = (new ResultDiagnostic('first warning', ' Retry with a narrower scope. '))->setLogLevel(LoggerInterface::WARNING);
        $error = (new ResultDiagnostic('first error', ' Fix the input. '))->setLogLevel(LoggerInterface::ERROR);
        $result = new AiToolResultString($this->workbench, '00123', $type, ['Keep this appendix.'], [$warning, $error]);
        $result->addException((new ResultDiagnostic('second warning'))->setLogLevel(LoggerInterface::WARNING));
        $result->addException((new ResultDiagnostic('second error'))->setLogLevel(LoggerInterface::CRITICAL));
        $result->setDurationMs(12.5);
        $freshText = $result->getValueWithMetadata();
        $warning->hint = 'Changed after capture.';
        $error->hint = 'Changed after capture.';

        $restored = $this->restore($result);

        $this->assertSame('00123', $restored->getValue());
        $this->assertSame(['Keep this appendix.'], $restored->getAppendix());
        $this->assertSame($result->getErrors(), $restored->getErrors());
        $this->assertSame($result->getWarnings(), $restored->getWarnings());
        $this->assertCount(2, $restored->getErrors());
        $this->assertCount(2, $restored->getWarnings());
        $this->assertTrue($restored->isFailed());
        $this->assertSame(12.5, $restored->getDurationMs());
        $this->assertSame([], $restored->getExceptions());
        $this->assertCount(4, $result->getExceptions());
        $this->assertSame($freshText, $restored->getValueWithMetadata());
        $this->assertSame($freshText, $restored->getValueAsMarkdown(true));
        $this->assertStringContainsString("Remediation: Retry with a narrower scope.", $freshText);
        $this->assertSame(1, $warning->resolutions);
        $this->assertSame(1, $error->resolutions);
        $this->assertInstanceOf(MarkdownDataType::class, $restored->getValueDataType());
        $this->assertSame($this->workbench, $restored->getWorkbench());
        $this->assertArrayNotHasKey('exceptions', $result->exportUxonObject()->toArray());
        $this->assertArrayNotHasKey('arguments', $result->exportUxonObject()->toArray());
        $this->assertArrayNotHasKey('tool', $result->exportUxonObject()->toArray());
    }

    public function testWarningsDoNotFailAndRejectedRequestsCanBeMarkedFailed(): void
    {
        $warning = (new ResultDiagnostic('invalid request'))->setLogLevel(LoggerInterface::WARNING);
        $result = new AiToolResultString($this->workbench, '', null, [], [$warning]);
        $this->assertFalse($result->isFailed());
        $this->assertSame([], $result->getErrors());
        $this->assertSame($result, $result->setFailed(true));
        $restored = $this->restore($result);
        $this->assertTrue($restored->isFailed());
        $this->assertSame([], $restored->getErrors());
        $this->assertCount(1, $restored->getWarnings());
        $this->assertStringContainsString('Do not repeat the same call unchanged.', $restored->getValueWithMetadata());
    }

    public function testErrorLevelAloneMarksFailed(): void
    {
        $result = new AiToolResultString($this->workbench);
        $result->addException((new ResultDiagnostic('cannot run'))->setLogLevel(LoggerInterface::ERROR));
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('No result was produced because errors occurred.', $result->getValueWithMetadata());
    }

    public function testBooleanCompatibilityAndOriginalValueAreRetained(): void
    {
        foreach ([[true, 'true'], [false, 'false']] as [$value, $expected]) {
            $result = new AiToolResultString($this->workbench, $value);
            $this->assertSame($expected, (string) $result);
            $this->assertSame($value, $result->exportUxonObject()->toArray()['value']);
            $this->assertSame($expected, (string) $this->restore($result));
        }
    }

    public function testTypedValuesRemainAvailableWithoutImplicitParsing(): void
    {
        foreach (['{"id":"001"}', 42, 1.0, null, [], (object) ['id' => '001', 'empty' => new \stdClass()]] as $value) {
            $result = new AiToolResultString($this->workbench, $value);
            $restored = $this->restore($result);
            $this->assertEquals($value, $restored->getValue());
            if (! is_object($value)) {
                $this->assertSame($value, $restored->getValue());
            }
            $this->assertSame((string) $result, (string) $restored);
        }
    }

    public function testDataTypeConfigurationAndCodeLanguageSurviveRestoration(): void
    {
        $type = DataTypeFactory::createFromPrototype($this->workbench, CodeDataType::class);
        $type->setLanguage('sql');
        $type->setLengthMax(100);
        $result = new AiToolResultString($this->workbench, 'SELECT 1', $type);
        $restored = $this->restore($result);

        $this->assertInstanceOf(CodeDataType::class, $restored->getValueDataType());
        $this->assertSame('sql', $restored->getValueDataType()->getLanguage());
        $this->assertSame(100, $restored->getValueDataType()->getLengthMax());
        $this->assertSame($result->getValueAsMarkdown(), $restored->getValueAsMarkdown());
        $this->assertStringContainsString('```sql', $restored->getValueAsMarkdown());
        $this->assertNull(AiToolResultString::getUxonSchemaClass());
    }

    public function testImportReplacesTransientExceptionsAndPriorMetadata(): void
    {
        $result = new AiToolResultString($this->workbench, 'old', null, ['old appendix'], [new ResultDiagnostic('old error')]);
        $result->importUxonObject(new UxonObject(['value' => 'new', 'failed' => false, 'errors' => [], 'warnings' => []]));
        $this->assertSame('new', $result->getValue());
        $this->assertSame([], $result->getExceptions());
        $this->assertSame([], $result->getErrors());
        $this->assertSame([], $result->getAppendix());
        $this->assertFalse($result->isFailed());
    }

    public function testFactoryRejectsArbitraryStoredClass(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        AiToolResultFactory::createFromUxon($this->workbench, new UxonObject(['class' => \stdClass::class, 'value' => 'bad']));
    }

    public function testFactoryRejectsMissingStoredClass(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        AiToolResultFactory::createFromUxon($this->workbench, new UxonObject(['value' => 'bad']));
    }

    public function testImportRejectsInvalidDiagnostics(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new AiToolResultString($this->workbench))->importUxonObject(new UxonObject(['value' => '', 'errors' => [42]]));
    }

    private function restore(AiToolResultString $result): AiToolResultString
    {
        $data = AiPayload::decode(AiPayload::encode($result->exportUxonObject()->toArray()));
        return AiToolResultFactory::createFromUxon($this->workbench, new UxonObject($data));
    }
}

class ResultDiagnostic extends InternalError
{
    public int $resolutions = 0;

    public function __construct(string $message, public string $hint = '')
    {
        parent::__construct($message);
    }

    public function getMessageModel(WorkbenchInterface $workbench): MessageInterface
    {
        $this->resolutions++;
        return (new Message($workbench, 'test-diagnostic'))->setHint($this->hint);
    }
}
