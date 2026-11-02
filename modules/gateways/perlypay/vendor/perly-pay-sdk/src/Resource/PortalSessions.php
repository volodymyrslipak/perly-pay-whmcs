<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Resource;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns\Creates;

/** `/portal_sessions`: a link that lets a customer manage their subscriptions and receipts. */
final class PortalSessions extends AbstractResource
{
    use Creates;
}
