<?php
namespace axenox\GenAI\Common\Mcp;

/**
 * Provides a dependency-free tool for verifying the MCP protocol boundary.
 */
final class DiagnosticTool
{
    private string $endpointSelector;

    /**
     * Creates the diagnostic tool for the endpoint selector supplied to the CLI process.
     *
     * @param string $endpointSelector Endpoint selector passed by the MCP client.
     */
    public function __construct(string $endpointSelector)
    {
        $this->endpointSelector = $endpointSelector;
    }

    /**
     * Returns stable process information without starting an ExFace workbench.
     *
     * @return array<string, string>
     */
    public function getStatus(bool $emitWarning = false) : array
    {
        if ($emitWarning) {
            trigger_error('MCP diagnostic warning: STDERR routing is active.', E_USER_WARNING);
        }

        return [
            'status' => 'ok',
            'endpoint' => $this->endpointSelector,
            'php_version' => PHP_VERSION,
            'transport' => 'stdio'
        ];
    }
}