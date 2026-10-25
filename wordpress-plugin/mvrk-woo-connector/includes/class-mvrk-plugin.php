<?php

/** Wiring: options, the API client, hooks. */
final class Mvrk_Wc_Plugin
{
    public const OPT_URL = 'mvrk_wc_app_url';

    public const OPT_TOKEN = 'mvrk_wc_token';

    public const OPT_STATUS = 'mvrk_wc_last_status';      // ['ok' => bool, 'message' => string, 'at' => int]

    public const OPT_AUTO = 'mvrk_wc_auto_insert';        // show the block on product pages automatically

    public const OPT_ENQUIRY = 'mvrk_wc_enquiry_enabled';  // "Reserve to view in showroom" form

    public static function boot(): void
    {
        Mvrk_Wc_Settings::register();
        Mvrk_Wc_Availability::register();
    }

    public static function api(): Mvrk_Wc_Api
    {
        return new Mvrk_Wc_Api((string) get_option(self::OPT_URL, ''), (string) get_option(self::OPT_TOKEN, ''));
    }

    public static function remember(bool $ok, string $message): void
    {
        update_option(self::OPT_STATUS, ['ok' => $ok, 'message' => $message, 'at' => time()], false);
    }
}
