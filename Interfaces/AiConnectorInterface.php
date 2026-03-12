<?php

namespace axenox\GenAI\Interfaces;

use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;

interface AiConnectorInterface extends DataConnectionInterface
{
    public function getModelName() : string;
    
    public function getTemperature(OpenAiApiDataQuery $query) : ?float;
}