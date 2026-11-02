<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** The secret key is missing, malformed, revoked, or for the other mode. */
final class AuthenticationException extends PerlyPayException
{
}
