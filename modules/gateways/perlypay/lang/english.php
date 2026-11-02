<?php

/**
 * Perly Pay for WHMCS, in English. One line per string: build.mjs reads
 * this file and lang/ukrainian.php and fails when either misses a string
 * the module uses or their placeholders differ.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'config.api_key' => 'Secret key',
    'config.api_key_help' => 'Perly Pay → Developers → API keys. sk_test_… for test mode, sk_live_… to take real money. Save, then:',
    'config.test' => 'Test connection',
    'config.webhook_secret' => 'Webhook signing secret',
    'config.webhook_help' => 'In Perly Pay → Developers → Webhooks, add this address with the invoice and refund events, then paste its secret (whsec_…) here:',
    'config.page' => 'Payment page',
    'config.page_overlay' => 'Over the invoice (overlay)',
    'config.page_redirect' => 'On pay.perly.io (redirect)',
    'config.page_help' => 'For the overlay, add this WHMCS address in Perly Pay → Settings → "Sites that may embed the checkout". Phones always get the page itself.',
    'config.currencies' => 'Currencies',
    'config.currencies_both' => 'USD and EUR',
    'config.currencies_usd' => 'USD only',
    'config.currencies_eur' => 'EUR only',
    'config.currencies_help' => 'Perly Pay bills in USD or EUR only. Clients in another currency see a note to choose another way to pay, unless you set "Convert To For Processing" below to USD or EUR.',
    'pay.button' => 'Pay with crypto',
    'pay.note' => 'Bitcoin, USDC or USDT from any wallet.',
    'pay.test_mode' => 'Test mode: nothing real moves.',
    'pay.or' => ' or ',
    'pay.currency' => 'Crypto payment takes %s only. Please choose another way to pay.',
    'pay.not_set_up' => 'Crypto payment is not set up yet. Please choose another way to pay.',
    'pay.unavailable' => 'Crypto payment is not available right now. Please try again, or choose another way to pay.',
    'pay.not_payable' => 'This invoice is not waiting for payment.',
    'pay.changed' => 'This invoice has changed. Go back, reload it and try again.',
    'pay.description' => 'Invoice #%1$s at %2$s',
    'pay.back' => 'Back to the invoice',
    'test.no_key' => 'Save a secret key first: sk_test_… or sk_live_….',
    'test.refused' => 'Perly Pay refused this key. Copy it again from Developers → API keys.',
    'test.unreachable' => 'Could not reach Perly Pay. Try again in a minute.',
    'test.failed' => 'Perly Pay: %s',
    'test.live' => 'Connected in live mode.',
    'test.test' => 'Connected in test mode.',
    'test.no_webhook' => 'This WHMCS\'s webhook is not in Perly Pay yet: add the address below in Developers → Webhooks.',
    'test.webhook_off' => 'This WHMCS\'s webhook is switched off in Perly Pay. Switch it on in Developers → Webhooks.',
    'test.webhook_events' => 'The webhook does not send invoice.fulfilled. Add the invoice events to it in Developers → Webhooks.',
    'test.no_secret' => 'The webhook is in Perly Pay, but its signing secret is not saved here.',
    'test.ok' => 'The webhook is set up.',
];
