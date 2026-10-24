<?php

namespace App\Exceptions;

/**
 * The AI returned a plan that Coolify does not accept. The errors are safe to show to the user and
 * to send back to the AI.
 */
class InvalidAiPlanException extends AiAssistantException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The AI returned a plan that Coolify cannot use: '.implode(' ', array_slice($errors, 0, 5)));
    }
}
