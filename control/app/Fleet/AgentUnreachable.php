<?php

namespace App\Fleet;

/**
 * The agent could not be reached, so the state of the host is UNKNOWN.
 *
 * Distinct from AgentRefused on purpose. "The request never arrived" and "the
 * request arrived and was rejected" call for opposite responses: the first may
 * be safe to retry, the second never is until the reason is addressed.
 */
class AgentUnreachable extends \RuntimeException {}
