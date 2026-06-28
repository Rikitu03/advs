<?php

namespace App\Jobs;

use App\Actions\ProcessDocumentAction;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues the full validation pipeline (including Stage T forensics) for one
 * document. ML inference can be slow, hence the generous timeout; transient
 * failures retry with backoff before the document is marked failed.
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public int $timeout = 300;

    public function __construct(public Document $document)
    {
        $this->onQueue('document-processing');
    }

    public function handle(ProcessDocumentAction $action): void
    {
        $action->execute($this->document);
    }

    public function failed(Throwable $e): void
    {
        $this->document->update(['processing_status' => Document::STATUS_FAILED]);

        Log::error('ProcessDocumentJob failed', [
            'document_id' => $this->document->id,
            'error' => $e->getMessage(),
        ]);
    }
}
