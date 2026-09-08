<?php

namespace App\Services\Document;

use Illuminate\Support\Str;

final class IssuerCity
{
    /** @var array<string, list<string>> */
    private const ALIASES = [
        'Digos' => ['CITY OF DIGOS', 'DIGOS CITY', 'LUNGSOD NG DIGOS'],
        'Makati' => ['CITY OF MAKATI', 'MAKATI CITY', 'LUNGSOD NG MAKATI'],
        'Manila' => ['CITY OF MANILA', 'MANILA CITY', 'MAYNILA', 'MAYNILA CITY', 'LUNGSOD NG MAYNILA'],
        'Marikina' => ['CITY OF MARIKINA', 'MARIKINA CITY', 'LUNGSOD NG MARIKINA'],
        'Taguig' => ['CITY OF TAGUIG', 'TAGUIG CITY', 'LUNGSOD NG TAGUIG'],
    ];

    public static function canonical(?string $value): ?string
    {
        $normalized = Str::upper(Str::squish((string) $value));
        if ($normalized === '') {
            return null;
        }

        foreach (self::ALIASES as $city => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalized === $alias || Str::contains($normalized, $alias)) {
                    return $city;
                }
            }
        }

        return Str::title($normalized);
    }

    /** @return array<string, list<string>> */
    public static function aliases(): array
    {
        return self::ALIASES;
    }
}
