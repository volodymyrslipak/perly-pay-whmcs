<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Resource;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Retrieves;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Updates;

/** `/payment_links`: a lasting `/l/…` address for one price. */
final class PaymentLinks extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
