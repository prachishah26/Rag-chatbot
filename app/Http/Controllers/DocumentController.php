<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Rag\QdrantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        return view('documents.index', [
            'documents' => Document::visibleTo($request->user())->latest()->paginate(10),
            // Totals across all pages, for the summary cards.
            'stats' => Document::visibleTo($request->user())->toBase()
                ->selectRaw("count(*) as total, coalesce(sum(chunk_count), 0) as chunks, sum(case when status in ('queued', 'processing') then 1 else 0 end) as pending")
                ->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document' => [
                'required',
                'file',
                'max:51200',
                'mimes:pdf,txt',
            ],
            'shared' => ['sometimes', 'boolean'],
        ]);

        $shared = (bool) ($validated['shared'] ?? false);
        abort_if($shared && !$request->user()->is_admin, 403, 'Only admins can share documents with all users.');

        $file = $validated['document'];
        $path = $file->store('documents');

        $document = Document::create([
            // A shared document has no owner: every user can search it.
            'user_id' => $shared ? null : $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => Document::QUEUED,
        ]);

        ProcessDocument::dispatch($document->id);

        return back()->with('success', $shared
            ? 'Shared document uploaded and queued for indexing. Every user will be able to search it.'
            : 'Document uploaded and queued for indexing.');
    }

    public function destroy(Request $request, Document $document, QdrantService $qdrant): RedirectResponse
    {
        // Another user's document is a 404, not a leak; a shared one is visible but only admins delete it.
        abort_unless($document->isShared() || $document->user_id === $request->user()->id, 404);
        abort_unless($document->canBeDeletedBy($request->user()), 403, 'Only admins can delete shared documents.');

        // The indexing job would re-add vectors after we delete them.
        if ($document->status === Document::PROCESSING) {
            return back()->with('error', 'This document is still being indexed. Please delete it once indexing has finished.');
        }

        // Remove vectors first so a failure never leaves searchable chunks for a deleted document.
        try {
            $qdrant->deleteByDocument($document->id);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not remove the document from the search index. Please try again.');
        }

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return back()->with('success', 'Document deleted.');
    }
}
