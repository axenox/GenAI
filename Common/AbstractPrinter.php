<?php

namespace axenox\GenAI\Common;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

class AbstractPrinter implements iCanBeConvertedToUxon
{
    use ICanBeConvertedToUxonTrait;

    protected WorkbenchInterface $workbench;

    public function __construct(WorkbenchInterface $workbench, ?UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }
}