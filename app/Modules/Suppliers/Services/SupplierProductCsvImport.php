<?php

namespace App\Modules\Suppliers\Services;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use App\Support\Countries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierProductCsvImport
{
    public const MAX_ROWS = 1000;

    public const MAX_SIZE_KB = 2048;

    public const REQUIRED_COLUMNS = ['name', 'unit_price', 'min_quantity_unit', 'currency'];

    public const COLUMNS = ['name', 'unit_price', 'min_quantity_value', 'min_quantity_unit', 'currency', 'category', 'country_of_origin', 'variety', 'caliber', 'quality', 'description', 'quantity_available', 'valid_until', 'is_bio', 'status', 'packaging_method', 'package_size'];

    /** @return array<int, array<string, mixed>> */
    public function validateFile(UploadedFile $file): array
    {
        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'extensions:csv', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/x-csv', 'max:'.self::MAX_SIZE_KB],
        ])->validate();

        $contents = $file->get();

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('The CSV must use UTF-8 encoding.')]);
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $firstLine = strtok($contents, "\r\n") ?: '';
            $delimiter = count(str_getcsv($firstLine, ';', '"', '')) > count(str_getcsv($firstLine, ',', '"', '')) ? ';' : ',';
            $headers = fgetcsv($stream, separator: $delimiter, escape: '');
            $headers = $headers === false ? [] : array_map(fn (?string $header): string => trim($header ?? ''), $headers);

            if (array_diff(self::REQUIRED_COLUMNS, $headers) !== [] || array_diff($headers, self::COLUMNS) !== [] || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => __('Invalid CSV columns. Use the downloadable template. Required columns: :columns.', ['columns' => implode(', ', self::REQUIRED_COLUMNS)])]);
            }

            $packagingMethods = PackagingMethod::query()->active()->pluck('id', 'name')->all();
            $units = Unit::query()->withoutGlobalScopes()->pluck('symbol')->push('kg')->unique()->all();
            $rules = $this->rowRules($units, array_keys($packagingMethods));
            $rows = [];
            $errors = [];
            $recordNumber = 1;
            $productRowCount = 0;

            while (($values = fgetcsv($stream, separator: $delimiter, escape: '')) !== false) {
                $recordNumber++;

                if (count(array_filter($values, fn (?string $value): bool => filled(trim($value ?? '')))) === 0) {
                    continue;
                }

                $productRowCount++;

                if ($productRowCount > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => __('The CSV may contain at most :count product rows.', ['count' => self::MAX_ROWS])]);
                }

                if (count($values) !== count($headers)) {
                    $errors[] = __('CSV row :row: the number of values does not match the header.', ['row' => $recordNumber]);

                    continue;
                }

                $row = $this->normalizeRow(array_combine($headers, $values));
                $validator = Validator::make($row, $rules);

                if ($validator->fails()) {
                    foreach ($validator->errors()->all() as $message) {
                        $errors[] = __('CSV row :row: :message', ['row' => $recordNumber, 'message' => $message]);
                    }
                } else {
                    $row = $validator->validated();
                    $row['packaging_method_id'] = $packagingMethods[$row['packaging_method'] ?? ''] ?? null;
                    unset($row['packaging_method']);
                    $rows[] = $row;
                }

                if (count($errors) >= 100) {
                    break;
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => $errors]);
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['file' => __('The CSV contains no product rows.')]);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function validationToken(UploadedFile $file, int $supplierId): string
    {
        return hash_hmac('sha256', auth()->id().'|'.$supplierId.'|'.hash('sha256', $file->get()), config('app.key'));
    }

    public function import(UploadedFile $file, int $supplierId, string $validationToken): int
    {
        Gate::authorize('create', SupplierProduct::class);
        $supplier = Supplier::query()->findOrFail($supplierId);

        if (! hash_equals($this->validationToken($file, $supplierId), $validationToken)) {
            throw ValidationException::withMessages(['file' => __('Validate the current CSV and supplier before importing.')]);
        }

        $rows = $this->validateFile($file);

        return DB::transaction(function () use ($rows, $supplier): int {
            foreach ($rows as $row) {
                $supplier->supplierProducts()->create($row);
            }

            return count($rows);
        });
    }

    public function downloadTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, self::COLUMNS, ';', '"', '');
            fputcsv($stream, ['Roșii', '4.50', '10', 'kg', 'EUR', '', 'RO', '', '', '', '', '100', '', '0', 'active', '', ''], ';', '"', '');
            fclose($stream);
        }, 'supplier-products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $row = array_map(fn (?string $value): ?string => filled(trim($value ?? '')) ? trim($value) : null, $row);
        $row['min_quantity_value'] ??= null;
        $row['status'] ??= 'active';
        $row['is_bio'] ??= '0';
        $row['currency'] = strtoupper($row['currency'] ?? '');

        if (isset($row['country_of_origin'])) {
            $row['country_of_origin'] = strtoupper($row['country_of_origin']);
        }

        foreach (['unit_price', 'min_quantity_value', 'quantity_available', 'package_size'] as $column) {
            if (isset($row[$column]) && preg_match('/^\d+,\d+$/', $row[$column])) {
                $row[$column] = str_replace(',', '.', $row[$column]);
            }
        }

        return $row;
    }

    /**
     * @param  array<int, string>  $units
     * @param  array<int, string>  $packagingMethods
     * @return array<string, array<int, mixed>>
     */
    private function rowRules(array $units, array $packagingMethods): array
    {
        $decimal = ['numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', ...$decimal],
            'min_quantity_value' => ['nullable', ...$decimal],
            'min_quantity_unit' => ['required', 'string', 'max:16', Rule::in($units)],
            'currency' => ['required', Rule::in(['EUR', 'RON', 'USD', 'GBP'])],
            'status' => ['required', Rule::in(['active', 'archived'])],
            'is_bio' => ['required', 'boolean'],
            'category' => ['nullable', 'string', 'max:255'],
            'country_of_origin' => ['nullable', Rule::in(array_keys(Countries::LIST))],
            'variety' => ['nullable', 'string', 'max:255'],
            'caliber' => ['nullable', 'string', 'max:255'],
            'quality' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'quantity_available' => ['nullable', ...$decimal],
            'package_size' => ['nullable', ...$decimal],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'packaging_method' => ['nullable', Rule::in($packagingMethods)],
        ];
    }
}
