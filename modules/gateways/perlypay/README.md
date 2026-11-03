# Perly Pay for WHMCS

Take bitcoin, USDC and USDT on your WHMCS invoices, paid straight to your
own wallet. Perly Pay never holds the money and takes nothing from a payment:
it costs 2.5¢ a payment, never more than 0.5% of it, billed after the month,
and a month of up to 21 payments costs $0.

WHMCS 8.x, PHP 8.1+ with `curl` and `json`. Nothing to install with
Composer: Perly Pay's PHP SDK is inside, under its own namespace.

## Install

1. Unzip `perly-pay-whmcs.zip` over your WHMCS folder. It adds
   `modules/gateways/perlypay.php`, `modules/gateways/callback/perlypay.php`
   and `modules/gateways/perlypay/`.
2. In WHMCS: **Configuration → Apps & Integrations → Payment Gateways**
   (or **Setup → Payment Gateways**), activate **Perly Pay**.

## Configure

| Setting | What to put |
| --- | --- |
| Secret key | Perly Pay → Developers → API keys. `sk_test_…` for test mode, `sk_live_…` for real money. Save, then press **Test connection**. |
| Webhook signing secret | In Perly Pay → Developers → Webhooks, add `https://your-whmcs/modules/gateways/callback/perlypay.php` (the settings show the exact address) with the invoice and refund events, and paste its `whsec_…` secret. |
| Payment page | **Overlay** opens the checkout over the invoice (add your WHMCS address in Perly Pay → Settings → "Sites that may embed the checkout"); **Redirect** sends the client to it. |
| Currencies | USD and EUR, or one of them. Perly Pay bills in USD or EUR only: for clients in another currency set WHMCS's **Convert To For Processing** to USD or EUR, or they see a note to choose another way to pay. |

## How it works

- The invoice shows **Pay with crypto**. Pressing it makes a Perly Pay
  invoice for the balance (or reuses the open one for the same amount) and
  opens it. `_link` itself calls nothing: WHMCS also runs it for invoice
  emails.
- Perly Pay's signed webhook `invoice.fulfilled` (paid in full and the
  sender screened) records the payment with `addInvoicePayment`, for the
  balance the Perly Pay invoice was made for, in the invoice's own
  currency. The transaction id is the Perly Pay payment (`pay_…`); the
  Gateway Log names the network, the coin amount and the transaction link.
- Part payments, expiry, review holds and refunds are written to the
  Gateway Log, without recording a payment. A refund's amount, address and
  transaction are the ones Perly Pay's API returns for that refund.
- A replayed or doubled webhook records nothing twice: the module claims
  the payment before recording it, and WHMCS's `checkCbTransID` stands
  behind that.
- A refund from WHMCS opens a refund in Perly Pay in the coin that was
  paid (the same share of it for a partial refund). Perly Pay emails the
  client for an address; you send from your wallet and paste the
  transaction id in Perly Pay. WHMCS shows the refund as done once it is
  opened; the Gateway Log says what is left to do.

The module keeps one table, `mod_perlypay_invoices` (which Perly Pay
invoice belongs to which WHMCS invoice), made on first use.

## Test

With a `sk_test_…` key, open an unpaid invoice in the client area and press
**Pay with crypto**. Pay it with test coins, or from the API (the invoice
id `inv_…` is on the checkout page):

```bash
curl -X POST https://pay.perly.io/api/v1/invoices/inv_…/simulate \
  -H "Authorization: Bearer $PERLY_PAY_SECRET_KEY" -H "Content-Type: application/json" -d '{}'
```

Within seconds the WHMCS invoice is **Paid**.

## Go live

Turn on live mode in Perly Pay and connect your wallet's public keys, paste
a `sk_live_…` key, add the callback address again as a **live** webhook and
paste its secret, then pay one small real invoice and refund it.

## Security

- The callback acts only on a valid `Perly-Signature` (HMAC-SHA256 of the
  timestamp and the raw body, at most five minutes old, compared in constant
  time), and only on Perly Pay invoices this WHMCS made.
- A signed event is not taken at its word: its invoice is retrieved from
  Perly Pay with the module's key and only that answer is used. Refund
  details are also confirmed with Perly Pay's API, never taken from the
  webhook: the Gateway Log says to send what the refund Perly Pay returns
  holds, to its address.
- The pay button's form is signed with your secret key, so a visitor cannot
  change the amount or the invoice it pays.
- Keys, secrets, signatures and clients' emails never go to the Gateway Log.
- Everything the module prints is escaped; every PHP file refuses to run
  outside WHMCS except the two entry points, which load WHMCS's `init.php`.

## WHMCS documentation used

- Third Party Gateway (`_link`, its parameters): https://developers.whmcs.com/payment-gateways/third-party-gateway/
- Metadata Parameters (`_MetaData`): https://developers.whmcs.com/payment-gateways/meta-data-params/
- Configuration (`_config` field types): https://developers.whmcs.com/payment-gateways/configuration/
- Callback Files (`getGatewayVariables`, `checkCbInvoiceID`, `checkCbTransID`, `logTransaction`, `addInvoicePayment`): https://developers.whmcs.com/payment-gateways/callbacks/
- Refunds (`_refund` parameters and return values): https://developers.whmcs.com/payment-gateways/refunds/
- Installation & Activation: https://developers.whmcs.com/payment-gateways/installation-activation/
- Local API GetInvoice: https://developers.whmcs.com/api-reference/getinvoice/
- Local API GetClientsDetails: https://developers.whmcs.com/api-reference/getclientsdetails/
- WHMCS's sample gateway module: https://github.com/WHMCS/sample-gateway-module

## Licence

MIT. Perly Pay's PHP SDK inside (`vendor/perly-pay-sdk`) is MIT too.

## Changelog

- **1.0.1**: Every payment is confirmed with Perly Pay's API before an order is completed: a signed webhook alone no longer marks an order paid, so a leaked signing secret cannot ship an unpaid order. Refund details are also confirmed with Perly Pay's API, never taken from the webhook, so a leaked signing secret cannot point a refund at someone else's wallet.
- **1.0.0**: First release.
