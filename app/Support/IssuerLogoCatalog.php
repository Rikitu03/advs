<?php

namespace App\Support;

use App\Services\Document\IssuerCity;
use JsonException;

class IssuerLogoCatalog
{
    private const MANIFEST_PATH = 'python/logo_and_seals/manifest.json';

    /** @var array{schema_version: string|null, references: list<array<string, mixed>>}|null */
    private ?array $catalog = null;

    public function version(): ?string
    {
        return $this->catalog()['schema_version'];
    }

    /**
     * @return list<array{key: string, label: string, document_type: string, issuer_scope: string, city: string|null}>
     */
    public function referencesFor(?string $documentType, ?string $issuerScope, ?string $city): array
    {
        if ($documentType === null || $issuerScope === null) {
            return [];
        }

        $canonicalCity = $issuerScope === 'lgu' ? IssuerCity::canonical($city) : null;

        return collect($this->catalog()['references'])
            ->filter(fn (array $reference): bool => $reference['document_type'] === $documentType
                && $reference['issuer_scope'] === $issuerScope
                && ($issuerScope !== 'lgu' || $reference['city'] === $canonicalCity))
            ->map(fn (array $reference): array => [
                'key' => $reference['key'],
                'label' => $reference['label'],
                'document_type' => $reference['document_type'],
                'issuer_scope' => $reference['issuer_scope'],
                'city' => $reference['city'],
            ])
            ->values()
            ->all();
    }

    public function pathFor(string $key): ?string
    {
        $reference = collect($this->catalog()['references'])->firstWhere('key', $key);

        return is_array($reference) ? $reference['absolute_path'] : null;
    }

    /**
     * @return array{schema_version: string|null, references: list<array<string, mixed>>}
     */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $manifestPath = base_path(self::MANIFEST_PATH);
        $root = realpath(dirname($manifestPath));
        if ($root === false || ! is_file($manifestPath)) {
            return $this->catalog = ['schema_version' => null, 'references' => []];
        }

        try {
            $payload = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->catalog = ['schema_version' => null, 'references' => []];
        }

        if (! is_array($payload) || ! is_array($payload['references'] ?? null)) {
            return $this->catalog = ['schema_version' => null, 'references' => []];
        }

        $references = collect($payload['references'])
            ->map(function (mixed $reference) use ($root): ?array {
                if (! is_array($reference)) {
                    return null;
                }

                $key = $reference['key'] ?? null;
                $label = $reference['label'] ?? null;
                $documentType = $reference['document_type'] ?? null;
                $issuerScope = $reference['issuer_scope'] ?? null;
                $relativePath = $reference['path'] ?? null;
                if (! is_string($key) || ! is_string($label) || ! is_string($documentType)
                    || ! in_array($issuerScope, ['national', 'lgu'], true) || ! is_string($relativePath)) {
                    return null;
                }

                $relativePath = str_replace('\\', '/', $relativePath);
                if (str_starts_with($relativePath, '/') || in_array('..', explode('/', $relativePath), true)) {
                    return null;
                }

                $absolutePath = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
                if ($absolutePath === false || ! is_file($absolutePath)
                    || ($absolutePath !== $root && ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR))) {
                    return null;
                }

                $city = $issuerScope === 'lgu'
                    ? IssuerCity::canonical(is_string($reference['city'] ?? null) ? $reference['city'] : null)
                    : null;
                if ($issuerScope === 'lgu' && $city === null) {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $label,
                    'document_type' => strtolower($documentType),
                    'issuer_scope' => $issuerScope,
                    'city' => $city,
                    'absolute_path' => $absolutePath,
                ];
            })
            ->filter()
            ->unique('key')
            ->values()
            ->all();

        return $this->catalog = [
            'schema_version' => is_string($payload['schema_version'] ?? null) ? $payload['schema_version'] : null,
            'references' => $references,
        ];
    }
}
