<?php

namespace App\Fleet;

/** The agent answered, and said no. $detail carries its reason and hint verbatim. */
class AgentRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly array $detail = [])
    {
        parent::__construct($message);
    }
}
