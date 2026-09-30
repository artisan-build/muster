<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

trait ValidatesClosedInput
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validateClosed(Request $request, array $rules): array
    {
        $allowed = array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
            array_keys($rules),
        )));

        foreach (array_keys($request->all()) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'arguments' => ['Unknown top-level argument.'],
                ]);
            }
        }

        return $request->validate($rules);
    }

    private function invalidCursor(): never
    {
        throw ValidationException::withMessages([
            'cursor' => ['The cursor is invalid.'],
        ]);
    }
}
