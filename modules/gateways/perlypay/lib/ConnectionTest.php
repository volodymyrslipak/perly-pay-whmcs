<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\AuthenticationException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\ConnectionException;
use PerlyPay\Whmcs\Vendor\Perly\Pay\Exception\PerlyPayException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * "Test connection" on the settings page: whether the saved key works,
 * its mode, and whether this WHMCS's callback is a webhook in Perly Pay
 * that sends invoice.fulfilled, with its secret saved here. It only
 * reads: nothing in Perly Pay or WHMCS changes.
 */
final class ConnectionTest
{
    public static function run(Settings $settings, Lang $lang): string
    {
        $client = $settings->client(0);
        if ($client === null) {
            return $lang->t('test.no_key');
        }
        try {
            $client->invoices->list(['limit' => 1]);
            $found = null;
            foreach ($client->webhookEndpoints->listAll(['limit' => 100]) as $endpoint) {
                if (is_array($endpoint) && ($endpoint['url'] ?? '') === $settings->callbackUrl()) {
                    $found = $endpoint;
                    break;
                }
            }
        } catch (AuthenticationException) {
            return $lang->t('test.refused');
        } catch (ConnectionException) {
            return $lang->t('test.unreachable');
        } catch (PerlyPayException $error) {
            return $lang->t('test.failed', $error->getMessage());
        }

        $mode = $client->mode === 'live' ? $lang->t('test.live') : $lang->t('test.test');
        if ($found === null) {
            return $mode . ' ' . $lang->t('test.no_webhook');
        }
        $events = is_array($found['enabled_events'] ?? null) ? $found['enabled_events'] : [];
        if (($found['status'] ?? 'enabled') !== 'enabled') {
            return $mode . ' ' . $lang->t('test.webhook_off');
        }
        if (!array_intersect(['*', 'invoice.*', 'invoice.fulfilled'], $events)) {
            return $mode . ' ' . $lang->t('test.webhook_events');
        }
        if (!Keys::isWebhookSecret($settings->webhookSecret())) {
            return $mode . ' ' . $lang->t('test.no_secret');
        }

        return $mode . ' ' . $lang->t('test.ok');
    }
}
