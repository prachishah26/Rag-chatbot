@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <span class="eyebrow">Knowledge base</span>
        <h1>Your documents</h1>
        <p>Upload PDF or TXT files. Laravel extracts and chunks the text, creates embeddings with Ollama, and stores them in Qdrant.</p>
    </div>
    <a class="button primary-gradient" href="{{ route('chat.index') }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Open Chat
    </a>
</div>

<div class="stats">
    <div class="stat">
        <span class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
        </span>
        <div>
            <div class="stat-value">{{ number_format($stats->total) }}</div>
            <div class="stat-label">Documents</div>
        </div>
    </div>
    <div class="stat">
        <span class="stat-icon green">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        </span>
        <div>
            <div class="stat-value">{{ number_format($stats->chunks) }}</div>
            <div class="stat-label">Indexed chunks</div>
        </div>
    </div>
    <div class="stat">
        <span class="stat-icon amber">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </span>
        <div>
            <div class="stat-value">{{ number_format($stats->pending ?? 0) }}</div>
            <div class="stat-label">Waiting to index</div>
        </div>
    </div>
</div>

<div class="docs-grid">
<section class="card docs-card">
    <div class="section-heading">
        <div>
            <h2>Documents</h2>
            <p>{{ number_format($stats->total) }} {{ Str::plural('file', $stats->total) }} in your knowledge base</p>
        </div>
    </div>

    @if($documents->count())
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th class="num">Chunks</th>
                        <th>Processed</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($documents as $document)
                    @php($extension = Str::lower(pathinfo($document->original_name, PATHINFO_EXTENSION)))
                    <tr>
                        <td>
                            <div class="file">
                                <span @class(['file-icon', 'txt' => $extension === 'txt'])>{{ Str::upper($extension ?: 'file') }}</span>
                                <div>
                                    <div class="file-name">
                                        {{ $document->original_name }}
                                        @if($document->isShared())
                                            <span class="shared-tag" title="Shared with every user; remove it from the server">Shared</span>
                                        @endif
                                    </div>
                                    <div class="file-meta">{{ Number::fileSize($document->size ?? 0) }} · added {{ $document->created_at?->diffForHumans() }}</div>
                                </div>
                            </div>
                        </td>
                        <td><span class="status {{ $document->status }}">{{ $document->status }}</span></td>
                        <td class="num">{{ number_format($document->chunk_count ?? 0) }}</td>
                        <td class="muted">{{ $document->processed_at?->format('M j, Y · H:i') ?? '—' }}</td>
                        <td class="actions">
                            @if($document->canBeDeletedBy(auth()->user()))
                                <form method="POST" action="{{ route('documents.destroy', $document) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="danger icon-button" title="Delete" aria-label="Delete {{ $document->original_name }}" onclick="return confirm({{ Js::from($document->isShared() ? 'Delete this shared document for every user?' : 'Delete this document?') }})">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @if($document->error_message)
                        <tr class="error-row"><td colspan="5">{{ $document->error_message }}</td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $documents->links('pagination::bootstrap-4') }}
    @else
        <div class="empty">
            <span class="empty-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            </span>
            <strong>No documents uploaded yet.</strong>
            <span>Add your first PDF or TXT file above to start chatting with it.</span>
        </div>
    @endif
</section>

<section class="card upload-card">
    <div class="section-heading">
        <div>
            <h2>Add document</h2>
            <p>Files are queued and indexed in the background.</p>
        </div>
    </div>
    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="upload-form" id="upload-form">
        @csrf
        <label class="dropzone" id="dropzone">
            <input type="file" name="document" id="document-input" accept=".pdf,.txt" required>
            <span class="dropzone-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
            </span>
            <span class="dropzone-title" id="dropzone-title"><span>Click to choose</span> or drag a file here</span>
            <span class="dropzone-hint" id="dropzone-hint">PDF or TXT · up to 50 MB</span>
        </label>
        @if(auth()->user()->is_admin)
            <label class="share-option">
                <input type="checkbox" name="shared" value="1">
                <span>
                    <strong>Share with all users</strong>
                    <small>Everyone can search it; only admins can delete it.</small>
                </span>
            </label>
        @endif
        <button type="submit" id="upload-button" class="primary-gradient" disabled>Upload &amp; Index</button>
        <small class="upload-note">Scanned/image-only PDFs require OCR/vision processing and are not handled by this text-only version.</small>
    </form>
</section>
</div>


<script>
(() => {
    const zone = document.getElementById('dropzone');
    const input = document.getElementById('document-input');
    const title = document.getElementById('dropzone-title');
    const hint = document.getElementById('dropzone-hint');
    const button = document.getElementById('upload-button');
    const form = document.getElementById('upload-form');

    const formatSize = bytes => bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;

    const showFile = () => {
        const file = input.files[0];
        zone.classList.toggle('has-file', Boolean(file));
        button.disabled = !file;
        title.textContent = file ? file.name : 'Click to choose or drag a file here';
        hint.textContent = file ? `${formatSize(file.size)} · ready to upload` : 'PDF or TXT · up to 50 MB';
    };

    input.addEventListener('change', showFile);
    ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, () => zone.classList.add('dragging')));
    ['dragleave', 'drop'].forEach(type => zone.addEventListener(type, () => zone.classList.remove('dragging')));

    form.addEventListener('submit', () => {
        button.disabled = true;
        button.innerHTML = '<span class="spinner"></span> Uploading…';
    });

    // Refresh while documents are being indexed, unless the user is mid-upload.
    @if($documents->contains(fn ($document) => in_array($document->status, ['queued', 'processing'])))
        setInterval(() => { if (!input.files.length) location.reload(); }, 5000);
    @endif
})();
</script>
@endsection
