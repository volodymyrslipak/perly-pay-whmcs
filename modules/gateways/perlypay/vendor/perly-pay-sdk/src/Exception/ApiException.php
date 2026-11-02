<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Any other error the API answered (a 5xx after its retries, an unknown type). */
final class ApiException extends PerlyPayException
{
}
