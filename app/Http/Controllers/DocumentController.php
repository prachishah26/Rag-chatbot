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
    public function index(): View
    {
        return view('documents.index', [
            'documents' => Document::latest()->paginate(10),
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
        ]);

        $file = $validated['document'];
        $path = $file->store('documents');

        $document = Document::create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => 'queued',
        ]);

        ProcessDocument::dispatch($document->id);

        return back()->with('success', 'Document uploaded and queued for indexing.');
    }

    public function destroy(Document $document, QdrantService $qdrant): RedirectResponse
    {
        // The indexing job would re-add vectors after we delete them.
        if ($document->status === 'processing') {
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
