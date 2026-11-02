<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * What WHMCS asks the module for on its own pages: the settings form
 * (_config) and the pay button on an invoice (_link).
 *
 * _link makes no call to Perly Pay. WHMCS runs it for every invoice page
 * and for the {$invoice_payment_link} of invoice emails, so it only draws
 * a form; the Perly Pay invoice is made (or the open one reused) when the
 * client presses the button, by pay.php. The form carries the invoice,
 * its amount and currency, signed with the secret key together with the
 * invoice's own balance when the page was drawn, so pay.php bills exactly
 * what the page showed and nothing a visitor typed — and a form drawn for
 * an older balance stops working (API3-01: where WHMCS converts for
 * processing, an old form billed the old converted amount and the callback
 * then recorded the new, larger balance as paid). The time it was drawn is
 * signed too: where WHMCS converts, a form older than an hour is drawn
 * again, since its amount came from that hour's rate.
 *
 *   https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *   https://developers.whmcs.com/payment-gateways/configuration/
 */
final class Gateway
{
    /**
     * The settings form. With $test (the admin pressed "Test connection") the
     * key's field says what Perly Pay answered for the saved settings.
     *
     * @param array<string, mixed> $saved getGatewayVariables(), for the callback URL and the test
     * @return array<string, array<string, mixed>>
     */
    public static function config(array $saved, Lang $lang, bool $test = false): array
    {
        $settings = new Settings($saved);
        $callback = Html::e($settings->callbackUrl());
        $keyHelp = Html::e($lang->t('config.api_key_help'))
            . ' <a href="configgateways.php?perlypay_test=1#m_perlypay">' . Html::e($lang->t('config.test')) . '</a>';
        if ($test) {
            $keyHelp .= '<br><strong>' . Html::e(ConnectionTest::run($settings, $lang)) . '</strong>';
        }

        return [
            'FriendlyName' => ['Type' => 'System', 'Value' => 'Perly Pay'],
            'apiKey' => [
                'FriendlyName' => $lang->t('config.api_key'),
                'Type' => 'password',
                'Size' => '48',
                'Default' => '',
                'Description' => $keyHelp,
            ],
            'webhookSecret' => [
                'FriendlyName' => $lang->t('config.webhook_secret'),
                'Type' => 'password',
                'Size' => '48',
                'Default' => '',
                'Description' => Html::e($lang->t('config.webhook_help')) . '<br><code>' . $callback . '</code>',
            ],
            'checkoutMode' => [
                'FriendlyName' => $lang->t('config.page'),
                'Type' => 'dropdown',
                'Options' => [
                    'overlay' => $lang->t('config.page_overlay'),
                    'redirect' => $lang->t('config.page_redirect'),
                ],
                'Default' => 'overlay',
                'Description' => Html::e($lang->t('config.page_help')),
            ],
            'currencies' => [
                'FriendlyName' => $lang->t('config.currencies'),
                'Type' => 'dropdown',
                'Options' => [
                    'USD,EUR' => $lang->t('config.currencies_both'),
                    'USD' => $lang->t('config.currencies_usd'),
                    'EUR' => $lang->t('config.currencies_eur'),
                ],
                'Default' => 'USD,EUR',
                'Description' => Html::e($lang->t('config.currencies_help')),
            ],
        ];
    }

    /**
     * The pay button on an invoice.
     *
     * @param array<string, mixed> $params what WHMCS passes to _link
     */
    public static function link(array $params, ?Lang $lang = null, ?Whmcs $whmcs = null): string
    {
        $lang ??= Lang::client($params);
        $settings = new Settings($params);
        if ($settings->mode() === null || $settings->webhookSecret() === '') {
            return self::note($lang->t('pay.not_set_up'));
        }
        $currency = strtoupper(is_string($params['currency'] ?? null) ? $params['currency'] : '');
        if (!$settings->accepts($currency)) {
            $list = implode($lang->t('pay.or'), $settings->currencies());

            return self::note($lang->t('pay.currency', $list));
        }
        $amount = Money::normalise($params['amount'] ?? null);
        $invoice = (int) ($params['invoiceid'] ?? 0);
        if ($amount === null || Money::toMinor($amount) === 0 || $invoice <= 0) {
            return '';
        }

        // The balance in the invoice's own currency, as pay.php will read it (the same as the amount unless WHMCS converts).
        $balance = $whmcs?->invoice($invoice)['balance'] ?? $amount;
        $drawn = time();
        $fields = [
            'invoice' => (string) $invoice,
            'amount' => $amount,
            'currency' => $currency,
            'drawn' => (string) $drawn,
            'sig' => self::signature($settings->apiKey(), $invoice, $amount, $currency, $balance, $drawn),
        ];
        $html = '<form method="post" action="' . Html::e($settings->moduleUrl('pay.php')) . '" class="perlypay-form"';
        if ($settings->overlay()) {
            $pin = self::payScript();
            $html .= ' data-perlypay="overlay" data-paid-url="' . Html::e(self::returnUrl($params, $settings, $invoice)) . '"';
            if ($pin !== null) {
                $html .= ' data-script="' . Html::e($pin['path']) . '" data-integrity="' . Html::e($pin['integrity']) . '"';
            }
        }
        $html .= '>';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . $name . '" value="' . Html::e($value) . '">';
        }
        $html .= '<button type="submit" class="btn btn-success">' . Html::e($lang->t('pay.button')) . '</button>';
        $html .= '<p class="perlypay-note small text-muted">' . Html::e($lang->t('pay.note')) . '</p>';
        if ($settings->mode() === 'test') {
            $html .= '<p class="perlypay-test small text-warning">' . Html::e($lang->t('pay.test_mode')) . '</p>';
        }
        $html .= '</form>';
        if ($settings->overlay()) {
            $html .= '<script src="' . Html::e($settings->moduleUrl('assets/perlypay.js') . '?v=' . PERLYPAY_WHMCS_VERSION) . '" defer></script>';
        }

        return $html;
    }

    /**
     * What makes the form unforgeable: an HMAC, under the secret key, of the invoice, the amount and currency
     * to bill, the invoice's own balance they were drawn for (API3-01) and, from this version, when they were
     * drawn (unix seconds). Without $drawn: the older form (perlypay-link/2), still in invoice emails.
     */
    public static function signature(string $apiKey, int $invoice, string $amount, string $currency, string $balance, ?int $drawn = null): string
    {
        $message = $invoice . "\n" . $amount . "\n" . strtoupper($currency) . "\n" . $balance;

        return $drawn === null
            ? hash_hmac('sha256', "perlypay-link/2\n" . $message, $apiKey)
            : hash_hmac('sha256', "perlypay-link/3\n" . $message . "\n" . $drawn, $apiKey);
    }

    /** @return array{path: string, integrity: string}|null pay.js as published, pinned at build time */
    public static function payScript(): ?array
    {
        $file = dirname(__DIR__) . '/pay-script.php';
        $pin = is_readable($file) ? require $file : null;

        return is_array($pin) && is_string($pin['path'] ?? null) && is_string($pin['integrity'] ?? null) ? $pin : null;
    }

    /** @param array<string, mixed> $params */
    private static function returnUrl(array $params, Settings $settings, int $invoice): string
    {
        $url = is_string($params['returnurl'] ?? null) ? Html::url($params['returnurl']) : null;

        return $url ?? $settings->systemUrl() . 'viewinvoice.php?id=' . $invoice;
    }

    private static function note(string $text): string
    {
        return '<p class="perlypay-note">' . Html::e($text) . '</p>';
    }
}
