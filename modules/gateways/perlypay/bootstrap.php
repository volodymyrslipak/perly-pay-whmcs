<?php

/**
 * Class loading without Composer on the WHMCS server: the module's own
 * classes under PerlyPay\Whmcs\ (lib/), and Perly Pay's PHP SDK, copied
 * in at build time under its own prefix (PerlyPay\Whmcs\Vendor\Perly\Pay\,
 * in vendor/perly-pay-sdk) so it can never clash with another module that
 * ships the SDK too.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('PERLYPAY_WHMCS_VERSION')) {
    define('PERLYPAY_WHMCS_VERSION', '1.0.1');

    spl_autoload_register(static function (string $class): void {
        $roots = [
            'PerlyPay\\Whmcs\\Vendor\\Perly\\Pay\\' => __DIR__ . '/vendor/perly-pay-sdk/src/',
            'PerlyPay\\Whmcs\\' => __DIR__ . '/lib/',
        ];
        foreach ($roots as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }

            return;
        }
    });
}
