<?php

/**
 * Perly Pay for WHMCS: bitcoin and USDC/USDT, paid straight to your own
 * wallet. Perly Pay never holds the money; it charges 2.5¢ a payment, never
 * more than 0.5% of it, billed after the month.
 *
 * A third-party gateway module (the client pays on Perly Pay's checkout,
 * over the invoice or on its own page). Its parts:
 *
 *   modules/gateways/perlypay.php            this file: what WHMCS calls (_MetaData, _config, _link, _refund)
 *   modules/gateways/callback/perlypay.php   where Perly Pay's signed webhooks land
 *   modules/gateways/perlypay/pay.php        the pay button: makes (or reuses) the Perly Pay invoice
 *   modules/gateways/perlypay/lib/           the rules, testable without WHMCS
 *
 *   https://developers.whmcs.com/payment-gateways/third-party-gateway/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/perlypay/bootstrap.php';

use PerlyPay\Whmcs\CapsuleStore;
use PerlyPay\Whmcs\Gateway;
use PerlyPay\Whmcs\Lang;
use PerlyPay\Whmcs\Refund;
use PerlyPay\Whmcs\Settings;
use PerlyPay\Whmcs\WhmcsFunctions;

/**
 * @return array<string, mixed>
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 */
function perlypay_MetaData()
{
    return [
        'DisplayName' => 'Perly Pay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * The settings form. "Test connection" (a link in the key's field) reloads
 * this admin page with perlypay_test=1; only then is Perly Pay asked.
 *
 * @return array<string, array<string, mixed>>
 */
function perlypay_config()
{
    $test = defined('ADMINAREA') && isset($_GET['perlypay_test']) && $_GET['perlypay_test'] === '1';
    $saved = function_exists('getGatewayVariables') ? getGatewayVariables(Settings::MODULE) : [];

    return Gateway::config(is_array($saved) ? $saved : [], Lang::admin(), $test);
}

/**
 * The pay button on an invoice.
 *
 * @param array<string, mixed> $params
 */
function perlypay_link($params)
{
    return Gateway::link(is_array($params) ? $params : [], null, new WhmcsFunctions());
}

/**
 * A refund from WHMCS: opens one in Perly Pay.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function perlypay_refund($params)
{
    $params = is_array($params) ? $params : [];
    $settings = new Settings($params);

    return (new Refund($settings, new CapsuleStore(), $settings->client()))->handle($params);
}
