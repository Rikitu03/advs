<?php

namespace Tests\Feature\Document;

use App\Models\Document;
use App\Models\TamperAnalysis;
use App\Services\Document\TamperDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TamperDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_payload_uses_the_original_file_and_config_weights(): void
    {
        $document = Document::factory()->create([
            'file_path' => 'documents/sample.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $payload = (new TamperDetectionService)->buildPayload($document, ocrText: 'TIN: 274-118-902-000');

        $this->assertStringEndsWith('documents/sample.jpg', $payload['original_path']);
        $this->assertSame([$payload['original_path']], $payload['page_images']); // raster = its own page
        $this->assertSame('TIN: 274-118-902-000', $payload['ocr_text']);
        $this->assertSame(config('advs.forensics.weights'), $payload['weights']);
    }

    public function test_build_payload_omits_page_images_for_pdf(): void
    {
        $document = Document::factory()->create([
            'file_path' => 'documents/cert.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $payload = (new TamperDetectionService)->buildPayload($document);

        // PDFs need full-DPI rendered pages from the orchestrator, not the original.
        $this->assertSame([], $payload['page_images']);
    }

    public function test_persist_maps_verdict_onto_a_tamper_analysis_row(): void
    {
        $document = Document::factory()->create();

        $verdict = [
            'tamper_score' => 0.62,
            'tamper_authenticity' => 0.38,
            'tamper_confidence' => 0.85,
            'hard_flag' => true,
            'tamper_passed' => false,
            'flags' => ['Copy-move: 48 cloned keypoints', 'Malformed TIN'],
            'techniques' => [
                'metadata' => ['score' => 1.0, 'pass' => true, 'flags' => []],
                'copy_move' => ['score' => 0.15, 'pass' => false, 'flags' => ['Copy-move: 48 cloned keypoints']],
            ],
        ];

        $analysis = (new TamperDetectionService)->persist($document, $verdict);

        $this->assertInstanceOf(TamperAnalysis::class, $analysis);
        $this->assertDatabaseHas('tamper_analyses', [
            'document_id' => $document->id,
            'submission_id' => $document->submission_id,
            'hard_flag' => true,
            'tamper_passed' => false,
        ]);

        $fresh = $analysis->fresh();
        $this->assertEqualsWithDelta(0.62, $fresh->tamper_score, 0.0001);
        $this->assertIsArray($fresh->copy_move_result);          // JSON cast
        $this->assertFalse($fresh->copy_move_result['pass']);
        $this->assertContains('Malformed TIN', $fresh->flags);   // JSON cast
        $this->assertTrue($fresh->hard_flag);                    // boolean cast
    }

    public function test_persist_is_idempotent_per_document(): void
    {
        $document = Document::factory()->create();
        $service = new TamperDetectionService;

        $service->persist($document, ['tamper_score' => 0.1]);
        $service->persist($document, ['tamper_score' => 0.2]);

        $this->assertSame(1, TamperAnalysis::where('document_id', $document->id)->count());
        $this->assertEqualsWithDelta(0.2, $document->tamperAnalysis()->first()->tamper_score, 0.0001);
    }

    public function test_analyze_runs_the_python_runner_end_to_end(): void
    {
        $python = base_path('python/venv/bin/python');
        if (! file_exists($python)) {
            $this->markTestSkipped('python/venv not set up — skipping the live Stage T integration.');
        }
        config(['advs.forensics.python_bin' => $python]);

        Storage::fake('local');
        $document = Document::factory()->create([
            'file_path' => 'documents/page.png',
            'mime_type' => 'image/png',
        ]);
        // A real PNG at the resolved path so the forensic script has something to read.
        Storage::disk('local')->makeDirectory('documents');
        $img = imagecreatetruecolor(80, 80);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 180, 160));
        imagepng($img, Storage::disk('local')->path('documents/page.png'));
        imagedestroy($img);

        $verdict = (new TamperDetectionService)->analyze($document);

        $this->assertArrayHasKey('tamper_score', $verdict);
        $this->assertArrayHasKey('tamper_passed', $verdict);
        $this->assertArrayHasKey('techniques', $verdict);

        // Temp payloads must always be cleaned up (CLAUDE.md §9a).
        $leftovers = File::glob(storage_path('app/python_payloads/tamper_*'));
        $this->assertSame([], $leftovers);
    }
}
