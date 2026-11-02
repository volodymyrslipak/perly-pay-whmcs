<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The WHMCS functions the module calls, behind one interface so its rules
 * can be tested without WHMCS. WhmcsFunctions is the real one.
 */
interface Whmcs
{
    /**
     * An invoice (localAPI GetInvoice), or null.
     *
     * @return array{id: int, number: string, userid: int, status: string, balance: ?string}|null
     */
    public function invoice(int $id): ?array;

    /**
     * A client (localAPI GetClientsDetails), or null.
     *
     * @return array{email: string, currency: string, language: string}|null
     */
    public function client(int $id): ?array;

    /** checkCbInvoiceID(): the normalised id; WHMCS halts the request for an unknown invoice. */
    public function checkInvoice(int $id, string $gatewayName): int;

    /** checkCbTransID(): WHMCS halts the request when this transaction is already recorded. */
    public function checkTransaction(string $transid): void;

    /** addInvoicePayment(), the amount in the invoice's own currency ("24.50"). */
    public function addPayment(int $invoiceId, string $transid, string $amount, string $module): bool;

    /**
     * logTransaction(): a line in Billing → Gateway Log.
     *
     * @param array<string, mixed> $data
     */
    public function log(string $gatewayName, array $data, string $result): void;
}
