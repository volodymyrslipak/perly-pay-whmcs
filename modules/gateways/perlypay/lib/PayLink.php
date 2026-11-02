<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\PerlyPay;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The pay button pressed (pay.php): the Perly Pay invoice for this WHMCS
 * invoice — the open one again while it can still be paid for the same
 * amount, else a new one — and where to send the client.
 *
 * Checked first: the form's signature (the page drew it, for the
 * invoice's balance at that moment — a form for an older balance fails it,
 * converted or not: API3-01), the currency, that the WHMCS invoice is
 * Unpaid, and — unless WHMCS converts for processing — that its balance is
 * what the form bills. Where WHMCS converts, the amount came from the
 * rate of the moment the page was drawn, so the form must be under an
 * hour old (whmcs/pay-form-no-freshness-stale-rate); an older one sends
 * the client back to the invoice, which draws it again at today's rate.
 */
final class PayLink
{
    /** Perly Pay invoices the client can still pay. */
    private const PAYABLE = ['open', 'seen', 'underpaid'];

    /** How long a converted form's amount stands, in seconds: its rate is the one of the moment it was drawn. */
    private const CONVERTED_FORM_SECONDS = 3600;

    /** New invoices tried in one press before giving up. */
    private const ATTEMPTS = 5;

    public function __construct(
        private readonly Settings $settings,
        private readonly Whmcs $whmcs,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
        private readonly Lang $lang,
    ) {
    }

    /**
     * @param array<string, mixed> $post the form
     * @return array{status: int, invoice?: string, url?: string, error?: string, message?: string}
     */
    public function handle(array $post): array
    {
        if ($this->client === null || !$this->settings->active() || $this->settings->webhookSecret() === '') {
            return $this->refuse(503, 'not_set_up', 'pay.not_set_up');
        }
        $invoiceId = is_string($post['invoice'] ?? null) && preg_match('/^[1-9]\d{0,9}$/', $post['invoice']) === 1 ? (int) $post['invoice'] : 0;
        $amount = is_string($post['amount'] ?? null) && preg_match('/^\d{1,13}\.\d{2}$/', $post['amount']) === 1 ? $post['amount'] : '';
        $currency = is_string($post['currency'] ?? null) ? strtoupper($post['currency']) : '';
        $sig = is_string($post['sig'] ?? null) ? $post['sig'] : '';
        // When the page drew the form; absent on a form from before it was signed (perlypay-link/2).
        $drawn = is_string($post['drawn'] ?? null) && preg_match('/^\d{1,12}$/', $post['drawn']) === 1 ? (int) $post['drawn'] : null;
        if ($invoiceId === 0 || $amount === '') {
            return $this->refuse(400, 'bad_request', 'pay.changed');
        }
        // The form was signed with the invoice's balance when the page was drawn: a changed balance breaks it.
        $invoice = $this->whmcs->invoice($invoiceId);
        $balance = $invoice['balance'] ?? $amount;
        if (!hash_equals(Gateway::signature($this->settings->apiKey(), $invoiceId, $amount, $currency, $balance, $drawn), $sig)) {
            return $this->refuse(400, 'bad_request', 'pay.changed');
        }
        if (!$this->settings->accepts($currency) || !in_array($currency, Money::CURRENCIES, true)) {
            return $this->refuse(400, 'currency', 'pay.unavailable');
        }
        $minor = Money::toMinor($amount);
        if ($minor === null || $minor <= 0) {
            return $this->refuse(400, 'nothing_to_pay', 'pay.not_payable');
        }

        if ($invoice === null) {
            return $this->refuse(404, 'no_invoice', 'pay.not_payable');
        }
        if ($invoice['status'] !== 'Unpaid') {
            return $this->refuse(409, 'not_payable', 'pay.not_payable');
        }
        $client = $this->whmcs->client($invoice['userid']);
        $ownCurrency = $client !== null && $client['currency'] !== '' ? $client['currency'] : $currency;
        if ($ownCurrency === $currency && $balance !== $amount) {
            return $this->refuse(409, 'changed', 'pay.changed');
        }
        if ($ownCurrency !== $currency && ($drawn === null || abs(time() - $drawn) > self::CONVERTED_FORM_SECONDS)) {
            return $this->refuse(409, 'changed', 'pay.changed');
        }

        try {
            $made = $this->reuse($invoiceId, $minor, $currency, $balance) ?? $this->create($invoice, $client, $minor, $currency, $balance, $ownCurrency);
        } catch (PerlyPayException $error) {
            $this->whmcs->log($this->settings->name(), ['action' => 'create invoice', 'whmcs_invoice' => $invoiceId, 'code' => $error->getErrorCode(), 'request' => (string) $error->getRequestId()], 'Error');

            return $this->refuse(502, 'perly_pay', 'pay.unavailable');
        }
        $url = Html::url($made['url']);
        if ($url === null) {
            return $this->refuse(502, 'perly_pay', 'pay.unavailable');
        }

        return ['status' => 200, 'invoice' => $made['id'], 'url' => $url];
    }

