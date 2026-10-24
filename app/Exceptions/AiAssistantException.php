<?php

namespace App\Exceptions;

/**
 * The AI deployment assistant could not finish a request. The message names no API keys or secret
 * values, so it can be shown to the user and stored on the attempt.
 */
class AiAssistantException extends NonReportableException {}
