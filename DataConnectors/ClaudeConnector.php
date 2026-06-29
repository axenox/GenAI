<?php
namespace axenox\GenAI\DataConnectors;

use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ClaudeMessagesApiResponseAdapter;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;

/**
 * Connects to Anthropic Claude Messages API.
 *
 * @author Andrej Kabachnik
 */
class ClaudeConnector extends OpenAiConnector
{
    private string $modelName = 'claude-sonnet-4-5';

    private ?float $temperature = null;

    private int $maxTokens = 2048;

    private string $anthropicVersion = '2023-06-01';

    protected function getRequestAdapter() : HttpRequestAdapterInterface
    {
        return new ClaudeMessagesApiRequestAdapter($this);
    }

    protected function getResponseAdapter(\Psr\Http\Message\ResponseInterface $response) : HttpResponseAdapterInterface
    {
        return new ClaudeMessagesApiResponseAdapter($response);
    }

    protected function getHeadersDefaults() : array
    {
        return [
            'Content-Type' => 'application/json',
            'anthropic-version' => $this->anthropicVersion,
        ];
    }

    /**
     * Name of the Claude model to call.
     *
     * @uxon-property model
     * @uxon-type string
     * @uxon-default claude-sonnet-4-5
     *
     * @param string $name
     * @return \axenox\GenAI\DataConnectors\ClaudeConnector
     */
    protected function setModel(string $name) : ClaudeConnector
    {
        $this->modelName = $name;
        return $this;
    }

    public function getModelName() : string
    {
        return $this->modelName;
    }

    /**
     * What sampling temperature to use, between 0 and 1 for Claude.
     *
     * @uxon-property temperature
     * @uxon-type number
     *
     * @param float $val
     * @return \axenox\GenAI\DataConnectors\ClaudeConnector
     */
    protected function setTemperature(float $val) : ClaudeConnector
    {
        $this->temperature = $val;
        return $this;
    }

    public function getTemperature(OpenAiApiDataQuery $query) : ?float
    {
        return $query->getTemperature() ?? $this->temperature;
    }

    /**
     * Maximum number of output tokens Claude may generate.
     *
     * @uxon-property max_tokens
     * @uxon-type integer
     * @uxon-default 2048
     *
     * @param int $value
     * @return \axenox\GenAI\DataConnectors\ClaudeConnector
     */
    protected function setMaxTokens(int $value) : ClaudeConnector
    {
        if ($value < 1) {
            throw new DataConnectionConfigurationError($this, 'Invalid `max_tokens` value "' . $value . '". It must be >= 1.');
        }

        $this->maxTokens = $value;
        return $this;
    }

    public function getMaxTokens() : int
    {
        return $this->maxTokens;
    }

    /**
     * API version sent in `anthropic-version` request header.
     *
     * @uxon-property anthropic_version
     * @uxon-type string
     * @uxon-default 2023-06-01
     *
     * @param string $value
     * @return \axenox\GenAI\DataConnectors\ClaudeConnector
     */
    protected function setAnthropicVersion(string $value) : ClaudeConnector
    {
        $this->anthropicVersion = trim($value);
        return $this;
    }
}