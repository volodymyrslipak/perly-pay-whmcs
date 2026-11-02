<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Resource;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/events`: everything that happened, the same objects webhooks deliver. */
final class Events extends AbstractResource
{
    use Lists, Retrieves;
}
