<?php
namespace axenox\GenAI\DataConnectors;

use axenox\GenAI\Common\ApiAdapters\CompletionsApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\CompletionsApiResponseAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiRequestAdapter;
use axenox\GenAI\Common\ApiAdapters\ResponsesApiResponseAdapter;
use axenox\GenAI\Interfaces\AiConnectorInterface;
use axenox\GenAI\Interfaces\HttpRequestAdapterInterface;
use axenox\GenAI\Interfaces\HttpRequestToolTestInterface;
use axenox\GenAI\Interfaces\HttpResponseAdapterInterface;
use exface\Core\CommonLogic\AbstractDataConnector;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use exface\Core\DataConnectors\Traits\IDoNotSupportTransactionsTrait;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Interfaces\UserInterface;
use exface\Core\Interfaces\Selectors\UserSelectorInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\ExpressionLanguage\Lexer;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\ExpressionLanguage\Token;

/**
 * Fakes tool calls for testing purposes without calling an LLM.
 * 
 * A connection based on this connector can be used for any AI agent. If done so, the user can type tool calls to test
 * in Excel formula like syntax and the connector will perform the tool call instead of asking an LLM what to do. The
 * "function" called in the prompt must match a tool name available to the agent of course.
 * 
 * Arguments can be passed positionally - in the order the tool defines them - or as named arguments using a colon
 * (`argument: value`). Strings must be quoted with single or double quotes, numbers, `true`, `false` and `null` are
 * recognized automatically. Several tool calls can be tested at once by writing them one after another.
 * 
 * Example user prompts:
 * 
 * ```
 * read_file('axenox/genai/DataConnectors/OpenAiToolTester.php')
 * 
 * ```
 * 
 * ```
 * read_file(path: 'axenox/genai/DataConnectors/OpenAiToolTester.php', max_lines: 50)
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *        
 */
class OpenAiToolTester extends AbstractDataConnector implements AiConnectorInterface
{
    use IDoNotSupportTransactionsTrait;
    
    private ?string $apiType = null;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performQuery()
     */
    final protected function performQuery(DataQueryInterface $query)
    {
        if (! $query instanceof OpenAiApiDataQuery) {
            throw new DataQueryFailedError($query, 'Invalid query type for connection ' . $this->getAliasWithNamespace() . ': expecting instance of OpenAiApiDataQuery');
        }
        
        $requestAdapter = $this->getRequestAdapter();
        if (! $requestAdapter instanceof HttpRequestToolTestInterface) {
            throw new DataConnectionConfigurationError($this, 'The API type "' . $this->getApiType() . '" does not support tool testing in connection ' . $this->getAliasWithNamespace());
        }
        
        $requestJson = json_decode($requestAdapter->buildBody($query), true);
        
        // If the request already contains results of previous tool calls, the tools to test have
        // already been called. Return a final text answer to stop the agent from calling them again.
        if ($requestAdapter->hasToolCallResultsInRequest($requestJson)) {
            $results = $requestAdapter->getToolCallResultsFromRequest($requestJson);
            $text = empty($results) ? 'Tool test completed.' : implode("\n\n", $results);
            $response = $requestAdapter->buildTextResponse($requestJson, $text);
            $responseAdapter = $this->getResponseAdapter($response);
            $warnings = [];
            return $query
                ->withResponse($response, $responseAdapter, $this->getCosts($responseAdapter, $warnings))
                ->withWarnings($warnings);
        }
        
        // Find the user prompt in the request - it contains the tool call(s) to test
        $prompt = $requestAdapter->getUserPromptFromRequest($requestJson);
        if ($prompt === null || trim($prompt) === '') {
            throw new DataQueryFailedError($query, 'Cannot test tools: no user prompt found in the request.');
        }
        
        // Parse the user prompt into tool calls written in Excel formula like syntax
        $parsedCalls = $this->parseToolCalls($prompt, $query);
        if (empty($parsedCalls)) {
            throw new DataQueryFailedError($query, 'Cannot test tools: the prompt "' . $prompt . '" does not contain any tool call. Expected Excel formula like syntax - e.g. read_file(\'path/to/file\').');
        }
        
        // Match each parsed call with a tool available in the request and fake a tool call for it
        $toolCalls = [];
        foreach ($parsedCalls as $parsedCall) {
            $toolName = $parsedCall['name'];
            $argNames = $requestAdapter->getToolArgumentNames($requestJson, $toolName);
            if ($argNames === null) {
                $available = $requestAdapter->getToolNamesFromRequest($requestJson);
                throw new DataQueryFailedError($query, 'Tool "' . $toolName . '" is not available for this agent. Available tools: ' . (empty($available) ? 'none' : implode(', ', $available)) . '.');
            }
            $toolCalls[] = [
                'name' => $toolName,
                'call_id' => 'call_' . UUIDDataType::generateSqlOptimizedUuid(),
                'arguments' => $this->mapArguments($parsedCall['arguments'], $argNames),
            ];
        }
        
        // Put the faked tool call(s) into a response the response adapter can understand
        $response = $requestAdapter->buildToolCallResponse($requestJson, $toolCalls);
        $responseAdapter = $this->getResponseAdapter($response);
        
        // Return a copy of the DataQuery with the faked response in it
        $warnings = [];
        return $query
            ->withResponse($response, $responseAdapter, $this->getCosts($responseAdapter, $warnings))
            ->withWarnings($warnings);
    }
    
