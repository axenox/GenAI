<?php

namespace axenox\GenAI\Interfaces;

use exface\Core\Interfaces\DataSources\DataConnectionInterface;

interface AiConnectorInterface extends DataConnectionInterface
{
    public function getModelName() : string;
    
    public function getTemperature(AiQueryInterface $query) : ?float;
}