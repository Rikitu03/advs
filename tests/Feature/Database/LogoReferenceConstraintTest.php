<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the `logo_references` uniqueness model: one reference logo per issuer.
 * National issuers use the '' city sentinel (NOT NULL) so the composite unique
 * index enforces one-per-document-type — a nullable city would not, because
 * MySQL treats NULL as distinct.
 */
class LogoReferenceConstraintTest extends TestCase
{
    use RefreshDatabase;

    /** Insert a logo_references row, returning the inserted id. */
    private function insertLogo(int $documentTypeId, ?string $city = null): int
    {
        $row = [
            'document_type_id' => $documentTypeId,
            'feature_vector' => json_encode([0.1, 0.2, 0.3]),
            'reference_image_path' => 'logos/ref.png',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($city !== null) {
            $row['city'] = $city;
        }

        return DB::table('logo_references')->insertGetId($row);
    }

    private function documentType(string $code, ?string $scope): int
    {
        return DB::table('document_types')->insertGetId([
            'name' => ucfirst($code),
            'code' => $code,
            'is_required' => true,
            'issuer_scope' => $scope,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_city_defaults_to_empty_sentinel_not_null(): void
    {
        $bir = $this->documentType('bir_permit', 'national');

        $this->insertLogo($bir); // no city provided

        $this->assertSame('', DB::table('logo_references')->where('document_type_id', $bir)->value('city'));
    }

    public function test_national_issuer_is_unique_per_document_type(): void
    {
        $bir = $this->documentType('bir_permit', 'national');

        $this->insertLogo($bir, ''); // first BIR logo — ok

        $this->expectException(QueryException::class);
        $this->insertLogo($bir, ''); // second national logo for same type — must fail
    }

    public function test_lgu_issuer_is_unique_per_document_type_and_city(): void
    {
        $permit = $this->documentType('business_permit', 'lgu');

        // Different cities for the same document type are allowed…
        $this->insertLogo($permit, 'Pasig');
        $this->insertLogo($permit, 'Quezon City');
        $this->assertSame(2, DB::table('logo_references')->where('document_type_id', $permit)->count());

        // …but the same (document_type, city) is not.
        $this->expectException(QueryException::class);
        $this->insertLogo($permit, 'Pasig');
    }

    public function test_national_and_city_references_coexist_on_one_document_type(): void
    {
        $type = $this->documentType('mixed', 'national');

        $this->insertLogo($type, '');      // national sentinel
        $this->insertLogo($type, 'Pasig'); // a city row — distinct key, allowed

        $this->assertSame(2, DB::table('logo_references')->where('document_type_id', $type)->count());
    }
}
