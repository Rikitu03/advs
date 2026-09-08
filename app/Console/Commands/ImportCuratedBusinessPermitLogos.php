<?php

namespace App\Console\Commands;

use App\Support\IssuerLogoCatalog;
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

    /** @var list<string> */
    private const CITIES = ['Digos', 'Makati', 'Manila', 'Marikina', 'Taguig'];

    public function handle(IssuerLogoCatalog $catalog): int
    {
        $city = trim((string) $this->option('city'));
        $cities = $city === '' ? self::CITIES : array_values(array_filter(
            self::CITIES,
            static fn (string $candidate): bool => strcasecmp($candidate, $city) === 0,
        ));
        if ($cities === []) {
            $this->error('Unknown city. Use Digos, Makati, Manila, Marikina, or Taguig.');

            return self::INVALID;
        }

        $typeId = DB::table('document_types')->where('code', 'business_permit')->value('id');
        if ($typeId === null) {
            $this->error('The business_permit document type is not seeded.');

            return self::FAILURE;
        }

        foreach ($cities as $canonicalCity) {
            $reference = $catalog->referencesFor('business_permit', 'lgu', $canonicalCity)[0] ?? null;
            $path = is_array($reference) ? $catalog->pathFor($reference['key']) : null;
            if (! is_array($reference) || $path === null) {
                $this->warn("{$canonicalCity}: no usable manifest reference");

                continue;
            }

            $this->import($canonicalCity, $reference, $path, (int) $typeId, $catalog->version());
        }

        return self::SUCCESS;
    }

    /** @param  array{key: string, label: string}  $reference */
    private function import(string $city, array $reference, string $absolutePath, int $typeId, ?string $version): void
    {
        $existing = DB::table('logo_references')
            ->where('document_type_id', $typeId)
            ->where('city', $city)
            ->first(['id']);
        if ($existing !== null && ! $this->option('force')) {
            $this->line("{$city}: skipped, reference {$existing->id} already exists");

            return;
        }

        $this->line("{$city}: curated candidate {$reference['key']} ({$reference['label']})");
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

        $storedPath = 'logo_references/business_permit/curated-'.Str::slug($reference['key']).'-'.now()->timestamp.'.png';
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            $this->error("{$city}: unable to read the asset for storage");

            return;
        }
        Storage::disk('local')->put($storedPath, $contents);
        $payload = [
            'document_type_id' => $typeId,
            'city' => $city,
            'label' => "{$city} Business Permit (approved curated asset ".($version ?? 'unversioned').": {$reference['key']})",
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
