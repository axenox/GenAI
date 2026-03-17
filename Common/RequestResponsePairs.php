<?php

namespace axenox\GenAI\Common;

use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchInterface;

class RequestResponsePairs implements iCanBeConvertedToUxon
{
     use ICanBeConvertedToUxonTrait;

    private WorkbenchInterface $workbench;
    
    private string $request;
    
    private string $response;

    public function __construct(WorkbenchInterface $workbench, UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    /**
     *What can AI do for these tool requests?
     *
     *@uxon-proeprty requets
     *@uxon-type string 
     *@uxon-tempalte ""
     * 
     * @param string $request
     * @return $this
     */
    protected function setRequest(string $request): RequestResponsePairs
    {
        $this->request = $request;
        return $this;
    }

    /**
     * What should the tool respond with to this request?
     * 
     * @uxon-proeprty response
     * @uxon-type string
     * @uxon-tempalte ""
     * 
     * @param string $response
     * @return $this
     */
    protected function setResponse(string $response): RequestResponsePairs
    {
        $this->response = $response;
        return $this;
    }
    
    public function isMatch(string $request): bool
    {
        return $this->request === $request;
    }
    
    public function getResponse(): string
    {
        return $this->response;
    }
    
    
}