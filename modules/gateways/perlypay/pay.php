<?php

/**
 * The pay button's target (POST, the form _link draws): the Perly Pay
 * invoice for this WHMCS invoice, then a 303 to it — or, for the overlay
 * (format=json), its id and address for pay.js. The rules are in
 * lib/PayLink.php.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/bootstrap.php';

use PerlyPay\Whmcs\CapsuleStore;
use PerlyPay\Whmcs\Html;
use PerlyPay\Whmcs\Lang;
use PerlyPay\Whmcs\PayLink;
use PerlyPay\Whmcs\Settings;
use PerlyPay\Whmcs\WhmcsFunctions;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$json = ($_POST['format'] ?? '') === 'json';
$gatewayParams = getGatewayVariables(Settings::MODULE);
$settings = new Settings(is_array($gatewayParams) ? $gatewayParams : []);
$lang = Lang::client();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $answer = ['status' => 405, 'error' => 'method_not_allowed', 'message' => $lang->t('pay.not_payable')];
} else {
    $answer = (new PayLink($settings, new WhmcsFunctions(), new CapsuleStore(), $settings->client(1), $lang))->handle($_POST);
}

http_response_code($answer['status']);
if ($json) {
    header('Content-Type: application/json');
    echo json_encode($answer['status'] === 200
        ? ['invoice' => $answer['invoice'], 'url' => $answer['url']]
        : ['error' => $answer['error'] ?? 'error', 'message' => $answer['message'] ?? '']);
    exit;
}
if ($answer['status'] === 200 && isset($answer['url'])) {
    header('Location: ' . $answer['url'], true, 303);
    exit;
}
$back = $settings->systemUrl() . (isset($_POST['invoice']) && is_string($_POST['invoice']) && ctype_digit($_POST['invoice']) ? 'viewinvoice.php?id=' . $_POST['invoice'] : 'clientarea.php');
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Perly Pay</title></head>'
    . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem">'
    . '<p>' . Html::e($answer['message'] ?? '') . '</p>'
    . '<p><a href="' . Html::e($back) . '">' . Html::e($lang->t('pay.back')) . '</a></p>'
    . '</body></html>';
