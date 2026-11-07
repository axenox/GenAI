<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Common;

use axenox\GenAI\Common\AiPayload;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Factories\AiToolResultFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use PHPUnit\Framework\TestCase;

class AiToolResultTypedValueTest extends TestCase
{
    public function testScalarValuesRetainTheirActualTypesBeforeAndAfterRestoration(): void
    {
        $workbench = new Workbench(['DEBUG.LIVE_CLASS_AUTOLOADER' => false]);
        foreach ([true, false, null, 0, 42, -7, 1.0, 2.5, '00123'] as $value) {
            $result = new AiToolResultString($workbench, $value);
            $restored = AiToolResultFactory::createFromUxon(
                $workbench,
                new UxonObject(AiPayload::decode(AiPayload::encode($result->exportUxonObject()->toArray())))
            );
            $this->assertSame($value, $result->getValue());
            $this->assertSame($value, $restored->getValue());
            $this->assertSame($value, $restored->exportUxonObject()->toArray()['value']);
        }
    }

    public function testBooleanCompatibilityConversionIsLimitedToExplicitTextRendering(): void
    {
        $workbench = new Workbench(['DEBUG.LIVE_CLASS_AUTOLOADER' => false]);
        foreach ([[true, 'true'], [false, 'false']] as [$value, $text]) {
            $result = new AiToolResultString($workbench, $value);
            $this->assertSame($value, $result->getValue());
            $this->assertSame($text, (string) $result);
            $this->assertSame($text, $result->getValueWithMetadata());
            $this->assertStringContainsString($text, $result->getValueAsMarkdown());
        }
    }
}
