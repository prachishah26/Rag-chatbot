@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <h1>Knowledge Base</h1>
        <p>Upload PDF or TXT files. Laravel extracts and chunks the text, creates embeddings with Ollama, and stores them in Qdrant.</p>
    </div>
</div>

<section class="card">
    <h2>Add document</h2>
    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="upload-form">
        @csrf
        <input type="file" name="document" accept=".pdf,.txt" required>
        <button type="submit">Upload & Index</button>
    </form>
    <small>Maximum file size: 50 MB. Scanned/image-only PDFs require OCR/vision processing and are not handled by this text-only version.</small>
</section>

<section class="card">
    <div class="section-heading">
        <h2>Documents</h2>
        <a class="button secondary" href="{{ route('chat.index') }}">Open Chat</a>
    </div>

    @if($documents->count())
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Chunks</th>
                        <th>Processed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($documents as $document)
                    <tr>
                        <td>{{ $document->original_name }}</td>
                        <td><span class="status {{ $document->status }}">{{ $document->status }}</span></td>
                        <td>{{ $document->chunk_count }}</td>
                        <td>{{ $document->processed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('documents.destroy', $document) }}">
                                @csrf
                                @method('DELETE')
                                <button class="danger" onclick="return confirm('Delete this document?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @if($document->error_message)
                        <tr><td colspan="5" class="error-row">{{ $document->error_message }}</td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    @else
        <div class="empty">No documents uploaded yet.</div>
    @endif
</section>
@endsection