    /**
     * Parses a prompt with tool calls in Excel formula like syntax into an array of calls.
     * 
     * Each returned element has a `name` and an `arguments` array. Positional arguments are keyed by their
     * integer position, named arguments (written as `name: value`) are keyed by their name.
     * 
     * @param string $prompt
     * @param DataQueryInterface $query
     * @return array
     */
    protected function parseToolCalls(string $prompt, DataQueryInterface $query) : array
    {
        try {
            $stream = (new Lexer())->tokenize(trim($prompt));
        } catch (SyntaxError $e) {
            throw new DataQueryFailedError($query, 'Cannot parse tool test prompt: ' . $e->getMessage(), null, $e);
        }
        
        // Collect all tokens into an array for easier lookahead
        $tokens = [];
        while (true) {
            $tokens[] = $stream->current;
            if ($stream->isEOF()) {
                break;
            }
            $stream->next();
        }
        
        $calls = [];
        $count = count($tokens);
        $i = 0;
        while ($i < $count) {
            $token = $tokens[$i];
            // A tool call starts with a name immediately followed by an opening bracket
            if ($token->type !== Token::NAME_TYPE
                || ! isset($tokens[$i + 1])
                || ! $tokens[$i + 1]->test(Token::PUNCTUATION_TYPE, '(')
            ) {
                $i++;
                continue;
            }
            
            $name = $token->value;
            $i += 2; // skip the name and the opening bracket
            $arguments = [];
            $position = 0;
            while ($i < $count && ! $tokens[$i]->test(Token::PUNCTUATION_TYPE, ')')) {
                $cur = $tokens[$i];
                switch (true) {
                    // Named argument: NAME ':' value
                    case $cur->type === Token::NAME_TYPE
                        && isset($tokens[$i + 1])
                        && $tokens[$i + 1]->test(Token::PUNCTUATION_TYPE, ':'):
                        $argName = $cur->value;
                        $i += 2; // skip the name and the colon
                        if ($i < $count) {
                            $arguments[$argName] = $this->parseScalarToken($tokens[$i]);
                            $i++;
                        }
                        break;
                    // Argument separator
                    case $cur->test(Token::PUNCTUATION_TYPE, ','):
                        $i++;
                        break;
                    // Positional argument
                    default:
                        $arguments[$position] = $this->parseScalarToken($cur);
                        $position++;
                        $i++;
                        break;
                }
            }
            $i++; // skip the closing bracket
            $calls[] = ['name' => $name, 'arguments' => $arguments];
        }
        
        return $calls;
    }
    
    /**
     * Converts a single token into its PHP value.
     * 
     * @param Token $token
     * @return mixed
     */
    protected function parseScalarToken(Token $token)
    {
        switch ($token->type) {
            case Token::STRING_TYPE:
            case Token::NUMBER_TYPE:
                return $token->value;
            case Token::NAME_TYPE:
                switch (strtolower((string) $token->value)) {
                    case 'true':
                        return true;
                    case 'false':
                        return false;
                    case 'null':
                        return null;
                    default:
                        return $token->value;
                }
            default:
                return $token->value;
        }
    }
    
    /**
     * Maps parsed arguments onto the argument names defined by a tool.
     * 
     * Positional arguments (integer keys) are mapped to tool argument names by their order. Named arguments
     * (string keys) are kept as-is.
     * 
     * @param array $parsedArgs
     * @param string[] $argNames
     * @return array
     */
    protected function mapArguments(array $parsedArgs, array $argNames) : array
    {
        $result = [];
        $position = 0;
        foreach ($parsedArgs as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            } else {
                $name = $argNames[$position] ?? (string) $position;
                $result[$name] = $value;
                $position++;
            }
        }
        return $result;
    }
    
    protected function getApiType() : string
    {
        switch (true) {
            case $this->apiType !== null:
                return $this->apiType;
            default:
                return 'responses';
        }
    }

    /**
     * Set the API type to use. Supported values are "completions" and "responses". 
     * 
     * If not set, the connector will try to determine the API type from the URL 
     * (if it contains "completions" or "responses").
     * 
     * @uxon-property api_type
     * @uxon-type [completions,responses]
     * 
     * @param string $apiType
     * @return $this
     */
    protected function setApiType(string $apiType) : OpenAiToolTester
    {
        $this->apiType = $apiType;
        return $this;
    }
    
    protected function getRequestAdapter() : HttpRequestAdapterInterface
    {
        switch ($this->getApiType()) {
            case 'completions':
                return new CompletionsApiRequestAdapter($this);
            case 'responses':
                return new ResponsesApiRequestAdapter($this);
            default:
                throw new DataConnectionConfigurationError($this, 'Unsupported API type: ' . $this->getApiType());
        }
    }
    
    protected function getResponseAdapter(ResponseInterface $response) : HttpResponseAdapterInterface
    {
        switch ($this->getApiType()) {
            case 'completions':
                return new CompletionsApiResponseAdapter($response);
            case 'responses':
                return new ResponsesApiResponseAdapter($response);
            default:
                throw new DataConnectionConfigurationError($this, 'Unsupported API type: ' . $this->getApiType());
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = parent::exportUxonObject();
        // TODO
        return $uxon;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token, bool $updateUserCredentials = true, UserInterface $credentialsOwner = null, bool $credentialsArePrivate = null) : AuthenticationTokenInterface
    {
        return $token;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $container, bool $saveCredentials = true, UserSelectorInterface $credentialsOwner = null) : iContainOtherWidgets
    {
        return $container;
    }

    /**
     * 
     * {@inheritDoc}
     * @see AiConnectorInterface::getModelName()
     */
    public function getModelName() : string
    {
        return 'test';
    }

    protected function getCosts(HttpResponseAdapterInterface $adapter, array &$warnings = []) : ?float
    {
        return 0;
    }

    protected function performConnect()
    {
        return;
    }
    
    protected function performDisconnect() 
    {
        return;
    }

    public function getTemperature(OpenAiApiDataQuery $query): ?float
    {
        return 0;
    }
}