<?php

/**
 * Where Perly Pay sends events: https://your-whmcs/modules/gateways/callback/perlypay.php
 * (add it in Perly Pay → Developers → Webhooks; its signing secret goes in the gateway settings).
 *
 * Public by design (Perly Pay's server calls it): what makes a request
 * count is its Perly-Signature, checked before anything else. The rules
 * are in lib/Callback.php.
 *
 *   https://developers.whmcs.com/payment-gateways/callbacks/
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../perlypay/bootstrap.php';

use PerlyPay\Whmcs\Callback;
use PerlyPay\Whmcs\CapsuleStore;
use PerlyPay\Whmcs\Settings;
use PerlyPay\Whmcs\WhmcsFunctions;

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$gatewayParams = getGatewayVariables(Settings::MODULE);
$settings = new Settings(is_array($gatewayParams) ? $gatewayParams : []);
if (!$settings->active()) {
    http_response_code(503);
    echo json_encode(['error' => 'module_not_activated']);
    exit;
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PERLY_SIGNATURE'] ?? null;
$answer = (new Callback($settings, new WhmcsFunctions(), new CapsuleStore(), $settings->client(1)))
    ->handle(is_string($payload) ? $payload : '', is_string($signature) ? $signature : null);

http_response_code($answer['status']);
echo json_encode($answer['body']);
