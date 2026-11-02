<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The Store in WHMCS's own database, through Capsule (WHMCS's Laravel
 * query builder): the table mod_perlypay_invoices, made the first time
 * it is needed. Only portable builder calls, so it runs on every WHMCS 8
 * database (MySQL or MariaDB).
 */
final class CapsuleStore implements Store
{
    public const TABLE = 'mod_perlypay_invoices';

    private static bool $ready = false;

    public function __construct()
    {
        self::ensureTable();
    }

    public static function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, static function ($table): void {
                $table->increments('id');
                $table->string('pay_invoice', 64)->unique();
                $table->unsignedInteger('whmcs_invoice')->index();
                $table->string('mode', 4);
                $table->unsignedBigInteger('amount');
                $table->string('currency', 3);
                $table->string('whmcs_amount', 20);
                $table->string('whmcs_currency', 3);
                $table->text('url');
                $table->string('status', 10);
                $table->string('whmcs_transid', 64)->nullable()->index();
                $table->string('claim', 40)->nullable();
                $table->unsignedInteger('claimed_at')->nullable();
                $table->text('events')->nullable();
                $table->text('refunds')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
        self::$ready = true;
    }

    public function latestFor(int $whmcsInvoice, string $mode): ?array
    {
        return self::row(Capsule::table(self::TABLE)->where('whmcs_invoice', $whmcsInvoice)->where('mode', $mode)->orderBy('id', 'desc')->first());
    }

    public function count(int $whmcsInvoice): int
    {
        return (int) Capsule::table(self::TABLE)->where('whmcs_invoice', $whmcsInvoice)->count();
    }

    public function add(array $row): void
    {
        $now = time();
        Capsule::table(self::TABLE)->insert([
            'pay_invoice' => (string) $row['pay_invoice'],
            'whmcs_invoice' => (int) $row['whmcs_invoice'],
            'mode' => (string) $row['mode'],
            'amount' => (int) $row['amount'],
            'currency' => (string) $row['currency'],
            'whmcs_amount' => (string) $row['whmcs_amount'],
            'whmcs_currency' => (string) $row['whmcs_currency'],
            'url' => (string) $row['url'],
            'status' => 'open',
            'events' => '[]',
            'refunds' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function byPayInvoice(string $payInvoice): ?array
    {
        return self::row(Capsule::table(self::TABLE)->where('pay_invoice', $payInvoice)->first());
    }

    public function byTransaction(string $transid): ?array
    {
        return $transid === '' ? null : self::row(Capsule::table(self::TABLE)->where('whmcs_transid', $transid)->first());
    }

    public function setStatus(string $payInvoice, string $status): void
    {
        Capsule::table(self::TABLE)->where('pay_invoice', $payInvoice)->where('status', '!=', 'paid')->update(['status' => $status, 'updated_at' => time()]);
    }

    public function claim(string $payInvoice, string $token, int $now): bool
    {
        $taken = Capsule::table(self::TABLE)
            ->where('pay_invoice', $payInvoice)
            ->whereNull('whmcs_transid')
            ->where(static function ($q) use ($now): void {
                $q->whereNull('claim')->orWhere('claimed_at', '<', $now - self::CLAIM_SECONDS);
            })
            ->update(['claim' => $token, 'claimed_at' => $now, 'updated_at' => $now]);

        return $taken === 1;
    }

    public function release(string $payInvoice, string $token): void
    {
        Capsule::table(self::TABLE)->where('pay_invoice', $payInvoice)->where('claim', $token)->update(['claim' => null, 'claimed_at' => null, 'updated_at' => time()]);
    }

    public function markPaid(string $payInvoice, string $transid): void
    {
        Capsule::table(self::TABLE)->where('pay_invoice', $payInvoice)->update(['status' => 'paid', 'whmcs_transid' => $transid, 'claim' => null, 'claimed_at' => null, 'updated_at' => time()]);
    }

    public function hasEvent(string $payInvoice, string $eventId): bool
    {
        $row = $this->byPayInvoice($payInvoice);

        return $row !== null && in_array($eventId, $row['events'], true);
    }

    public function rememberEvent(string $payInvoice, string $eventId): void
    {
        $row = $this->byPayInvoice($payInvoice);
        if ($row === null || in_array($eventId, $row['events'], true)) {
            return;
        }
        $events = array_slice([...$row['events'], $eventId], -30);
        Capsule::table(self::TABLE)->where('pay_invoice', $payInvoice)->update(['events' => json_encode($events), 'updated_at' => time()]);
    }

    public function addRefund(string $payInvoice, string $refundId): void
    {
        $row = $this->byPayInvoice($payInvoice);
        if ($row === null) {
            return;
        }
        $refunds = array_slice([...$row['refunds'], $refundId], -50);
        Capsule::table(self::TABLE)->where('pay_invoice', $payInvoice)->update(['refunds' => json_encode($refunds), 'updated_at' => time()]);
    }

    /** @return array<string, mixed>|null */
    private static function row(mixed $found): ?array
    {
        if (!is_object($found)) {
            return null;
        }
        $row = (array) $found;
        $list = static function (mixed $json): array {
            $decoded = is_string($json) ? json_decode($json, true) : null;

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        };

        return [
            'pay_invoice' => (string) $row['pay_invoice'],
            'whmcs_invoice' => (int) $row['whmcs_invoice'],
            'mode' => (string) $row['mode'],
            'amount' => (int) $row['amount'],
            'currency' => (string) $row['currency'],
            'whmcs_amount' => (string) $row['whmcs_amount'],
            'whmcs_currency' => (string) $row['whmcs_currency'],
            'url' => (string) $row['url'],
            'status' => (string) $row['status'],
            'whmcs_transid' => isset($row['whmcs_transid']) ? (string) $row['whmcs_transid'] : null,
            'claim' => isset($row['claim']) ? (string) $row['claim'] : null,
            'claimed_at' => isset($row['claimed_at']) ? (int) $row['claimed_at'] : null,
            'events' => $list($row['events'] ?? null),
            'refunds' => $list($row['refunds'] ?? null),
        ];
    }
}
