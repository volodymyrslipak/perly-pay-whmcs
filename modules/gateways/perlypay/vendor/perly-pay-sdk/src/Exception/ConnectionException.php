<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** The request never got an answer: network down, DNS, timeout. */
final class ConnectionException extends PerlyPayException
{
}
