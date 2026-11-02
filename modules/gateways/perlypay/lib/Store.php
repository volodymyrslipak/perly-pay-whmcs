<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Which Perly Pay invoice was made for which WHMCS invoice, and what came
 * of it. One row per Perly Pay invoice:
 *
 *   pay_invoice     inv_…, unique
 *   whmcs_invoice   the WHMCS invoice id
 *   mode            test | live (the key's)
 *   amount          what Perly Pay bills, minor units, in `currency` (USD|EUR)
 *   whmcs_amount    the WHMCS balance it stands for, "24.50", in `whmcs_currency` (the client's;
 *                   the same as `currency` unless WHMCS converts for processing)
 *   url             the hosted invoice
 *   status          open | closed | paid
 *   whmcs_transid   the transaction recorded in WHMCS once paid
 *   claim, claimed_at   who is recording the payment right now (one webhook at a time)
 *   events          the last event ids applied (a replay changes nothing)
 *   refunds         the refunds opened from WHMCS
 *
 * @phpstan-type Row array{pay_invoice: string, whmcs_invoice: int, mode: string, amount: int, currency: string, whmcs_amount: string, whmcs_currency: string, url: string, status: string, whmcs_transid: ?string, claim: ?string, claimed_at: ?int, events: list<string>, refunds: list<string>}
 */
interface Store
{
    /** Seconds after which a claim that never finished may be taken over. */
    public const CLAIM_SECONDS = 600;

    /** @return array<string, mixed>|null the newest row for this WHMCS invoice in this mode */
    public function latestFor(int $whmcsInvoice, string $mode): ?array;

    /** How many Perly Pay invoices were made for this WHMCS invoice, in any mode. */
    public function count(int $whmcsInvoice): int;

    /** @param array<string, mixed> $row */
    public function add(array $row): void;

    /** @return array<string, mixed>|null */
    public function byPayInvoice(string $payInvoice): ?array;

    /** @return array<string, mixed>|null */
    public function byTransaction(string $transid): ?array;

    public function setStatus(string $payInvoice, string $status): void;

    /**
     * Takes the row for recording its payment: true only for the one caller
     * that finds it unpaid and unclaimed (or claimed longer ago than CLAIM_SECONDS).
     */
    public function claim(string $payInvoice, string $token, int $now): bool;

    public function release(string $payInvoice, string $token): void;

    public function markPaid(string $payInvoice, string $transid): void;

    public function hasEvent(string $payInvoice, string $eventId): bool;

    public function rememberEvent(string $payInvoice, string $eventId): void;

    public function addRefund(string $payInvoice, string $refundId): void;
}
