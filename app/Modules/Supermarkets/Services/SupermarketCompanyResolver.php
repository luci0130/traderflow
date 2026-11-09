<?php

namespace App\Modules\Supermarkets\Services;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Illuminate\Validation\ValidationException;

class SupermarketCompanyResolver
{
    /** @return array{supplier_lead_id: ?int, supplier_id: ?int} */
    public function resolve(?int $leadId, ?int $supplierId): array
    {
        if ($leadId !== null) {
            $lead = SupplierLead::query()->findOrFail($leadId);

            if ($supplierId !== null && $supplierId !== $lead->converted_supplier_id) {
                throw ValidationException::withMessages([
                    'company' => __('A price can only belong to one company.'),
                ]);
            }

            $supplierId = $lead->converted_supplier_id;
        } elseif ($supplierId !== null) {
            Supplier::query()->findOrFail($supplierId);
            $leadId = SupplierLead::query()->where('converted_supplier_id', $supplierId)->value('id');
        }

        return ['supplier_lead_id' => $leadId, 'supplier_id' => $supplierId];
    }
}
