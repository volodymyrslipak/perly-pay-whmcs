<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** A field is wrong, missing or not allowed; getParam() names it. */
final class InvalidRequestException extends PerlyPayException
{
}
