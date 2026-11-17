<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait NormalizesBooleanFilters
{
    /**
     * Query string booleans arrive as "true"/"false"; normalise them so the
     * framework `boolean` rule can validate the value.
     *
     * @param  array<int, string>  $keys
     */
    protected function normalizeBooleanFilters(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if (! is_string($value)) {
                continue;
            }

            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                $request->merge([$key => $normalized]);
            }
        }
    }
}
