<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The real WHMCS: its documented helpers (includes/gatewayfunctions.php,
 * includes/invoicefunctions.php, loaded by the callback) and its Local API.
 *
 *   https://developers.whmcs.com/payment-gateways/callbacks/
 *   https://developers.whmcs.com/api-reference/getinvoice/
 *   https://developers.whmcs.com/api-reference/getclientsdetails/
 */
final class WhmcsFunctions implements Whmcs
{
    public function invoice(int $id): ?array
    {
        $found = localAPI('GetInvoice', ['invoiceid' => $id]);
        if (!is_array($found) || ($found['result'] ?? '') !== 'success') {
            return null;
        }
        $number = $found['invoicenum'] ?? '';

        return [
            'id' => (int) ($found['invoiceid'] ?? $id),
            'number' => is_scalar($number) && (string) $number !== '' ? (string) $number : (string) $id,
            'userid' => (int) ($found['userid'] ?? 0),
            'status' => is_string($found['status'] ?? null) ? $found['status'] : '',
            'balance' => Money::normalise($found['balance'] ?? null),
        ];
    }

    public function client(int $id): ?array
    {
        $found = localAPI('GetClientsDetails', ['clientid' => $id, 'stats' => false]);
        if (!is_array($found) || ($found['result'] ?? '') !== 'success') {
            return null;
        }
        // WHMCS 8 nests the client under `client`; the flat keys are the older (deprecated) copy.
        $client = is_array($found['client'] ?? null) ? $found['client'] + $found : $found;

        return [
            'email' => is_string($client['email'] ?? null) ? $client['email'] : '',
            'currency' => is_string($client['currency_code'] ?? null) ? strtoupper($client['currency_code']) : '',
            'language' => is_string($client['language'] ?? null) ? $client['language'] : '',
        ];
    }

    public function checkInvoice(int $id, string $gatewayName): int
    {
        return (int) checkCbInvoiceID($id, $gatewayName);
    }

    public function checkTransaction(string $transid): void
    {
        checkCbTransID($transid);
    }

    public function addPayment(int $invoiceId, string $transid, string $amount, string $module): bool
    {
        return (bool) addInvoicePayment($invoiceId, $transid, $amount, '0.00', $module);
    }

    public function log(string $gatewayName, array $data, string $result): void
    {
        logTransaction($gatewayName, $data, $result);
    }
}
