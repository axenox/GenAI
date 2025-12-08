<?php
namespace axenox\GenAI\Facades;

use axenox\GenAI\Common\AiPrompt;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\Facades\AbstractHttpFacade\Middleware\DataUrlParamReader;
use exface\Core\Facades\AbstractHttpFacade\Middleware\JsonBodyParser;
use exface\Core\Facades\AbstractHttpFacade\Middleware\TaskReader;
use axenox\GenAI\Factories\AiFactory;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;

/**
 * Allows to chat with AI agents defined in the meta model using an OpenAI style API
 * 
 * ## Examples
 * 
 * `POST api/aichat/exface.Core.SqlFilteringAgent/completions?object=exface.Core.USER`
 * 
 * Body:
 * 
 * ```
 * {
 *  "prompt": [
 *   "Show all users added in the past two moths"
 *  ],
 *  "temperature": 0,
 *  "n": 1
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class AiChatFacade extends AbstractHttpFacade
{
    const REQUEST_ATTR_TASK = 'task';

    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $headers = $this->buildHeadersCommon();
        
        // api/aichat/exface.Core.SqlFilterAgent/completions -> exface.Core.SqlFilterAgent/completions
        $pathInFacade = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/');
        // exface.Core.SqlFilterAgent/completions -> exface.Core.SqlFilterAgent, completions
        list($agentSelector, $pathInFacade) = explode('/', $pathInFacade, 2);
        $pathInFacade = mb_strtolower($pathInFacade);
        try{                
        // Do the routing here
            switch (true) {     
                case $pathInFacade === 'completions':
                    $prompt = $request->getAttribute(self::REQUEST_ATTR_TASK);
                    $agent = $this->findAgent($agentSelector);
                    $response = $agent->handle($prompt);

                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode($response->toArray(), JSON_UNESCAPED_UNICODE);
                    break;
                // Deepchat format - see https://deepchat.dev/docs/connect#Response
                case $pathInFacade === 'deepchat':
                    
                    $prompt = $request->getAttribute(self::REQUEST_ATTR_TASK);
                    $agent = $this->findAgent($agentSelector);
                    $response = $agent->handle($prompt);

                    $responseCode = 200;
                    $headers['content-type'] = 'application/json';
                    $body = json_encode([
                            'text' => $response->getMessage(),
                            'conversation'=> $response->getConversationId()
                        ]
                        , JSON_UNESCAPED_UNICODE
                    );
                    break;
                default:
                    throw new FacadeRoutingError('Route "' . $pathInFacade . '" not found!');
            }
            return new Response(($responseCode ?? 404), $headers, Utils::streamFor($body ?? ''));
        }
        catch(\Throwable $e){
            $this->getWorkbench()->getLogger()->logException($e);
            return $this->createResponseFromError($e, $request);
        } 
    }

    /**
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade::createResponseFromError()
     */
    protected function createResponseFromError(\Throwable $exception, ServerRequestInterface $request = null) : ResponseInterface
    {
        $response = parent::createResponseFromError($exception, $request);
        if ($response->getStatusCode() !== 401 && $request !== null && stripos($request->getUri()->getPath(), '/deepchat') !== false) {
            // @see https://deepchat.dev/docs/connect#Response
            $json = [
                'error' => $exception->getMessage()
            ];
            $body = json_encode($json, JSON_UNESCAPED_UNICODE);
            return $response->withBody(Utils::streamFor($body))->withHeader('content-type','application/json');
        }
        return parent::createResponseFromError($exception, $request);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/aichat';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
     */
    protected function getMiddleware() : array
    {
        $middleware = parent::getMiddleware();

        // Parse JSON body if it is a JSON and make it available via `$request->getParsedBody()`
        $middleware[] = new JsonBodyParser();
        
        // Generate a task and save it in the request attributes
        $middleware[] = new TaskReader($this, self::REQUEST_ATTR_TASK, function(AiChatFacade $facade, ServerRequestInterface $request){
            return new AiPrompt($facade->getWorkbench(), $facade, $request); 
        }, 
        // URL parameters, that we need in the task
        [
            'object' => 'object_alias',
            'page' => 'page_alias',
            'widget' => 'widget_id'
        ]);
        $middleware[] = new DataUrlParamReader($this, 'data', 'setInputData');
        
        // Add HTTP basic auth for simpler API testing. This allows to log in with
        // username and password from API clients like PostMan.
        // TODO remove authentication after initial testing phase
        $middleware[] = new AuthenticationMiddleware($this, [
            [AuthenticationMiddleware::class, 'extractBasicHttpAuthToken'],
            [AuthenticationMiddleware::class, 'extractBearerTokenAsApiKey']
        ]);
        
        return $middleware;
    }

    protected function findAgent(string $selector)
    {
        // TODO find agent by selector once an agent list is implemented
        $agent = AiFactory::createAgentFromString($this->getWorkbench(), $selector);
        return $agent;
    }
}