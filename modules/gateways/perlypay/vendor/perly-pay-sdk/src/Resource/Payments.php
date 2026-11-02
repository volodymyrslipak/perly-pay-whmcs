<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Resource;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/payments`: transfers that arrived on-chain for your invoices. */
final class Payments extends AbstractResource
{
    use Lists, Retrieves;
}
