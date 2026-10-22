<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngestDocument extends Command
{
    protected $signature = 'rag:ingest {path : A local PDF or TXT file} {--replace : Re-index a document with the same file name}';

    protected $description = 'Add a local PDF or TXT file to the index and index it now (no queue worker needed)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!is_file($path) || !in_array($extension, ['pdf', 'txt'], true)) {
            $this->error('Give the path of an existing .pdf or .txt file.');

            return self::FAILURE;
        }

        $name = basename($path);
        // CLI documents are shared; a user's private upload with the same name is a different document.
        $existing = Document::whereNull('user_id')->where('original_name', $name)->first();

        if ($existing && !$this->option('replace')) {
            $this->error("{$name} is already indexed (document {$existing->id}); pass --replace to re-index it.");

            return self::FAILURE;
        }

        $attributes = [
            'original_name' => $name,
            'path' => Storage::disk('local')->putFileAs('documents', $path, Str::random(40).'.'.$extension),
            'mime_type' => $extension === 'pdf' ? 'application/pdf' : 'text/plain',
            'size' => filesize($path),
            'status' => 'queued',
        ];

        if ($existing) {
            // Index the file as it is now, not the copy stored last time.
            $oldPath = $existing->path;
            $existing->update($attributes);
            Storage::disk('local')->delete($oldPath);
        }

        $document = $existing ?? Document::create($attributes);

        $this->info("Indexing {$name} (document {$document->id})…");
        $started = microtime(true);
        ProcessDocument::dispatchSync($document->id);

        $document->refresh();
        $this->info(sprintf('Done: %d chunks in %.1fs.', $document->chunk_count, microtime(true) - $started));

        return self::SUCCESS;
    }
}
