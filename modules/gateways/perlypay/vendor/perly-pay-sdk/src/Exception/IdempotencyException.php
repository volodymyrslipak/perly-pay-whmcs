<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** The same Idempotency-Key was sent with a different request. */
final class IdempotencyException extends PerlyPayException
{
}
