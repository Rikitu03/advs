<?php

namespace Tests\Unit\Support;

use App\Support\IssuerLogoCatalog;
use Tests\TestCase;

class IssuerLogoCatalogTest extends TestCase
{
    public function test_resolves_ordered_national_references_from_the_manifest(): void
    {
        $catalog = app(IssuerLogoCatalog::class);

        $this->assertSame('1.0', $catalog->version());
        $this->assertSame(
            ['bir-permit-logo', 'bir-seal'],
            array_column($catalog->referencesFor('bir_certificate', 'national', null), 'key'),
        );
    }

    public function test_canonicalizes_lgu_cities_when_resolving_references(): void
    {
        $references = app(IssuerLogoCatalog::class)->referencesFor(
            'business_permit',
            'lgu',
            'CITY OF MAKATI',
        );

        $this->assertSame(
            ['makati-building-logo', 'makati-gold-logo', 'makati-logo'],
            array_column($references, 'key'),
        );
    }

    public function test_only_manifest_keys_resolve_to_existing_catalog_files(): void
    {
        $catalog = app(IssuerLogoCatalog::class);
        $path = $catalog->pathFor('dti-logo');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertNull($catalog->pathFor('not-in-the-manifest'));
        $this->assertNull($catalog->pathFor('../manifest.json'));
    }
}
