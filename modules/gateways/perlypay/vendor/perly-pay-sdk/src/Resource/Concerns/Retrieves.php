<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** @mixin \PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\AbstractResource */
trait Retrieves
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->requestor->request('GET', $this->idPath($id), null, [], $options);
    }
}
