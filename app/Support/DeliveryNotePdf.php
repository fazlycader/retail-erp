<?php

namespace App\Support;

use App\Models\SalesOrder;

/**
 * A4 delivery / collection note for a sales order (dompdf, see SalesDocuments).
 */
final class DeliveryNotePdf
{
    public static function render(SalesOrder $order): string
    {
        return SalesDocuments::deliveryNotePdf($order);
    }
}