    /**
     * The last Perly Pay invoice for this WHMCS invoice, if it still bills the same and can be paid.
     *
     * @return array{id: string, url: string}|null
     */
    private function reuse(int $invoiceId, int $minor, string $currency, string $balance): ?array
    {
        $mode = (string) $this->settings->mode();
        $row = $this->store->latestFor($invoiceId, $mode);
        if ($row === null || $row['status'] !== 'open') {
            return null;
        }
        if ($row['amount'] !== $minor || $row['currency'] !== $currency || $row['whmcs_amount'] !== $balance) {
            $this->store->setStatus($row['pay_invoice'], 'closed');

            return null;
        }
        try {
            $last = $this->client?->invoices->retrieve($row['pay_invoice'], ['max_retries' => 1]) ?? [];
        } catch (PerlyPayException) {
            return null;
        }
        if (in_array($last['status'] ?? '', self::PAYABLE, true) && ($last['total'] ?? null) === $minor && ($last['currency'] ?? '') === $currency) {
            return ['id' => $row['pay_invoice'], 'url' => is_string($last['url'] ?? null) ? $last['url'] : $row['url']];
        }
        if (!in_array($last['status'] ?? '', self::PAYABLE, true)) {
            $this->store->setStatus($row['pay_invoice'], 'closed');
        }

        return null;
    }

    /**
     * A new Perly Pay invoice.
     *
     * @param array{id: int, number: string, userid: int, status: string, balance: ?string} $invoice
     * @param array{email: string, currency: string, language: string}|null $client
     * @return array{id: string, url: string}
     */
    private function create(array $invoice, ?array $client, int $minor, string $currency, string $balance, string $ownCurrency): array
    {
        $attempt = $this->store->count($invoice['id']) + 1;
        $company = $this->settings->companyName();
        $host = (string) parse_url($this->settings->systemUrl(), PHP_URL_HOST);
        $params = [
            'amount' => $minor,
            'currency' => $currency,
            'description' => Html::cut($this->lang->t('pay.description', $invoice['number'], $company !== '' ? $company : $host), 300),
            'return_url' => $this->settings->systemUrl() . 'viewinvoice.php?id=' . $invoice['id'],
            'locale' => $this->lang->name === 'ukrainian' ? 'uk' : 'en',
            'metadata' => [
                'whmcs_invoice' => (string) $invoice['id'],
                'whmcs_invoice_number' => Html::cut($invoice['number'], 100),
                'whmcs_client' => (string) $invoice['userid'],
                'whmcs' => Html::cut($host, 200),
                'source' => 'whmcs',
                'module' => PERLYPAY_WHMCS_VERSION,
            ],
        ];
        $email = $client['email'] ?? '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254) {
            $params['customer_email'] = $email;
        }
        // The same WHMCS, invoice, attempt and amount make the same Perly Pay invoice, however often the
        // button is pressed. A key Perly Pay already answered for an invoice that can no longer be paid
        // (this WHMCS's table was emptied or restored, its invoice ids reused) moves on to the next attempt.
        for ($tries = 0; $tries < self::ATTEMPTS; $tries++, $attempt++) {
            $key = 'whmcs_' . substr(hash('sha256', $this->settings->systemUrl() . '|' . $invoice['id'] . '|' . $attempt . '|' . $minor . $currency), 0, 40);
            $made = $this->client?->invoices->create($params, ['idempotency_key' => $key]) ?? [];
            if (!is_string($made['id'] ?? null) || !is_string($made['url'] ?? null)) {
                throw new PerlyPayException('Perly Pay answered without an invoice.');
            }
            // A replay is the stored first answer, with the status of that moment: ask again when it is not new.
            $created = is_string($made['created'] ?? null) ? strtotime($made['created']) : false;
            if ($created !== false && $created < time() - 60) {
                $made = $this->client?->invoices->retrieve($made['id'], ['max_retries' => 1]) ?? $made;
            }
            $payable = in_array($made['status'] ?? 'open', self::PAYABLE, true) && ($made['total'] ?? $minor) === $minor && ($made['currency'] ?? $currency) === $currency;
            if ($this->store->byPayInvoice($made['id']) === null) {
                $this->store->add([
                    'pay_invoice' => $made['id'],
                    'whmcs_invoice' => $invoice['id'],
                    'mode' => (string) $this->settings->mode(),
                    'amount' => $minor,
                    'currency' => $currency,
                    'whmcs_amount' => $balance,
                    'whmcs_currency' => $ownCurrency,
                    'url' => $made['url'],
                ]);
            }
            if ($payable) {
                return ['id' => $made['id'], 'url' => $made['url']];
            }
            $this->store->setStatus($made['id'], 'closed');
        }
        throw new PerlyPayException('Perly Pay kept answering with invoices that cannot be paid.');
    }

    /** @return array{status: int, error: string, message: string} */
    private function refuse(int $status, string $code, string $key): array
    {
        return ['status' => $status, 'error' => $code, 'message' => $this->lang->t($key)];
    }
}
