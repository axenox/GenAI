<?php
namespace axenox\GenAI\Common\Tasks;

use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Carries MCP invocation context through the generic task contract used by AI tools.
 */
class AiMcpTask extends GenericTask
{
    private string $endpointSelector;

    /**
     * Creates an MCP task for one capability operation.
     *
     * @param WorkbenchInterface $workbench Workbench scope owned by the operation.
     * @param string $endpointSelector Selector of the MCP endpoint or Phase 2 test agent.
     */
    public function __construct(WorkbenchInterface $workbench, string $endpointSelector)
    {
        parent::__construct($workbench);
        $this->endpointSelector = $endpointSelector;
    }

    /**
     * Returns the selector that owns the advertised capability.
     */
    public function getEndpointSelector() : string
    {
        return $this->endpointSelector;
    }
}