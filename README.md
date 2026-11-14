# ArionPay for Charitable

Crypto donations (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) for [Charitable](https://wordpress.org/plugins/charitable/) on WordPress, through [ArionPay](https://arionpay.com). The donor pays on the ArionPay payment page and the money goes straight to your own wallet: ArionPay never holds it.

Tested on Charitable 1.8.15.1, WordPress 7.1.4 and PHP 8.3, against a real ArionPay server, with 26 checks: donating, coming back before and after paying, a lost webhook, paying later, custom amounts, payment tolerance, late payments, a wrong amount, double payments and webhook security. One-time donations (recurring donations are not offered with this gateway).

## Install

1. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload `arionpay-for-charitable.zip`. Activate it.
2. Go to **Charitable > Settings > Payment Gateways**, click **Enable Gateway** next to **ArionPay (crypto)**, then open its settings.
3. Fill in the fields below and save.

| Field | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

- **Test donations**: turn on **Test Mode** at the top of **Charitable > Settings > Payment Gateways**. ArionPay invoices are then test invoices and no real money moves. Turn it off for real donations.
- **ArionPay address**: leave it empty.
- Nothing to paste into ArionPay: every invoice tells ArionPay where to send its payment updates (shown on the settings page). In your ArionPay store settings, check that **Old ArionPay (v1) integration compatibility** is off.
- The secrets are never shown again after saving. Leave a secret field empty to keep the saved one. A key that does not look right is not saved.

ArionPay takes USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK and ZAR. With another Charitable currency the donor is asked to choose another payment method.

## How donations change

- The donor picks an amount, chooses **Crypto (Bitcoin, USDT and more)**, clicks **Donate** and goes to the ArionPay payment page. The donation waits as **Pending**.
- When ArionPay confirms the full payment, the donation becomes **Paid** (completed) and Charitable sends its usual receipt emails. The donation log names the ArionPay invoice, the coin and notes such as "paid late"; the invoice id is saved as the donation's transaction id.
- When the donor comes back, the plugin asks ArionPay directly, so a lost webhook is caught there too, and Charitable's donation receipt shows where the payment stands.
- A donor who left the payment page without paying keeps a pending donation (it is not cancelled) and sees a **Pay with crypto** button on the receipt. It opens the same ArionPay invoice, or a new one when it expired.
- "Payment seen", Payment Radar and "expired" updates are written to the donation log without changing the status. A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard: a small difference inside your store's payment tolerance (default 1%) is accepted automatically, anything else you decide. If you accept it, the donation is completed here too.
- A donation is completed only by a signed update for its own invoice, test or live like the donation, with the same amount and currency. Anything else is written to the donation log and never completes it. A second paid invoice for the same donation is flagged as a possible double payment.

## Good to know

- Refunds are made from your own wallet; then mark the donation as refunded in Charitable.
- The plugin sends ArionPay the donation id, amount, currency and the campaign name. It does not send the donor's name, email or address.
- Deleting the plugin removes its own option; the gateway settings stay in Charitable's settings and donation logs stay.

## License

MIT

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
