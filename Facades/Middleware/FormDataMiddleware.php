<?php
namespace axenox\GenAI\Facades\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware to convert DeepChat multipart/form-data requests into the regular message format.
 * 
 * When files are attached, the DeepChat widget does not send a JSON body, but a `multipart/form-data`
 * request (see `createCustomFormDataBody()` in `deepChat.js`). In that case the messages are not sent
 * as a `messages` array, but as individual body fields `message1`, `message2`, ... each containing the
 * JSON encoded message (e.g. `{"role":"user","text":"..."}`). The uploaded files are sent under the
 * field name `files`.
 * 
 * This middleware reconstructs the `messages` array from those `messageN` fields, so the rest of the
 * processing (e.g. {@see \axenox\GenAI\Common\AiPrompt}) sees exactly the same parameters as for a
 * regular JSON request. The uploaded files remain available via `$request->getUploadedFiles()`.
 * 
 * It must run before the `TaskReader` middleware, so the reconstructed `messages` are available when
 * the AI prompt is instantiated.
 * 
 * @author Andrej Kabachnik
 *
 */
class FormDataMiddleware implements MiddlewareInterface
{
    /**
     * 
     * {@inheritDoc}
     * @see \Psr\Http\Server\MiddlewareInterface::process()
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $messages = [];
            $hasMessageFields = false;
            foreach ($parsedBody as $key => $value) {
                if (! is_string($key) || ! preg_match('/^message(\d+)$/', $key, $matches)) {
                    continue;
                }
                $hasMessageFields = true;
                $decoded = is_string($value) ? json_decode($value, true) : null;
                if (! is_array($decoded)) {
                    $decoded = [
                        'role' => 'user',
                        'text' => is_string($value) ? $value : ''
                    ];
                }
                $messages[(int) $matches[1]] = $decoded;
                unset($parsedBody[$key]);
            }

            if ($hasMessageFields) {
                ksort($messages);
                $parsedBody['messages'] = array_values($messages);
                $request = $request->withParsedBody($parsedBody);
            }
        }

        return $handler->handle($request);
    }
}
