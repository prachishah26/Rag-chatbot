<?php

namespace App\Services\Rag;

use RuntimeException;

/** The provider still answered HTTP 429 after the wait budget ran out. */
class RateLimitException extends RuntimeException {}
