<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\AuthenticationException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\ConnectionException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\PerlyPay;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * A refund from WHMCS (_refund) opens one in Perly Pay, in the coin that
 * was paid: all of what is left, or for part of the payment the same share
 * of the coins. Perly Pay holds no money: it emails the client for an
 * address and checks it, and the merchant sends from their own wallet and
 * pastes the transaction id in Perly Pay. So "success" here means the
 * refund is open, which the Gateway Log entry says in words.
 *
 *   https://developers.whmcs.com/payment-gateways/refunds/
 */
final class Refund
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
    ) {
    }

    /**
     * @param array<string, mixed> $params what WHMCS passes to _refund
     * @return array{status: string, rawdata: array<string, mixed>|string, transid?: string, fees?: string}
     */
    public function handle(array $params): array
    {
        if ($this->client === null) {
            return self::fail('error', 'Add your Perly Pay secret key in the gateway settings first.');
        }
        $transid = is_string($params['transid'] ?? null) ? trim($params['transid']) : '';
        $row = $this->store->byTransaction($transid);
        if ($row === null) {
            return self::fail('declined', 'This transaction was not recorded by Perly Pay, so there is nothing to refund there.');
        }
        if ($row['mode'] !== $this->client->mode) {
            return self::fail('declined', 'This payment was made in ' . $row['mode'] . ' mode; the saved key is a ' . $this->client->mode . ' key.');
        }
        $part = Money::toMinor(Money::normalise($params['amount'] ?? null) ?? '');
        if ($part === null || $part <= 0) {
            return self::fail('declined', 'Enter an amount to refund.');
        }
        // The amount comes in the currency WHMCS recorded the payment in, or in Perly Pay's when WHMCS converted.
        $currency = strtoupper(is_string($params['currency'] ?? null) ? $params['currency'] : $row['whmcs_currency']);
        $total = match ($currency) {
            $row['whmcs_currency'] => Money::toMinor($row['whmcs_amount']),
            $row['currency'] => $row['amount'],
            default => null,
        };
        if ($total === null || $total <= 0) {
            return self::fail('declined', 'Refunds are taken in ' . $row['whmcs_currency'] . ', the currency this payment was recorded in.');
        }
        if ($part > $total) {
            return self::fail('declined', 'That is more than was paid with Perly Pay (' . Money::fromMinor($total) . ' ' . $currency . ').');
        }

        try {
            $request = ['invoice' => $row['pay_invoice'], 'reason' => 'Refund from WHMCS invoice #' . $row['whmcs_invoice']];
            if ($part < $total) {
                $invoice = $this->client->invoices->retrieve($row['pay_invoice']);
                $units = Money::share(is_string($invoice['amount_received'] ?? null) ? $invoice['amount_received'] : '', $part, $total);
                if ($units === null || $units === '0') {
                    return self::fail('declined', 'That amount is too small to refund.');
                }
                $request['amount'] = $units;
            }
            $key = 'whmcs_refund_' . substr(hash('sha256', $this->settings->systemUrl() . '|' . $transid . '|' . count($row['refunds']) . '|' . $part), 0, 40);
            $refund = $this->client->refunds->create($request, ['idempotency_key' => $key]);
        } catch (PerlyPayException $error) {
            return self::fail($error instanceof ConnectionException || $error instanceof AuthenticationException ? 'error' : 'declined', self::explain($error), $error);
        }

        $id = is_string($refund['id'] ?? null) ? $refund['id'] : '';
        if ($id === '') {
            return self::fail('error', 'Perly Pay answered without a refund.');
        }
        $this->store->addRefund($row['pay_invoice'], $id);

        return [
            'status' => 'success',
            'transid' => $id,
            'fees' => '0.00',
            'rawdata' => [
                'refund' => $id,
                'status' => is_string($refund['status'] ?? null) ? $refund['status'] : '',
                'invoice' => $row['pay_invoice'],
                'amount' => Money::fromMinor($part) . ' ' . $currency,
                'coins' => isset($request['amount']) ? Money::units($request['amount'], is_int($refund['decimals'] ?? null) ? $refund['decimals'] : 0) . ' ' . Chains::asset((string) ($refund['asset'] ?? ''), (string) ($refund['chain'] ?? '')) : 'all that is left',
                'next' => 'Opened in Perly Pay. The client gets an email to give an address; once it is checked, send the amount from your wallet and paste the transaction id in Perly Pay.',
            ],
        ];
    }

    private static function explain(PerlyPayException $error): string
    {
        return match (true) {
            $error->getErrorCode() === 'sanctioned_sender' => 'Money on this invoice came from an address on a sanctions list, or one its issuer froze. It cannot be refunded.',
            $error instanceof AuthenticationException => 'Perly Pay refused the secret key. Check it in the gateway settings.',
            $error instanceof ConnectionException => 'Could not reach Perly Pay. Try again in a minute.',
            default => 'Perly Pay: ' . $error->getMessage(),
        };
    }

    /** @return array{status: string, rawdata: array<string, mixed>} */
    private static function fail(string $status, string $message, ?PerlyPayException $error = null): array
    {
        $raw = ['error' => $message];
        if ($error !== null) {
            $raw['code'] = $error->getErrorCode();
            $raw['request'] = (string) $error->getRequestId();
        }

        return ['status' => $status, 'rawdata' => $raw];
    }
}
