<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Console\Command;
use Throwable;

class ReindexDocuments extends Command
{
    protected $signature = 'rag:reindex {--queue : Queue the jobs instead of indexing now}';

    protected $description = 'Re-index every uploaded document (after changing the embedding model, chunking or collection)';

    public function handle(): int
    {
        $failed = 0;

        foreach (Document::orderBy('id')->get() as $document) {
            if ($this->option('queue')) {
                $document->update(['status' => 'queued']);
                ProcessDocument::dispatch($document->id);
                $this->line("Queued {$document->original_name}");
                continue;
            }

            $this->line("Indexing {$document->original_name}…");

            try {
                ProcessDocument::dispatchSync($document->id);
                $this->info('  '.$document->refresh()->chunk_count.' chunks');
            } catch (Throwable $e) {
                $failed++;
                $this->error('  failed: '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
