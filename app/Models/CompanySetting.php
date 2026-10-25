<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Support\BasisPoints;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The business (one row). Read with CompanySetting::current().
 */
#[Fillable([
    'name', 'address', 'phone', 'email', 'vat_registered', 'vat_number',
    'vat_rate_bp', 'prices_include_vat', 'document_prefixes',
    'allow_negative_stock', 'managers_edit_catalog', 'adjustment_approval_value',
    'quote_validity_days', 'sales_max_discount_bp', 'cashier_max_discount_bp',
    'require_advance_bp', 'cash_rounding_unit', 'return_days', 'credit_note_expiry_days',
    'require_shift_for_payments', 'blind_close', 'variance_approval_threshold', 'payout_approval_threshold',
    'managers_see_profit', 'report_emails', 'daily_email_time',
    'online_stock_locations', 'web_fulfilment_location_id', 'web_reserve_at', 'woo_sync_prices', 'woo_paid_statuses', 'woo_backorder_text',
    'woo_order_cursor', 'woo_last_push_at', 'woo_last_reconcile_at',
    'workshop_location_id', 'workshop_overhead_bp', 'workshop_lead_days', 'sms_enabled', 'sms_templates',
    'installment_agreement_terms', 'installment_tax_treatment', 'installment_early_settlement_method',
    'installment_charge_rebate_bp', 'installment_ownership_until_paid', 'installment_reminder_stages',
])]
class CompanySetting extends Model
{
    private static ?self $memo = null;

    protected $attributes = [
        'vat_registered' => false,
        'vat_rate_bp' => 1800,
        'prices_include_vat' => true,
        'allow_negative_stock' => false,
        'managers_edit_catalog' => true,
        'adjustment_approval_value' => 5000000,
        'quote_validity_days' => 14,
        'sales_max_discount_bp' => 1000,
        'cashier_max_discount_bp' => 500,
        'require_advance_bp' => 0,
        'cash_rounding_unit' => 0,
    ];

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'vat_rate_bp' => 'integer',
            'prices_include_vat' => 'boolean',
            'document_prefixes' => 'array',
            'allow_negative_stock' => 'boolean',
            'managers_edit_catalog' => 'boolean',
            'adjustment_approval_value' => 'integer',
            'quote_validity_days' => 'integer',
            'sales_max_discount_bp' => 'integer',
            'cashier_max_discount_bp' => 'integer',
            'require_advance_bp' => 'integer',
            'cash_rounding_unit' => 'integer',
            'return_days' => 'integer',
            'credit_note_expiry_days' => 'integer',
            'require_shift_for_payments' => 'boolean',
            'blind_close' => 'boolean',
            'variance_approval_threshold' => 'integer',
            'payout_approval_threshold' => 'integer',
            'managers_see_profit' => 'boolean',
            'report_emails' => 'array',
            'online_stock_locations' => 'array',
            'web_fulfilment_location_id' => 'integer',
            'woo_sync_prices' => 'boolean',
            'woo_paid_statuses' => 'array',
            'woo_order_cursor' => 'datetime',
            'woo_last_push_at' => 'datetime',
            'woo_last_reconcile_at' => 'datetime',
            'workshop_location_id' => 'integer',
            'workshop_overhead_bp' => 'integer',
            'workshop_lead_days' => 'integer',
            'sms_enabled' => 'boolean',
            'sms_templates' => 'array',
            'installment_charge_rebate_bp' => 'integer',
            'installment_ownership_until_paid' => 'boolean',
            'installment_reminder_stages' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => self::$memo = $setting);
    }

    public static function current(): self
    {
        return self::$memo ??= static::query()->oldest('id')->first()
            ?? static::query()->create(['name' => config('app.name'), 'document_prefixes' => DocumentType::defaultPrefixes()])->refresh(); // load the column defaults
    }

    public static function forget(): void
    {
        self::$memo = null;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function vatRatePercent(): string
    {
        return BasisPoints::toPercent($this->vat_rate_bp);
    }

    /** @return array<int, int> location ids whose stock is sold online */
    public function onlineLocationIds(): array
    {
        return array_values(array_map('intval', $this->online_stock_locations ?? []));
    }

    /** @return array<int, string> WooCommerce order statuses that mean "paid" */
    public function wooPaidStatuses(): array
    {
        return $this->woo_paid_statuses ?: ['processing', 'completed'];
    }

    /** "INV" for invoices; falls back to the default prefix. */
    /** Customer SMS templates (Step 11), with the defaults for any not edited yet. */
    public const SMS_TEMPLATES = [
        'custom_confirmed' => 'Hi {customer}, your custom order {order} ({item}) is confirmed. We expect it to be ready by {due}. - {company}',
        'custom_in_production' => 'Hi {customer}, good news: your {item} (order {order}) is now being made. - {company}',
        'custom_ready' => 'Hi {customer}, your {item} (order {order}) is ready. We will call you to arrange {fulfilment}. - {company} {phone}',
        'delivery_scheduled' => 'Hi {customer}, your delivery {delivery} is scheduled for {date} ({window}). - {company}',
        'delivery_out_for_delivery' => 'Hi {customer}, your order {order} is out for delivery today. - {company}',
        'delivery_delivered' => 'Hi {customer}, your order {order} has been delivered. Thank you. - {company}',
        'installment_due' => 'Hi {customer}, installment {agreement} of {amount} is due on {date}. - {company}',
        'installment_overdue' => 'Hi {customer}, installment {agreement} of {amount} is overdue. Please contact {phone}. - {company}',
    ];

    public function smsTemplate(string $key): string
    {
        $custom = trim((string) (($this->sms_templates ?? [])[$key] ?? ''));

        return $custom !== '' ? $custom : self::SMS_TEMPLATES[$key];
    }

    /** @return array<string, string> */
    public function smsTemplates(): array
    {
        return collect(array_keys(self::SMS_TEMPLATES))->mapWithKeys(fn (string $k) => [$k => $this->smsTemplate($k)])->all();
    }

    public function prefixFor(DocumentType $type): string
    {
        return ($this->document_prefixes ?? [])[$type->value] ?? $type->defaultPrefix();
    }

    /**
     * @return array<string, string>
     */
    public function prefixes(): array
    {
        return collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $type) => [$type->value => $this->prefixFor($type)])->all();
    }
}
