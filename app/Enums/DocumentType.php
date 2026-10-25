<?php

namespace App\Enums;

/**
 * Numbered documents. Each has a prefix on the company settings page ("INV" → INV-HO-000123)
 * and its numbers come from App\Support\Counter (type = this value).
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case Quotation = 'quotation';
    case SalesReturn = 'sales_return';
    case Receipt = 'receipt';
    case CreditNote = 'credit_note';
    case Refund = 'refund';
    case PurchaseOrder = 'purchase_order';
    case Grn = 'grn';
    case SupplierReturn = 'supplier_return';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';
    case ProductionJob = 'production_job';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Quotation => 'Quotation',
            self::SalesReturn => 'Sales return',
            self::Receipt => 'Payment receipt',
            self::CreditNote => 'Credit note',
            self::Refund => 'Refund',
            self::PurchaseOrder => 'Purchase order',
            self::Grn => 'Goods received note',
            self::SupplierReturn => 'Supplier return',
            self::Transfer => 'Stock transfer',
            self::Adjustment => 'Stock adjustment',
            self::ProductionJob => 'Production job',
            self::Delivery => 'Delivery note',
        };
    }

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'INV',
            self::Quotation => 'QUO',
            self::SalesReturn => 'SRN',
            self::Receipt => 'RCT',
            self::CreditNote => 'CN',
            self::Refund => 'RFD',
            self::PurchaseOrder => 'PO',
            self::Grn => 'GRN',
            self::SupplierReturn => 'SUR',
            self::Transfer => 'TRF',
            self::Adjustment => 'ADJ',
            self::ProductionJob => 'JOB',
            self::Delivery => 'DLV',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function defaultPrefixes(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->defaultPrefix()])->all();
    }
}
