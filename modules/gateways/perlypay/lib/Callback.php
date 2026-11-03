<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\SignatureVerificationException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\PerlyPay;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Webhook;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * What a Perly Pay event does in WHMCS (modules/gateways/callback/perlypay.php).
 *
 * A request counts only with a good Perly-Signature (HMAC-SHA256 of the
 * timestamp and the raw body under the webhook's secret, at most five
 * minutes old, checked by the SDK in constant time); one that fails
 * changes nothing. Then, for a Perly Pay invoice this WHMCS made:
 *
 *   invoice.fulfilled (or paid/overpaid once fulfilled)  → checkCbInvoiceID, checkCbTransID,
 *       addInvoicePayment with the WHMCS balance the Perly Pay invoice was made for, in the
 *       invoice's own currency; the transaction id is the Perly Pay payment's (pay_…);
 *       logTransaction "Success" with the network, the coin amount and a transaction link
 *   invoice.paid / overpaid before fulfilment   → logged: Perly Pay is screening the sender
 *   invoice.seen / underpaid / review           → logged, no payment
 *   invoice.expired / canceled                  → logged, no payment; a new button press makes a new invoice
 *   invoice.refunded, refund.*                  → logged (a refund as the API returns it)
 *
 * A signed event is not taken at its word: the webhook secret alone is not
 * proof of payment. For invoice.* the invoice is retrieved from Perly Pay
 * with the module's API key and only that answer is used (status, mode,
 * fulfilment, amounts); an event it does not bear out (an "invoice.fulfilled"
 * for an invoice still open) is acknowledged and ignored. For refund.* the
 * refund is retrieved the same way, and what to send, where and the
 * transaction are logged from that answer, never from the event.
 *
 * A payment is recorded once: the row is claimed before addInvoicePayment
 * (a second delivery at the same moment waits for a retry) and WHMCS's own
 * checkCbTransID stands behind that. Each event id is applied once. The
 * Gateway Log gets ids, amounts, networks and links — never the body as it
 * came (it holds the client's email), a key or a signature.
 *
 * Answers: 200 for every verified event (applied, a duplicate, or not this
 * WHMCS's), 400 for a bad signature, 503 until configured, while another
 * delivery is recording the same payment, or while the invoice (or refund)
 * cannot be retrieved from Perly Pay, 500 when recording failed —
 * Perly Pay retries anything but 2xx for 72 hours.
 *
 *   https://developers.whmcs.com/payment-gateways/callbacks/
 */
final class Callback
{
    public const EVENT_TYPES = [
        'invoice.seen',
        'invoice.underpaid',
        'invoice.paid',
        'invoice.overpaid',
        'invoice.review',
        'invoice.expired',
        'invoice.canceled',
        'invoice.fulfilled',
        'invoice.refunded',
        'refund.requested',
        'refund.address_received',
        'refund.sent',
        'refund.confirmed',
        'refund.canceled',
    ];

    /** A refund's statuses in Perly Pay (src/core/statuses.ts), in order; canceled ends any of the first three. */
    private const REFUND_STATUSES = ['requested', 'awaiting_address', 'ready_to_send', 'sent', 'confirmed', 'canceled'];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly Settings $settings,
        private readonly Whmcs $whmcs,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $payload, ?string $signature): array
    {
        $secret = $this->settings->webhookSecret();
        if (!$this->settings->active() || $secret === '') {
            return self::answer(503, ['error' => 'not_configured']);
        }
        try {
            $event = Webhook::verify($payload, $signature, $secret, Webhook::DEFAULT_TOLERANCE_SECONDS, ($this->clock)());
        } catch (SignatureVerificationException $error) {
            $this->log(['refused' => $error->getErrorCode()], 'Signature refused');

            return self::answer(400, ['error' => $error->getErrorCode()]);
        }

        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $id = is_string($event['id'] ?? null) ? $event['id'] : '';
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        if ($type === 'webhook.test') {
            $this->log(['event' => $id, 'type' => $type], 'Test event received');

            return self::answer(200, ['received' => true, 'result' => 'test']);
        }
        if (!in_array($type, self::EVENT_TYPES, true) || $id === '' || $object === null) {
            return self::answer(200, ['received' => true, 'result' => 'ignored']);
        }

        $payInvoice = str_starts_with($type, 'refund.') ? ($object['invoice'] ?? null) : ($object['id'] ?? null);
        $row = is_string($payInvoice) ? $this->store->byPayInvoice($payInvoice) : null;
        if ($row === null) {
            // Another WHMCS (or shop) on the same Perly Pay account made it: theirs to handle.
            return self::answer(200, ['received' => true, 'result' => 'not_ours']);
        }
        if ($this->store->hasEvent($row['pay_invoice'], $id)) {
            return self::answer(200, ['received' => true, 'result' => 'duplicate']);
        }
        if (str_starts_with($type, 'refund.')) {
            // Nor is it proof of where a refund goes: a signed "refund.address_received" could name anyone's address,
            // and the Gateway Log would say to send it there. So the event only names the refund; it is asked of Perly
            // Pay with this WHMCS's own key, must be the refund of the invoice named, and only that answer is logged.
            $retrieved = $this->retrieveRefund(is_string($object['id'] ?? null) ? $object['id'] : '', $row['pay_invoice'], $id, $type);
            if (!isset($retrieved['id'])) {
                return $retrieved;
            }
            $object = $retrieved;
            if (!self::refundAgrees($type, $object)) {
                // Signed, but Perly Pay says otherwise (forged, or overtaken: canceled before it was ready, say).
                return self::answer(200, ['received' => true, 'result' => 'not_what_perly_pay_says']);
            }
        } else {
            // The webhook secret alone is not proof of payment: anyone who holds it (a developer who revealed it, a
            // leaked config) can sign an "invoice.fulfilled" for any invoice. So the event only names the invoice; it
            // is asked of Perly Pay with this WHMCS's own key, and that answer decides everything below.
            $retrieved = $this->retrieve($row['pay_invoice'], $id, $type);
            if (!isset($retrieved['id'])) {
                return $retrieved;
            }
            $object = $retrieved;
            if (!self::agrees($type, $object)) {
                // Signed, but Perly Pay says otherwise (forged, or overtaken by a later event): nothing to do or retry.
                return self::answer(200, ['received' => true, 'result' => 'not_what_perly_pay_says']);
            }
        }
        $mode = ($object['livemode'] ?? false) === true ? 'live' : 'test';
        if ($mode !== $row['mode']) {
            return self::answer(200, ['received' => true, 'result' => 'other_mode']);
        }

        $fulfilled = !str_starts_with($type, 'refund.') && !empty($object['fulfilled_at'])
            && in_array($type, ['invoice.fulfilled', 'invoice.paid', 'invoice.overpaid'], true);
        if ($fulfilled) {
            $outcome = $this->recordPayment($row, $event, $object);
        } else {
            $outcome = str_starts_with($type, 'refund.') ? $this->noteRefund($row, $event, $object) : $this->noteInvoice($row, $event, $object);
        }
        if ($outcome['status'] === 200) {
            $this->store->rememberEvent($row['pay_invoice'], $id);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @param array<string, mixed> $invoice
     * @return array{status: int, body: array<string, mixed>}
     */
    private function recordPayment(array $row, array $event, array $invoice): array
    {
        if ($row['whmcs_transid'] !== null) {
            return self::answer(200, ['received' => true, 'result' => 'already_paid']);
        }
        $whmcsInvoice = $this->whmcs->checkInvoice($row['whmcs_invoice'], $this->settings->name());
        $payments = $this->payments($row['pay_invoice']);
        $transid = self::transactionId($payments) ?? $row['pay_invoice'];

        $token = bin2hex(random_bytes(12));
        if (!$this->store->claim($row['pay_invoice'], $token, ($this->clock)())) {
            $again = $this->store->byPayInvoice($row['pay_invoice']);
            if ($again !== null && $again['whmcs_transid'] !== null) {
                return self::answer(200, ['received' => true, 'result' => 'already_paid']);
            }

            return self::answer(503, ['error' => 'in_progress']);
        }
        // WHMCS's own guard: it halts the request when this transaction is recorded already.
        $this->whmcs->checkTransaction($transid);
        try {
            $added = $this->whmcs->addPayment($whmcsInvoice, $transid, $row['whmcs_amount'], Settings::MODULE);
        } catch (\Throwable $error) {
            $this->store->release($row['pay_invoice'], $token);
            $this->log(['event' => $event['id'], 'invoice' => $row['pay_invoice'], 'whmcs_invoice' => $whmcsInvoice, 'error' => get_class($error)], 'Error');

            return self::answer(500, ['error' => 'not_recorded']);
        }
        if (!$added) {
            $this->store->release($row['pay_invoice'], $token);
            $this->log(['event' => $event['id'], 'invoice' => $row['pay_invoice'], 'whmcs_invoice' => $whmcsInvoice, 'error' => 'addInvoicePayment returned false'], 'Error');

            return self::answer(500, ['error' => 'not_recorded']);
        }
        $this->store->markPaid($row['pay_invoice'], $transid);

        $details = $this->base($row, $event) + [
            'whmcs_invoice' => $whmcsInvoice,
            'transaction' => $transid,
            'recorded' => $row['whmcs_amount'] . ' ' . $row['whmcs_currency'],
            'billed' => Money::fromMinor($row['amount']) . ' ' . $row['currency'],
        ];
        foreach (self::transfers($payments, $invoice) as $i => $line) {
            $details['transfer_' . ($i + 1)] = $line;
        }
        if (($invoice['status'] ?? '') === 'overpaid') {
            $details['overpaid'] = self::usd($invoice, 'received_usd') . ' received for ' . self::usd($invoice, 'total_usd') . '. Refund the difference in Perly Pay if you need to.';
        }
        $this->log($details, 'Success');

        return self::answer(200, ['received' => true, 'result' => 'paid']);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @param array<string, mixed> $invoice
     * @return array{status: int, body: array<string, mixed>}
     */
    private function noteInvoice(array $row, array $event, array $invoice): array
    {
        $type = (string) $event['type'];
        $details = $this->base($row, $event);
        $chain = is_array($invoice['payment'] ?? null) && is_string($invoice['payment']['chain'] ?? null) ? $invoice['payment']['chain'] : '';
        if ($chain !== '') {
            $details['network'] = Chains::name($chain);
        }
        switch ($type) {
            case 'invoice.paid':
            case 'invoice.overpaid':
                $result = 'Screening';
                $details['note'] = 'Payment received. Perly Pay is checking the sender; the invoice is marked paid when that is done.';
                break;
            case 'invoice.seen':
                $result = 'Seen';
                $details['note'] = 'Payment seen on the network. Waiting for confirmations.';
                break;
            case 'invoice.underpaid':
                $result = 'Underpaid';
                $details['received'] = self::usd($invoice, 'received_usd') . ' of ' . self::usd($invoice, 'total_usd');
                $details['note'] = 'Part paid; no payment recorded. The client can still send the rest.';
                break;
            case 'invoice.review':
                $result = 'Held for review';
                $details['reason'] = is_string($invoice['review_reason'] ?? null) ? $invoice['review_reason'] : 'review';
                $details['note'] = 'Decide in Perly Pay; no payment recorded until then.';
                break;
            case 'invoice.expired':
            case 'invoice.canceled':
                $result = $type === 'invoice.expired' ? 'Expired' : 'Canceled';
                if ($row['status'] === 'open') {
                    $this->store->setStatus($row['pay_invoice'], 'closed');
                }
                if (is_int($invoice['received_usd'] ?? null) && $invoice['received_usd'] > 0) {
                    $details['received'] = self::usd($invoice, 'received_usd') . ' of ' . self::usd($invoice, 'total_usd');
                    $details['note'] = 'Closed with part paid; no payment recorded. Refund it or accept it in Perly Pay.';
                } else {
                    $details['note'] = 'Closed unpaid; no payment recorded.';
                }
                break;
            case 'invoice.refunded':
                $result = ($invoice['status'] ?? '') === 'refunded' ? 'Refunded' : 'Partly refunded';
                break;
            default:
                $result = 'Info';
        }
        $this->log($details, $result);

        return self::answer(200, ['received' => true, 'result' => strtolower(str_replace(' ', '_', $result))]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @param array<string, mixed> $refund
     * @return array{status: int, body: array<string, mixed>}
     */
    private function noteRefund(array $row, array $event, array $refund): array
    {
        $chain = is_string($refund['chain'] ?? null) ? $refund['chain'] : '';
        $decimals = is_int($refund['decimals'] ?? null) ? $refund['decimals'] : 0;
        $asset = Chains::asset(is_string($refund['asset'] ?? null) ? $refund['asset'] : '', $chain);
        $txid = is_string($refund['txid'] ?? null) ? $refund['txid'] : '';
        $details = $this->base($row, $event) + ['refund' => is_string($refund['id'] ?? null) ? $refund['id'] : ''];
        if ($chain !== '') {
            $details['network'] = Chains::name($chain);
        }
        $send = is_string($refund['amount_to_send'] ?? null) ? $refund['amount_to_send'] : (is_string($refund['amount'] ?? null) ? $refund['amount'] : '');
        if ($send !== '') {
            $details['amount'] = Money::units($send, $decimals) . ' ' . $asset;
        }
        // Ready to send is the only state where the log says where to send: once sent, saying it again could have it
        // sent twice, and an address Perly Pay refused on screening is not one to send to.
        $ready = ($refund['status'] ?? null) === 'ready_to_send';
        $result = match ((string) $event['type']) {
            'refund.requested' => 'Refund opened',
            'refund.address_received' => $ready ? 'Refund ready to send' : 'Refund address received',
            'refund.sent' => 'Refund sent',
            'refund.confirmed' => 'Refund confirmed',
            default => 'Refund canceled',
        };
        if ($event['type'] === 'refund.address_received' && $ready && is_string($refund['refund_address'] ?? null)) {
            $details['send_to'] = $refund['refund_address'];
            $details['note'] = 'Send the amount from your wallet, then paste the transaction id in Perly Pay.';
        }
        if ($txid !== '') {
            $details['transaction'] = Chains::txUrl($chain, $txid) ?? $txid;
        }
        $this->log($details, $result);

        return self::answer(200, ['received' => true, 'result' => 'refund_noted']);
    }

    /**
     * The invoice as Perly Pay's API has it, or the answer to give when it cannot be had: 503 (Perly Pay retries) when
     * there is no key or Perly Pay could not be asked, 200 "not_ours" when this key has no such invoice.
     *
     * @return array<string, mixed>
     */
    private function retrieve(string $payInvoice, string $eventId, string $type): array
    {
        if ($this->client === null) {
            return self::answer(503, ['error' => 'no_api_key']);
        }
        try {
            $invoice = $this->client->invoices->retrieve($payInvoice, ['max_retries' => 1]);
        } catch (PerlyPayException $error) {
            if ($error->getHttpStatus() === 404) {
                return self::answer(200, ['received' => true, 'result' => 'not_ours']);
            }
            $this->log(['event' => $eventId, 'type' => $type, 'invoice' => $payInvoice, 'error' => $error->getErrorCode()], 'Error');

            return self::answer(503, ['error' => 'invoice_unavailable']);
        }

        return ($invoice['id'] ?? null) === $payInvoice ? $invoice : self::answer(200, ['received' => true, 'result' => 'not_ours']);
    }

    /**
     * The refund as Perly Pay's API has it, or the answer to give when it cannot be had: 503 (Perly Pay retries) when
     * there is no key or Perly Pay could not be asked, 200 "not_ours" when this key has no such refund, or the one it
     * returns is another, or a refund of another invoice than the event names.
     *
     * @return array<string, mixed>
     */
    private function retrieveRefund(string $refundId, string $payInvoice, string $eventId, string $type): array
    {
        if ($refundId === '') {
            return self::answer(200, ['received' => true, 'result' => 'not_ours']);
        }
        if ($this->client === null) {
            return self::answer(503, ['error' => 'no_api_key']);
        }
        try {
            $refund = $this->client->refunds->retrieve($refundId, ['max_retries' => 1]);
        } catch (PerlyPayException $error) {
            if ($error->getHttpStatus() === 404) {
                return self::answer(200, ['received' => true, 'result' => 'not_ours']);
            }
            $this->log(['event' => $eventId, 'type' => $type, 'invoice' => $payInvoice, 'refund' => $refundId, 'error' => $error->getErrorCode()], 'Error');

            return self::answer(503, ['error' => 'refund_unavailable']);
        }

        return ($refund['id'] ?? null) === $refundId && ($refund['invoice'] ?? null) === $payInvoice
            ? $refund
            : self::answer(200, ['received' => true, 'result' => 'not_ours']);
    }

    /**
     * Whether the refund Perly Pay returned is in a state the event can mean: opened (any status), an address given
     * (ready to send, or sent since, or one Perly Pay refused on screening, the refund waiting for another), sent (or
     * confirmed since), confirmed, canceled.
     *
     * @param array<string, mixed> $refund
     */
    private static function refundAgrees(string $type, array $refund): bool
    {
        $status = is_string($refund['status'] ?? null) ? $refund['status'] : '';

        return match ($type) {
            'refund.requested' => in_array($status, self::REFUND_STATUSES, true),
            'refund.address_received' => in_array($status, ['ready_to_send', 'sent', 'confirmed'], true)
                || (in_array($status, ['requested', 'awaiting_address'], true) && is_string($refund['refund_address'] ?? null) && $refund['refund_address'] !== ''),
            'refund.sent' => in_array($status, ['sent', 'confirmed'], true),
            default => 'refund.' . $status === $type,
        };
    }

    /**
     * Whether the invoice Perly Pay returned is in a state the event can mean. An "invoice.fulfilled" needs a paid
     * invoice that is fulfilled; the rest need the status they are named for (a refund event, either refund status).
     *
     * @param array<string, mixed> $invoice
     */
    private static function agrees(string $type, array $invoice): bool
    {
        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : '';

        return match ($type) {
            'invoice.fulfilled' => in_array($status, ['paid', 'overpaid'], true) && !empty($invoice['fulfilled_at']),
            'invoice.paid', 'invoice.overpaid' => in_array($status, ['paid', 'overpaid'], true),
            'invoice.refunded' => in_array($status, ['refunded', 'partially_refunded'], true),
            default => 'invoice.' . $status === $type,
        };
    }

    /**
     * The invoice's transfers from the API, newest first; null when it could not be asked.
     *
     * @return list<array<string, mixed>>|null
     */
    private function payments(string $payInvoice): ?array
    {
        try {
            $page = $this->client?->payments->list(['invoice' => $payInvoice, 'limit' => 100], ['max_retries' => 1]);
        } catch (\Throwable) {
            return null;
        }
        $data = is_array($page['data'] ?? null) ? $page['data'] : null;

        return $data === null ? null : array_values(array_filter($data, static fn ($p): bool => is_array($p) && empty($p['dropped_at'])));
    }

    /**
     * The WHMCS transaction id: the first transfer that arrived for the invoice (pay_…).
     * The list is newest first, so it is the last one.
     *
     * @param list<array<string, mixed>>|null $payments
     */
    private static function transactionId(?array $payments): ?string
    {
        if ($payments === null || $payments === []) {
            return null;
        }
        $first = $payments[count($payments) - 1];

        return is_string($first['id'] ?? null) && preg_match('/^pay_[A-Za-z0-9]{1,60}$/', $first['id']) === 1 ? $first['id'] : null;
    }

    /**
     * "0.0003125 BTC on Bitcoin testnet4: https://…" for each transfer, oldest first.
     *
     * @param list<array<string, mixed>>|null $payments
     * @param array<string, mixed> $invoice
     * @return list<string>
     */
    private static function transfers(?array $payments, array $invoice): array
    {
        $lines = [];
        foreach (array_reverse($payments ?? []) as $payment) {
            $chain = is_string($payment['chain'] ?? null) ? $payment['chain'] : '';
            $txid = is_string($payment['txid'] ?? null) ? $payment['txid'] : '';
            $amount = Money::units(is_string($payment['amount'] ?? null) ? $payment['amount'] : '0', is_int($payment['decimals'] ?? null) ? $payment['decimals'] : 0);
            $asset = Chains::asset(is_string($payment['asset'] ?? null) ? $payment['asset'] : '', $chain);
            $lines[] = $amount . ' ' . $asset . ' on ' . Chains::name($chain) . ': ' . (Chains::txUrl($chain, $txid) ?? $txid);
        }
        if ($lines === [] && is_array($invoice['payment'] ?? null)) {
            $rail = $invoice['payment'];
            $chain = is_string($rail['chain'] ?? null) ? $rail['chain'] : '';
            $decimals = is_int($rail['decimals'] ?? null) ? $rail['decimals'] : 0;
            $lines[] = Money::units(is_string($invoice['amount_received'] ?? null) ? $invoice['amount_received'] : '0', $decimals) . ' '
                . Chains::asset(is_string($rail['asset'] ?? null) ? $rail['asset'] : '', $chain) . ' on ' . Chains::name($chain);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function base(array $row, array $event): array
    {
        return [
            'event' => (string) $event['id'],
            'type' => (string) $event['type'],
            'invoice' => $row['pay_invoice'],
            'whmcs_invoice' => $row['whmcs_invoice'],
            'mode' => $row['mode'] === 'test' ? 'test (no real money)' : 'live',
        ];
    }

    /** @param array<string, mixed> $invoice */
    private static function usd(array $invoice, string $field): string
    {
        return '$' . Money::fromMinor(is_int($invoice[$field] ?? null) ? $invoice[$field] : 0);
    }

    /** @param array<string, mixed> $data */
    private function log(array $data, string $result): void
    {
        $this->whmcs->log($this->settings->name(), $data, $result);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function answer(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
