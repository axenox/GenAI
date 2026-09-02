<?php
namespace axenox\GenAI\Exceptions;

use exface\Core\Exceptions\NotFoundError;

/**
 * Raised when a configured AI skill alias cannot be resolved.
 */
class AiSkillNotFoundError extends NotFoundError
{}