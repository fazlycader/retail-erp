<?php

namespace App\Services\Integrations;

/**
 * Every integration credential the app knows about: the ONE place a new one is declared.
 *
 * Each entry names the .env variable it replaces (integrations:migrate-from-env copies that variable
 * once), its provider group, whether its value is masked on screen, a short label and the "How do I
 * get this?" guide shown on the Integrations screen. `type` is 'text' (default) or 'boolean'.
 */
final class CredentialRegistry
{
    /** Provider groups, in screen order. */
    public const PROVIDERS = [
        'woocommerce' => ['label' => 'WooCommerce', 'description' => 'The online shop: products, prices and stock go to WooCommerce; web orders come back here.'],
        'sms' => ['label' => 'SMS (Notify.lk)', 'description' => 'Text messages to customers about their custom orders (confirmed, being made, ready). Turn sending on under Workshop → Setup.'],
    ];

    /** @var array<string, array{provider: string, description: string, secret: bool, type?: string, fallback?: string, guide: string}> */
    public const CREDENTIALS = [
        'WOO_STORE_URL' => [
            'provider' => 'woocommerce', 'secret' => false,
            'description' => 'Shop address',
            'guide' => <<<'MD'
The address of the WordPress site with WooCommerce, with `https://` and no trailing slash, e.g. `https://shop.example.lk`.

It must be **https**: the API keys are sent with every request.
MD,
        ],
        'WOO_CONSUMER_KEY' => [
            'provider' => 'woocommerce', 'secret' => true,
            'description' => 'REST API consumer key',
            'guide' => <<<'MD'
1. In WordPress: **WooCommerce → Settings → Advanced → REST API → Add key**.
2. Description: e.g. `ERP sync`. User: an administrator (or shop manager) account. **Permissions: Read/Write**.
3. **Generate API key**. Copy the **Consumer key** (starts `ck_`). WooCommerce shows it only once.

If it is ever exposed, revoke the key in WooCommerce and make a new one.
MD,
        ],
        'WOO_CONSUMER_SECRET' => [
            'provider' => 'woocommerce', 'secret' => true,
            'description' => 'REST API consumer secret',
            'guide' => <<<'MD'
Shown on the same screen as the consumer key, right after **Generate API key**: the **Consumer secret** (starts `cs_`). Copy it before leaving the page; WooCommerce never shows it again (you'd have to make a new key).
MD,
        ],
        'WOO_WEBHOOK_SECRET' => [
            'provider' => 'woocommerce', 'secret' => true,
            'description' => 'Webhook secret (a password you choose)',
            'guide' => <<<'MD'
You **make this up**; WooCommerce doesn't issue it.

1. Generate a long random string, e.g. `openssl rand -hex 24`, and paste it here.
2. In WordPress: **WooCommerce → Settings → Advanced → Webhooks → Add webhook**, twice:
   - Topic **Order created**, and topic **Order updated**.
   - Status **Active**, Delivery URL `https://<this app>/api/woo/webhook`, **Secret**: the same string, API version **WP REST API Integration v3**.

Every delivery is signed with it; a wrong value makes every webhook fail with "invalid signature" (shown on the Online store page).
MD,
        ],
        'NOTIFYLK_USER_ID' => [
            'provider' => 'sms', 'secret' => false,
            'description' => 'Notify.lk user ID',
            'guide' => <<<'MD'
1. Sign in at **app.notify.lk** (create an account and top up credit if needed).
2. Open **Settings → API Keys**. Copy the **User ID** (a number).
MD,
        ],
        'NOTIFYLK_API_KEY' => [
            'provider' => 'sms', 'secret' => true,
            'description' => 'Notify.lk API key',
            'guide' => <<<'MD'
On the same **Settings → API Keys** page of app.notify.lk: copy the **API Key**. Anyone with it can send SMS on your credit: if it leaks, generate a new one there and paste it here.
MD,
        ],
        'NOTIFYLK_SENDER_ID' => [
            'provider' => 'sms', 'secret' => false,
            'description' => 'Sender ID (name shown on the SMS)',
            'guide' => <<<'MD'
The approved sender name, e.g. `MyShop`. Until Notify.lk approves your own, use their demo sender `NotifyDEMO` (messages then only go to your own registered number).
MD,
        ],
    ];

    public static function definition(string $key): ?array
    {
        return self::CREDENTIALS[$key] ?? null;
    }

    public static function isBoolean(string $key): bool
    {
        return (self::CREDENTIALS[$key]['type'] ?? 'text') === 'boolean';
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CREDENTIALS);
    }
}
