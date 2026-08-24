<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportCuratedBusinessPermitLogos extends Command
{
    protected $signature = 'advs:import-business-permit-logos
        {--city= : Import only one canonical city}
        {--dry-run : Validate and print candidates without writing}
        {--force : Replace an existing city reference}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import explicitly curated business-permit logo assets as issuer references.';

    /** @var array<string, string> */
    private const ASSETS = [
        'Digos' => 'python/logo_and_seals/business_permit/digos/Digos Logo.png',
        'Makati' => 'python/logo_and_seals/business_permit/makati/Makati Logo.png',
        'Manila' => 'python/logo_and_seals/business_permit/maynila/Maynila Logo.png',
        'Marikina' => 'python/logo_and_seals/business_permit/marikina/Marikina Logo.png',
        'Taguig' => 'python/logo_and_seals/business_permit/taguig/Taguig Logo.png',
    ];

    private const CURATED_VERSION = 'v1';

    public function handle(): int
    {
        $city = trim((string) $this->option('city'));
        $assets = $city === '' ? self::ASSETS : array_filter(
            self::ASSETS,
            static fn (string $path, string $key): bool => strcasecmp($key, $city) === 0,
            ARRAY_FILTER_USE_BOTH,
        );
        if ($assets === []) {
            $this->error('Unknown city. Use Digos, Makati, Manila, Marikina, or Taguig.');

            return self::INVALID;
        }

        $typeId = DB::table('document_types')->where('code', 'business_permit')->value('id');
        if ($typeId === null) {
            $this->error('The business_permit document type is not seeded.');

            return self::FAILURE;
        }

        foreach ($assets as $canonicalCity => $relativePath) {
            $this->import($canonicalCity, $relativePath, (int) $typeId);
        }

        return self::SUCCESS;
    }

    private function import(string $city, string $relativePath, int $typeId): void
    {
        $absolutePath = base_path($relativePath);
        if (! is_file($absolutePath)) {
            $this->warn("{$city}: asset missing at {$relativePath}");

            return;
        }

        $existing = DB::table('logo_references')
            ->where('document_type_id', $typeId)
            ->where('city', $city)
            ->first(['id']);
        if ($existing !== null && ! $this->option('force')) {
            $this->line("{$city}: skipped, reference {$existing->id} already exists");

            return;
        }

        $this->line("{$city}: curated candidate {$relativePath}");
        if ($this->option('dry-run')) {
            return;
        }

        try {
            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read the asset.');
            }
            $response = Http::withToken((string) config('advs.ml.token'))
                ->timeout((int) config('advs.ml.timeout', 300))
                ->connectTimeout((int) config('advs.ml.connect_timeout', 10))
                ->attach('file', $contents, basename($absolutePath))
                ->post(rtrim((string) config('advs.ml.base_url'), '/').'/v1/stamp/embed');
        } catch (Throwable $exception) {
            $this->error("{$city}: embedding request failed: {$exception->getMessage()}");

            return;
        }

        $vector = $response->json('vector');
        if ($response->failed() || ! is_array($vector) || $vector === []) {
            $this->error("{$city}: embedding API returned no vector (HTTP {$response->status()})");

            return;
        }

        $storedPath = 'logo_references/business_permit/curated-'.Str::slug($city).'-'.now()->timestamp.'.png';
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            $this->error("{$city}: unable to read the asset for storage");

            return;
        }
        Storage::disk('local')->put($storedPath, $contents);
        $payload = [
            'document_type_id' => $typeId,
            'city' => $city,
            'label' => "{$city} Business Permit (approved curated asset ".self::CURATED_VERSION.": {$relativePath})",
            'feature_vector' => json_encode(array_map(static fn (mixed $value): float => (float) $value, $vector), JSON_THROW_ON_ERROR),
            'reference_image_path' => $storedPath,
            'seeded_from_document_id' => null,
            'enrolled_by' => null,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($existing !== null) {
            DB::table('logo_references')->where('id', $existing->id)->update($payload);
            $this->info("{$city}: replaced reference {$existing->id}");
        } else {
            $id = DB::table('logo_references')->insertGetId($payload);
            $this->info("{$city}: imported reference {$id}");
        }
    }
}
