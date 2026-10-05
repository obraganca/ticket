<?php

namespace App\Actions\Orders;

use Exception;

/** Retry-able: sinaliza 503 + Retry-After para o cliente tentar de novo com a mesma chave. */
class ConcurrentIdempotencyKeyInFlightException extends Exception {}
