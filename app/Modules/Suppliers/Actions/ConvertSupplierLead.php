<?php

namespace App\Modules\Suppliers\Actions;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConvertSupplierLead
{
    public function handle(SupplierLead $lead): Supplier
    {
        Gate::authorize('update', $lead);
        Gate::authorize('create', Supplier::class);

        return DB::transaction(function () use ($lead): Supplier {
            $lead = SupplierLead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($lead->isConverted()) {
                return $lead->convertedSupplier;
            }

            $supplier = Supplier::create([
                'tenant_id' => null,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'country' => $lead->country,
                'address' => $lead->address,
                'notes' => $lead->notes,
                'status' => 'active',
            ]);

            $lead->update([
                'converted_supplier_id' => $supplier->getKey(),
                'converted_at' => now(),
            ]);

            return $supplier;
        });
    }
}
