<?php

namespace App\Support;

/**
 * MVRK+ (the system's maker): one place for the name, website and WhatsApp support number.
 */
final class Brand
{
    public static function enabled(): bool
    {
        return (bool) config('brand.credit');
    }

    public static function name(): string
    {
        return (string) config('brand.name');
    }

    public static function product(): string
    {
        return (string) config('brand.product');
    }

    public static function website(): string
    {
        return (string) config('brand.website');
    }

    public static function websiteUrl(): string
    {
        return 'https://'.preg_replace('#^https?://#', '', self::website());
    }

    public static function whatsapp(): string
    {
        return (string) config('brand.whatsapp');
    }

    /** https://wa.me/94789818815 */
    public static function whatsappUrl(): string
    {
        return 'https://wa.me/'.preg_replace('/\D/', '', self::whatsapp());
    }

    /** "Powered by MVRK+ · mvrk.lk", or null when the credit is switched off. */
    public static function credit(): ?string
    {
        return self::enabled() ? 'Powered by '.self::name().' · '.self::website() : null;
    }

    /** The same for thermal printers (plain ASCII, so every printer's code page prints it). */
    public static function printCredit(): ?string
    {
        return self::enabled() ? 'Powered by '.self::name().' | '.self::website() : null;
    }

    /** Shared with every page (staff and guest). */
    public static function toArray(): array
    {
        return [
            'enabled' => self::enabled(),
            'name' => self::name(),
            'product' => self::product(),
            'website' => self::website(),
            'website_url' => self::websiteUrl(),
            'whatsapp' => self::whatsapp(),
            'whatsapp_url' => self::whatsappUrl(),
            'credit' => self::credit(),
        ];
    }
}
