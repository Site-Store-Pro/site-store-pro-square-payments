# Square Payments Plugin for Site Store Pro (Laravel)

A stand-alone, drop-in payment processor plugin for **Site Store Pro** (Laravel 13 + Livewire 3) powered by **Square's latest APIs**:
- **Frontend:** [Square Web Payments SDK (`v1/square.js`)](https://developer.squareup.com/docs/web-payments/overview)
- **Backend:** [Square REST API v2 (`/v2/payments`, `/v2/refunds`, `/v2/locations`)](https://developer.squareup.com/reference/square)

Requires **zero external Composer dependencies** and **zero modifications** to existing Site Store Pro Admin or Checkout files.

---

## What This Plugin Does

1. **Zero-Config Auto-Discovery & Database Registration (`processor.json`)**
   - Automatically discovered by Site Store Pro's `PaymentProcessorManager` when placed in `payment-processors/square/`.
   - Automatically seeds its own processor record (`processor_id = 100`, `processor_name = "Square Payments"`) in the `order_processors` table on application boot.
   - Immediately appears in **Admin → Checkout → Payment Processors** for Primary / Secondary / Tertiary checkout slot selection and one-click **Sandbox / Production** toggling.

2. **PCI-DSS Compliant Inline Card Checkout (`checkout.blade.php`)**
   - Dynamically loads the official Square Web Payments SDK (`https://sandbox.web.squarecdn.com/v1/square.js` in Sandbox mode or `https://web.squarecdn.com/v1/square.js` in Production mode).
   - Mounts an inline card input widget (`#square-card-container`) directly inside the Site Store Pro **Order Review & Payment** page (`OrderReview` Livewire component).
   - Automatically re-initializes the card form when Livewire refreshes checkout totals (e.g., when a customer applies a promo code or changes shipping options).
   - Raw card numbers never touch your server; the browser exchanges card details directly with Square for a single-use payment token (`cnon:...`).

3. **3D Secure (3DS) & Strong Customer Authentication (SCA)**
   - Passes `verificationDetails` (`amount`, `currencyCode`, `intent: 'CHARGE'`, `customerInitiated: true`, and customer `billingContact` details) directly into `card.tokenize(verificationDetails)` to satisfy 3DS/SCA requirements before order submission.

4. **Server-Side Payment Processing (`POST /v2/payments`)**
   - Charges the single-use `source_id` token using Square REST API v2 with a unique UUID v4 `idempotency_key` and `autocomplete: true`.
   - Automatically converts order totals into Square's smallest currency unit (e.g., cents for `USD`, `CAD`, `GBP`, `EUR`, `AUD`, and whole units for zero-decimal currencies such as `JPY` and `KRW`).
   - Records the Square Payment ID (`authorization_code`) and receipt reference (`processor_response`) in `order_payments`.
   - Surfaces human-readable Square error details (e.g., card declines, CVV/postal code mismatches) directly in the checkout UI without creating incomplete orders.

5. **Automatic Location ID Resolution (`GET /v2/locations`)**
   - Uses `SQUARE_LOCATION_ID` (or `SQUARE_SANDBOX_LOCATION_ID`) when configured in `.env`.
   - If omitted, automatically queries Square's `GET /v2/locations` endpoint and selects your primary `ACTIVE` merchant location.

6. **Full & Partial Refunds from Admin Order Details (`POST /v2/refunds`)**
   - Integrates with Site Store Pro's `PaymentRefundService` via `matchesPayment()` and `refund()`.
   - Allows administrators to issue full or partial refunds directly from **Admin → Orders → Order Details** (`/admin/orders/{id}`), automatically updating `order_refunds`, `order_payments`, and `orders` status records.

7. **HMAC-SHA256 Webhook Verification & Synchronization (`POST /webhooks/payment/square`)**
   - Verifies incoming Square webhooks using the `x-square-hmacsha256-signature` header (`Base64(HMAC-SHA256(notificationUrl + rawBody, signatureKey))`).
   - Processes `payment.created`, `payment.updated`, `refund.created`, and `refund.updated` events to keep Site Store Pro order and payment statuses synchronized with the Square Dashboard.

---

## Repository Structure

```text
square_plugin/
├── README.md                                      # Complete documentation & setup guide
├── processor.json                                 # Auto-discovery manifest (processor_id: 100, type: "square")
├── SquareProcessor.php                            # Main driver implementing PaymentPluginInterface
├── checkout.blade.php                             # Inline Square Web Payments SDK view & Alpine JS hooks
└── tests/
    └── Feature/
        └── SquarePaymentProcessorTest.php         # Automated PHPUnit feature tests for checkout, refunds & webhooks
```

---

## Step-by-Step Setup on an Existing Site Store Pro Laravel Install

### Step 1: Install the Plugin into `payment-processors/square`

From the root directory of your Site Store Pro Laravel application, clone this repository directly into `payment-processors/square` (or copy the files manually):

#### Option A — Clone via Git
```bash
git clone https://github.com/<your-org>/square_plugin.git payment-processors/square
```

#### Option B — Manual Copy
Create the folder `payment-processors/square/` in your Site Store Pro project root and copy these files into it:
```text
<site-store-pro-root>/
└── payment-processors/
    └── square/
        ├── processor.json
        ├── SquareProcessor.php
        ├── checkout.blade.php
        └── README.md
```

---

### Step 2: Obtain Your Square API Credentials

1. Sign in to the [Square Developer Dashboard](https://developer.squareup.com/apps) and open (or create) your application.
2. Under **Credentials**:
   - Toggle between **Sandbox** and **Production** at the top of the page.
   - Copy your **Application ID** (`sandbox-sq0idb-...` for Sandbox, `sq0idp-...` for Production).
   - Copy your **Access Token** (`EAAAE...`).
3. Under **Locations** in the left sidebar:
   - Copy your **Location ID** (`L...`) for both Sandbox and Production (optional — the plugin can also auto-detect your primary active location via `/v2/locations`).

---

### Step 3: Add Square Environment Variables to `.env`

Open the `.env` file in the root of your Site Store Pro Laravel installation and add the following block:

```dotenv
# ── Square Payments Plugin ────────────────────────────────────────────────────
SQUARE_API_VERSION=2025-01-23

# Sandbox Credentials (used when Production toggle is OFF in Admin)
SQUARE_SANDBOX_APPLICATION_ID=sandbox-sq0idb-your-sandbox-app-id
SQUARE_SANDBOX_ACCESS_TOKEN=your-sandbox-access-token
SQUARE_SANDBOX_LOCATION_ID=your-sandbox-location-id
SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY=

# Production Credentials (used when Production toggle is ON in Admin)
SQUARE_APPLICATION_ID=sq0idp-your-production-app-id
SQUARE_ACCESS_TOKEN=your-production-access-token
SQUARE_LOCATION_ID=your-production-location-id
SQUARE_WEBHOOK_SIGNATURE_KEY=
```

#### Optional: Add to `config/services.php` (Recommended if using `php artisan config:cache` in Production)
`SquareProcessor` reads from `config('services.square.*')` first and falls back to `env('SQUARE_*')`. If your production deployment caches configuration (`php artisan config:cache`), add this block to `config/services.php`:

```php
    'square' => [
        'api_version'                   => env('SQUARE_API_VERSION', '2025-01-23'),
        'application_id'                => env('SQUARE_APPLICATION_ID'),
        'access_token'                  => env('SQUARE_ACCESS_TOKEN'),
        'location_id'                   => env('SQUARE_LOCATION_ID'),
        'sandbox_application_id'        => env('SQUARE_SANDBOX_APPLICATION_ID'),
        'sandbox_access_token'          => env('SQUARE_SANDBOX_ACCESS_TOKEN'),
        'sandbox_location_id'           => env('SQUARE_SANDBOX_LOCATION_ID'),
        'webhook_signature_key'         => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'sandbox_webhook_signature_key' => env('SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY'),
    ],
```

---

### Step 4: Clear Application Cache & Trigger Auto-Discovery

Run the following Artisan command in your Site Store Pro root directory:

```bash
php artisan optimize:clear
```

On the next request (or Artisan boot), `PluginServiceProvider` automatically discovers `payment-processors/square/processor.json` and inserts `Square Payments` (`processor_id = 100`) into the `order_processors` database table.

---

### Step 5: Enable Square Payments in the Site Store Pro Admin Panel

1. Log in to the Site Store Pro Admin area and navigate to:
   **Admin → Checkout → Processors** (`/admin/checkout/processors`)
2. In the **Active Processors** card at the top:
   - Select **Square Payments (ID: 100)** as your **Primary Processor** (or Secondary / Tertiary if using randomized multi-processor routing).
   - Click **Save Routing**.
3. In the **Payment Processors** table below:
   - Locate **Square Payments**.
   - Keep the **Production** toggle **OFF** (`Amber / Sandbox`) while testing with Sandbox credentials.
   - Switch the **Production** toggle **ON** (`Green / Production`) when ready to accept live payments.

---

### Step 6: Configure Square Webhooks (Optional but Recommended)

Webhooks ensure that refunds or payment updates initiated directly inside the Square Dashboard are automatically reflected in Site Store Pro.

1. In the [Square Developer Dashboard](https://developer.squareup.com/apps), select your application and go to **Webhooks → Subscriptions**.
2. Click **Add subscription** and configure:
   - **Notification URL:**
     ```text
     https://your-store-domain.com/webhooks/payment/square
     ```
   - **API Version:** `2025-01-23` (or your current version)
   - **Events:**
     - `payment.created`
     - `payment.updated`
     - `refund.created`
     - `refund.updated`
3. Save the subscription, copy the generated **Signature Key**, and paste it into your `.env` file:
   - `SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY=...` (for Sandbox webhook subscriptions)
   - `SQUARE_WEBHOOK_SIGNATURE_KEY=...` (for Production webhook subscriptions)

---

### Step 7: Testing Checkout & Refunds in Sandbox Mode

When **Square Payments** is set to **Sandbox** mode in Admin, use Square's official Sandbox test card values at checkout:

| Brand | Test Card Number | Expiration | CVV | Postal Code | Expected Result |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Visa** | `4532 0155 0016 4662` | Any future date | `111` | `12345` | Approved (`COMPLETED`) |
| **Mastercard** | `5409 8865 3399 4563` | Any future date | `111` | `12345` | Approved (`COMPLETED`) |
| **American Express** | `3714 4963 5398 431` | Any future date | `1111` | `12345` | Approved (`COMPLETED`) |
| **Declined Card** | `4000 0000 0000 0069` | Any future date | `111` | `12345` | Declined (`CARD_DECLINED`) |
| **Wrong CVV** | `4532 0155 0016 4662` | Any future date | `999` | `12345` | Declined (`VERIFY_CVV_FAILURE`) |

To test refunds:
1. Complete a Sandbox order using the Visa test card above.
2. Go to **Admin → Orders**, open the order, and click **Refund** next to the Square payment record.
3. Enter a partial or full refund amount and submit — the plugin will call `POST /v2/refunds` on Square Sandbox and record the refund ID.

---

### Step 8: Running the Automated PHPUnit Test Suite (Optional)

If you want to run the included feature tests inside your Site Store Pro installation, copy `tests/Feature/SquarePaymentProcessorTest.php` into your application's `tests/Feature/` directory and run:

```bash
php artisan test tests/Feature/SquarePaymentProcessorTest.php
```
MIT License

Copyright (c) 2026 Viserity LLC.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
