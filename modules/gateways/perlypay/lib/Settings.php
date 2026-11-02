<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

use PerlyPay\Whmcs\Vendor\Perly\Pay\Http\Transport;
use PerlyPay\Whmcs\Vendor\Perly\Pay\PerlyPay;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The gateway's settings as WHMCS passes them (getGatewayVariables(), or
 * the $params of _link and _refund), read in one place.
 */
final class Settings
{
    public const MODULE = 'perlypay';
    public const DEFAULT_BASE = 'https://pay.perly.io';
    public const CURRENCY_CHOICES = ['USD,EUR', 'USD', 'EUR'];

    /** Tests (and only tests) answer the API from here instead of the network. */
    public static ?Transport $transport = null;

    /** @param array<string, mixed> $params */
    public function __construct(private readonly array $params)
    {
    }

    public function active(): bool
    {
        return is_string($this->params['type'] ?? null) && $this->params['type'] !== '';
    }

    public function apiKey(): string
    {
        return trim($this->string('apiKey'));
    }

    public function webhookSecret(): string
    {
        return trim($this->string('webhookSecret'));
    }

    /** "test" or "live" from the key's prefix; null while no valid key is saved. */
    public function mode(): ?string
    {
        return Keys::mode($this->apiKey());
    }

    public function overlay(): bool
    {
        return $this->string('checkoutMode') !== 'redirect';
    }

    /** @return list<string> the currencies this WHMCS lets clients pay in with Perly Pay */
    public function currencies(): array
    {
        $choice = $this->string('currencies');

        return explode(',', in_array($choice, self::CURRENCY_CHOICES, true) ? $choice : 'USD,EUR');
    }

    public function accepts(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->currencies(), true);
    }

    /** The name WHMCS shows for the gateway: logTransaction() and checkCbInvoiceID() take it. */
    public function name(): string
    {
        $name = $this->string('name');

        return $name !== '' ? $name : 'Perly Pay';
    }

    /** WHMCS's System URL, with its trailing slash. */
    public function systemUrl(): string
    {
        $url = $this->string('systemurl');
        if ($url === '' && class_exists('\\WHMCS\\Config\\Setting')) {
            $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        }

        return rtrim($url, '/') . '/';
    }

    public function companyName(): string
    {
        $name = $this->string('companyname');
        if ($name === '' && class_exists('\\WHMCS\\Config\\Setting')) {
            $name = (string) \WHMCS\Config\Setting::getValue('CompanyName');
        }

        return $name;
    }

    /** Where Perly Pay sends events: the module's callback file. */
    public function callbackUrl(): string
    {
        return $this->systemUrl() . 'modules/gateways/callback/' . self::MODULE . '.php';
    }

    /** A file of the module, by its path under modules/gateways/perlypay/. */
    public function moduleUrl(string $path): string
    {
        return $this->systemUrl() . 'modules/gateways/' . self::MODULE . '/' . ltrim($path, '/');
    }

    /**
     * Perly Pay's address. Locally (Perly Pay's own tests) PERLY_PAY_API_BASE is
     * defined in WHMCS's configuration.php; on a real WHMCS it is pay.perly.io.
     */
    public static function apiBase(): string
    {
        $base = defined('PERLY_PAY_API_BASE') && is_string(PERLY_PAY_API_BASE) ? PERLY_PAY_API_BASE : self::DEFAULT_BASE;

        return rtrim(Html::url($base) ?? self::DEFAULT_BASE, '/');
    }

    /** An API client for the saved key, or null without one. */
    public function client(int $maxRetries = 2, ?string $key = null): ?PerlyPay
    {
        $key ??= $this->apiKey();
        if (Keys::mode($key) === null) {
            return null;
        }
        $options = ['base_url' => self::apiBase(), 'max_retries' => $maxRetries, 'timeout' => 20];
        if (self::$transport !== null) {
            $options['transport'] = self::$transport;
            $options['sleep'] = static function (int $ms): void {
            };
        }

        return new PerlyPay($key, $options);
    }

    private function string(string $key): string
    {
        $value = $this->params[$key] ?? '';

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
