<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Exception;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** A webhook whose Perly-Signature does not check out. */
final class SignatureVerificationException extends PerlyPayException
{
}
