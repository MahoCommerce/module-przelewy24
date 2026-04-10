# Maho Przelewy24

![License](https://img.shields.io/badge/license-OSL--3.0-blue)
![PHP](https://img.shields.io/badge/php-%3E%3D8.3-8892BF)
![Maho Commerce](https://img.shields.io/badge/Maho_Commerce-module-orange)

**Przelewy24** payment gateway integration for [Maho Commerce](https://mahocommerce.com).

Accept payments through [Przelewy24](https://www.przelewy24.pl), one of Poland's most popular payment gateways.

> **Note:** This module currently supports the **gateway redirect** flow only — the customer is redirected to Przelewy24's hosted payment page to choose their payment method. Direct card payments, BLIK, Google Pay, and Apple Pay are planned for future releases.

## Features

- **Gateway redirect** — Customer selects their preferred payment method on Przelewy24's hosted payment page
- **Full order lifecycle** — Capture and refund (partial and full) from the Maho admin
- **Webhook support** — Real-time payment and refund notifications with SHA384 signature verification
- **Cron safety net** — Automatic polling of pending payments every 5 minutes in case webhooks fail
- **Sandbox mode** — Test integration with Przelewy24's sandbox environment
- **Country restrictions** — Allow payments from all or specific countries

## Requirements

- PHP >= 8.3
- Maho Commerce
- A [Przelewy24](https://www.przelewy24.pl) merchant account

## Installation

```bash
composer require mahocommerce/module-przelewy24
```

Clear the cache after installation:

```bash
php maho cache:flush
```

## Configuration

Navigate to **System > Configuration > Payment Methods** in the Maho admin panel.

### General Settings (Przelewy24 - General Settings)

| Setting | Description | Default |
|---|---|---|
| **Sandbox Mode** | Use Przelewy24 sandbox for testing | Yes |
| **Merchant ID** | Your Przelewy24 merchant account ID | — |
| **POS ID** | Point of Sale ID (usually same as Merchant ID) | — |
| **CRC Key** | Used for request signing and webhook verification | — |
| **API Key** | Used for HTTP Basic Authentication with the API | — |

### Payment Method (Przelewy24 - Gateway Payment)

| Setting | Description | Default |
|---|---|---|
| **Enabled** | Activate the payment method | No |
| **Title** | Payment method name shown at checkout | Przelewy24 |
| **Applicable Countries** | All countries or specific countries only | All |
| **Sort Order** | Display position among payment methods | 100 |

## How It Works

1. **Order placement** — Customer selects Przelewy24 at checkout, order is created in `pending_payment` state
2. **Registration** — A transaction is registered with Przelewy24's API, returning a payment token
3. **Redirect** — Customer is redirected to Przelewy24's hosted payment page to complete payment
4. **Webhook** — Przelewy24 sends a signed notification to your store confirming payment
5. **Verification** — The module verifies the transaction with Przelewy24's API and captures the payment
6. **Invoice** — An invoice is automatically created and the order moves to `processing`

If the webhook fails to arrive, a cron job polls Przelewy24 every 5 minutes for pending orders (up to 24 hours old).

## Webhooks

Configure the following webhook URL in your Przelewy24 merchant panel:

```
https://your-store.com/przelewy24/webhook/transaction
```

For refund notifications:

```
https://your-store.com/przelewy24/webhook/refund
```

## Supported Currencies

All currencies supported by your Przelewy24 merchant account. Amounts are converted to grosze (1/100) for API communication.

## Roadmap

- [ ] BLIK (inline 6-digit code entry at checkout)
- [ ] Direct card payments (Przelewy24 iframe)
- [ ] Google Pay
- [ ] Apple Pay

## License

This module is licensed under the [Open Software License v3.0](LICENSE.txt).

## Links

- [Maho Commerce](https://mahocommerce.com)
- [Przelewy24](https://www.przelewy24.pl)
- [Przelewy24 API Documentation](https://developers.przelewy24.pl)
