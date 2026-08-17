<?php

namespace App\Jobs;

use App\Actions\ProcessDocumentAction;
use App\Models\Document;
use App\Models\PipelineRun;
use App\Services\Document\SubmissionFinalizer;
use App\Services\SystemSettingsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues the full validation pipeline (including Stage T forensics) for one
 * document. ML inference can be slow, hence the generous timeout; transient
 * failures retry with backoff before the document is marked failed.
 */
class ProcessDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 360;

    public int $uniqueFor = 420;

    public function __construct(public Document $document)
    {
        $this->onQueue('document-processing');
    }

    public function handle(ProcessDocumentAction $action): void
    {
        $action->execute($this->document->freshOrFail(), $this->attempts());
    }

    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    public function failed(Throwable $e): void
    {
        $this->document->update(['processing_status' => Document::STATUS_FAILED]);

        if (! $this->document->pipelineRuns()
            ->where('status', PipelineRun::STATUS_FAILED)
            ->where('attempt', $this->tries)
            ->exists()) {
            $settings = app(SystemSettingsService::class);
            $snapshot = $settings->pipelineSnapshot();
            PipelineRun::query()->create([
                'document_id' => $this->document->id,
                'submission_id' => $this->document->submission_id,
                'attempt' => max(1, $this->tries),
                'status' => PipelineRun::STATUS_FAILED,
                'schema_version' => null,
                'settings_snapshot' => $snapshot,
                'settings_hash' => $settings->pipelineSnapshotHash($snapshot),
                'models' => null,
                'timings' => null,
                'flags' => ['pipeline_exhausted'],
                'error' => [
                    'type' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 1000),
                    'terminal' => true,
                ],
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }

        $result = $this->document->validationResult()->firstOrNew([], [
            'submission_id' => $this->document->submission_id,
        ]);
        $result->flags = array_values(array_unique(array_merge(
            $result->flags ?? [],
            ['processing_failed'],
        )));
        $this->document->validationResult()->save($result);

        Log::error('ProcessDocumentJob failed', [
            'document_id' => $this->document->id,
            'error' => $e->getMessage(),
        ]);

        // A failed document is terminal too — without this, one bad file
        // strands the whole submission in PROCESSING forever.
        if ($this->document->submission !== null) {
            app(SubmissionFinalizer::class)->finalize($this->document->submission);
        }
    }
}
