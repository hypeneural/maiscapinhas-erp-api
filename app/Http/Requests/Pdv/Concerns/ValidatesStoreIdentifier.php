<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ValidatesStoreIdentifier
{
    /**
     * @return array<int, mixed>
     */
    protected function storeIdRules(): array
    {
        return [
            'nullable',
            'bail',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                $normalized = trim((string) $value);
                if ($normalized === '') {
                    return;
                }

                if (ctype_digit($normalized)) {
                    $exists = DB::table('stores')
                        ->where('id', (int) $normalized)
                        ->exists();

                    if (!$exists) {
                        $fail('A loja informada em store_id nao foi encontrada.');
                    }

                    return;
                }

                if (!Str::isUuid($normalized)) {
                    $fail('O campo store_id deve ser numerico ou UUID valido.');

                    return;
                }

                $exists = DB::table('stores')
                    ->whereRaw('LOWER(guid) = ?', [strtolower($normalized)])
                    ->exists();

                if (!$exists) {
                    $fail('A loja informada em store_id nao foi encontrada.');
                }
            },
        ];
    }
}
