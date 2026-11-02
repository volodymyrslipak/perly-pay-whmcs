<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\Concerns;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** @mixin \PerlyPay\Whmcs\Vendor\Perly\Pay\Resource\AbstractResource */
trait Deletes
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> `['id' => …, 'object' => …, 'deleted' => true]`
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->requestor->request('DELETE', $this->idPath($id), null, [], $options);
    }
}
