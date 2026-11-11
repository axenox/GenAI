<?php
namespace axenox\GenAI\Tests\Unit\Mcp;

use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\Mcp\AiToolMcpAdapter;
use axenox\GenAI\Exceptions\AiToolCriticalError;
use axenox\GenAI\Interfaces\AiToolInterface;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Exceptions\Security\AccessPermissionDeniedError;
use exface\Core\Exceptions\Security\AuthorizationRuntimeError;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Security\AuthorizationPointInterface;
use exface\Core\Interfaces\Security\PermissionInterface;
use exface\Core\Interfaces\UserImpersonationInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies authorization failures are sanitized without requiring a live Workbench.
 * 
 * @author GitHub Copilot
 */
class McpAuthorizationTest extends TestCase
{
    /**
     * Verifies rejected result needs neither tool nor remediation lookup.
     * 
     * @return void
     */
    public function testRejectedResultNeedsNeitherToolNorRemediationLookup() : void
    {
        $result = AiToolMcpAdapter::createRejectedResult(new Workbench(), 'Check the input schema.');
        $this->assertTrue($result->isFailed());
        $this->assertSame([], $result->getErrors());
        $this->assertSame(['Check the input schema.'], $result->getWarnings());
        $this->assertSame([], $result->getExceptions());
        $this->assertTrue(AiToolMcpAdapter::convertResult($result)->isError);
    }

    /**
     * Verifies wrapped denial is failed warning and does not retain protected content.
     * 
     * @return void
     */
    public function testWrappedDenialIsFailedWarningAndDoesNotRetainProtectedContent() : void
    {
        $workbench = new Workbench();
        $denial = $this->denial(false);
        $wrapped = new AiToolCriticalError(
            $this->createMock(AiToolInterface::class), new GenericTask($workbench),
            'Protected authorization details', null, $denial
        );
        $original = new AiToolResultString($workbench, 'Protected partial content', null, ['Protected appendix'], [$wrapped]);
        $original->setDurationMs(12.5);
        [$normalized, $securityErrors] = AiToolMcpAdapter::normalizeAuthorizationResult($original);
        $this->assertSame([$denial], $securityErrors);
        $this->assertTrue($normalized->isFailed());
        $this->assertSame([], $normalized->getErrors());
        $this->assertCount(1, $normalized->getWarnings());
        $this->assertSame('', $normalized->getValue());
        $this->assertSame([], $normalized->getAppendix());
        $this->assertSame(12.5, $normalized->getDurationMs());
        $this->assertStringNotContainsString('Protected', $normalized->getValueWithMetadata());
    }

    /**
     * Verifies warning severity security denial still fails without becoming error.
     * 
     * @return void
     */
    public function testWarningSeveritySecurityDenialStillFailsWithoutBecomingError() : void
    {
        $denial = $this->denial(false);
        $denial->setLogLevel(LoggerInterface::WARNING);
        $original = new AiToolResultString(new Workbench(), '', null, [], [$denial]);
        [$normalized, $securityErrors] = AiToolMcpAdapter::normalizeAuthorizationResult($original);
        $this->assertSame([$denial], $securityErrors);
        $this->assertTrue($normalized->isFailed());
        $this->assertSame([], $normalized->getErrors());
        $this->assertCount(1, $normalized->getWarnings());
    }

    /**
     * Verifies authorization machinery failures are not downgraded to warnings.
     * 
     * @return void
     */
    public function testAuthorizationMachineryFailuresAreNotDowngradedToWarnings() : void
    {
        $workbench = new Workbench();
        foreach ([$this->denial(true), new AuthorizationRuntimeError('Authorization defect')] as $error) {
            $original = new AiToolResultString($workbench, '', null, [], [$error]);
            [$normalized, $securityErrors] = AiToolMcpAdapter::normalizeAuthorizationResult($original);
            $this->assertSame($original, $normalized);
            $this->assertSame([], $securityErrors);
            $this->assertNotEmpty($normalized->getErrors());
        }
    }

    /**
     * Verifies mixed diagnostics do not hide unrelated errors.
     * 
     * @return void
     */
    public function testMixedDiagnosticsDoNotHideUnrelatedErrors() : void
    {
        $other = new RuntimeException('An unrelated execution defect');
        $other->setLogLevel(LoggerInterface::ERROR);
        $original = new AiToolResultString(new Workbench(), '', null, [], [$this->denial(false), $other]);
        [$normalized, $securityErrors] = AiToolMcpAdapter::normalizeAuthorizationResult($original);
        $this->assertCount(1, $securityErrors);
        $this->assertSame(['An unrelated execution defect'], $normalized->getErrors());
        $this->assertCount(1, $normalized->getWarnings());
        $this->assertTrue($normalized->isFailed());
    }

    /**
     * Creates a narrow authorization-denial fixture without mocking the Workbench.
     * 
     * @param bool $indeterminate
     * @return AccessPermissionDeniedError
     */
    private function denial(bool $indeterminate) : AccessPermissionDeniedError
    {
        $permission = $this->createMock(PermissionInterface::class);
        $permission->method('isIndeterminate')->willReturn($indeterminate);
        $denial = new AccessPermissionDeniedError(
            $this->createMock(AuthorizationPointInterface::class),
            $permission,
            $this->createMock(UserImpersonationInterface::class),
            null,
            'Protected authorization details'
        );
        $denial->setLogLevel(LoggerInterface::ERROR);
        return $denial;
    }
}
