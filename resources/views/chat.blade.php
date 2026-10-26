@extends('layouts.app')

@section('main-class', 'flush')

@section('content')
<div class="chat-layout">
    <section class="chat-card">
        @if($conversation)
            <div class="chat-toolbar">
                <div class="chat-title">
                    <h2 title="{{ $conversation->title }}">{{ $conversation->title }}</h2>
                    <span class="scope-tag" title="Documents this conversation searches">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                        {{ $conversation->document?->original_name ?? 'All documents' }}
                    </span>
                </div>
                <form method="POST" action="{{ route('chat.destroy', $conversation->id) }}" onsubmit="return confirm('Delete this conversation?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ghost icon-button" title="Delete conversation" aria-label="Delete conversation">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                    </button>
                </form>
            </div>
        @endif

        <div id="messages" class="messages" aria-live="polite">
            <div id="chat-welcome" class="chat-welcome">
                <span class="brand-mark">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/></svg>
                </span>
                <h2>Ask your documents anything</h2>
                <p>Answers are written from the retrieved document sections, with the supporting passages shown as evidence. </p>
            </div>
        </div>

        <form id="chat-form" class="chat-form">
            @csrf
            @unless($conversation)
                <div class="scope-picker">
                    @if($documents->isNotEmpty())
                        <label for="document-scope">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            Search in
                        </label>
                        <select id="document-scope">
                            <option value="">All documents ({{ $documents->count() }})</option>
                            @foreach($documents as $document)
                                <option value="{{ $document->id }}">{{ $document->original_name }}{{ $document->isShared() ? ' · shared' : '' }}</option>
                            @endforeach
                        </select>
                    @else
                        <span>No indexed documents yet. <a href="{{ route('documents.index') }}">Upload one</a> to start asking questions.</span>
                    @endif
                </div>
            @endunless
            <div class="composer">
                <label for="question" class="sr-only">Your question</label>
                <textarea id="question" rows="1" maxlength="2000" placeholder="Ask something about your documents…" required></textarea>
                <button id="ask-button" type="submit" title="Send" aria-label="Send">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                </button>
            </div>
            <small class="composer-hint"><kbd>Enter</kbd> to send · <kbd>Shift</kbd> + <kbd>Enter</kbd> for a new line</small>
        </form>
    </section>
</div>

<script>
const form = document.getElementById('chat-form');
const question = document.getElementById('question');
const messages = document.getElementById('messages');
const button = document.getElementById('ask-button');
const welcome = document.getElementById('chat-welcome');
const scope = document.getElementById('document-scope');
const userInitial = @json(Str::upper(Str::substr(auth()->user()->name, 0, 1)));
const sendIcon = button.innerHTML;
let conversationId = @json($conversation?->id);

const ASSISTANT_AVATAR = `<span class="avatar" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/></svg></span>`;

// Earlier messages of this conversation, saved on the server.
for (const message of {{ Js::from($history) }}) {
    if (message.role === 'user') {
        addUserMessage(message.content);
    } else {
        addAssistantMessage({ answer: message.content, sources: message.sources, quotes: message.quotes });
    }
}

function addMessage(role, html) {
    welcome?.remove();
    const div = document.createElement('div');
    div.className = `message ${role}`;
    div.innerHTML = html;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    return div;
}

// The textarea grows with its content, up to the CSS max-height.
function resizeQuestion() {
    question.style.height = 'auto';
    question.style.height = `${question.scrollHeight}px`;
}
question.addEventListener('input', resizeQuestion);

// Enter sends; Shift+Enter adds a new line. Composing text (e.g. an IME) is left alone.
question.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (!button.disabled) form.requestSubmit();
    }
});

// Copy buttons are added to every answer, so one delegated listener handles them all.
messages.addEventListener('click', async (event) => {
    const copy = event.target.closest('[data-copy]');
    if (!copy) return;
    const answer = copy.closest('.message-body').querySelector('.answer');
    try {
        await navigator.clipboard.writeText(answer.innerText);
        copy.textContent = 'Copied';
    } catch {
        copy.textContent = 'Copy failed';
    }
    setTimeout(() => { copy.textContent = 'Copy'; }, 1500);
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const value = question.value.trim();
    if (!value) return;

    addUserMessage(value);
    question.value = '';
    resizeQuestion();
    button.disabled = true;
    button.innerHTML = '<span class="spinner"></span>';
    const typing = addMessage('assistant', `${ASSISTANT_AVATAR}<div class="message-body"><div class="message-author">RAG Assistant</div><div class="bubble"><span class="typing" aria-label="Thinking"><span></span><span></span><span></span></span></div></div>`);

    try {
        const response = await fetch('{{ route('chat.ask') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
            },
            body: JSON.stringify({
                question: value,
                conversation_id: conversationId,
                // Only a new chat picks its documents; the server keeps that choice for the conversation.
                document_id: !conversationId && scope?.value ? Number(scope.value) : null,
            })
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.errors?.document_id?.[0] || data.error || data.message || 'Request failed.');
        }

        typing.remove();
        addAssistantMessage(data);

        // A new chat gets its own URL, so reloading the page shows the same conversation.
        if (!conversationId && data.conversation_id) {
            conversationId = data.conversation_id;
            history.replaceState(null, '', `{{ url('/chat') }}/${conversationId}`);
            addToSidebar(conversationId, value);
            if (scope) scope.disabled = true;
        }
    } catch (error) {
        typing.remove();
        addMessage('assistant error-message', `${ASSISTANT_AVATAR}<div class="message-body"><div class="message-author">Error</div><div class="bubble">${escapeHtml(error.message)}</div></div>`);
    } finally {
        button.disabled = false;
        button.innerHTML = sendIcon;
        question.focus();
    }
});

// Lists a chat started on this page without a reload; the server titles it from the first question.
function addToSidebar(id, title) {
    const label = document.createElement('span');
    label.textContent = title.replace(/\s+/g, ' ').slice(0, 80);

    const link = document.createElement('a');
    link.href = `{{ url('/chat') }}/${id}`;
    link.className = 'active';
    link.title = title;
    link.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    link.appendChild(label);

    const deleteForm = document.getElementById('conversation-delete-template').content.firstElementChild.cloneNode(true);
    deleteForm.action = `{{ url('/chat') }}/${id}`;

    const item = document.createElement('li');
    item.append(link, deleteForm);
    const list = document.getElementById('conversation-list');
    list.querySelectorAll('a.active').forEach(active => active.classList.remove('active'));
    list.prepend(item);
    document.getElementById('no-conversations').hidden = true;
}

function addUserMessage(text) {
    addMessage('user', `<span class="avatar" aria-hidden="true">${escapeHtml(userInitial)}</span>
        <div class="message-body"><div class="message-author">You</div><div class="bubble">${escapeHtml(text)}</div></div>`);
}

function addAssistantMessage(data) {
    const sourceList = data.sources || [];
    const quoteList = data.quotes || [];

    const sources = sourceList.map(source => {
        const meta = [
            source.page ? `page ${escapeHtml(String(source.page))}` : '',
            source.section ? escapeHtml(source.section) : '',
        ].filter(Boolean).join(' · ');

        return `<li class="source">
            <div class="source-info">
                <span class="source-doc">${escapeHtml(source.document)}</span>
                ${meta ? `<span class="source-meta">${meta}</span>` : ''}
            </div>
            ${source.cited ? '<span class="chip">cited</span>' : ''}
            <span class="score" title="Similarity score">${escapeHtml(String(source.score))}</span>
        </li>`;
    }).join('');

    // The answer is generated by the model; quotes are the document passages that back it up.
    const evidence = quoteList.map(renderQuote).join('');

    addMessage(
        'assistant',
        `${ASSISTANT_AVATAR}
         <div class="message-body">
            <div class="message-author">RAG Assistant</div>
            <div class="bubble">
                <div class="answer">${renderAnswer(data.answer || '')}</div>
                ${evidence ? `<details><summary>Evidence from documents <span class="count">${quoteList.length}</span></summary><div class="details-body">${evidence}</div></details>` : ''}
                ${sources ? `<details><summary>Sources <span class="count">${sourceList.length}</span></summary><div class="details-body"><ul class="source-list">${sources}</ul></div></details>` : ''}
            </div>
            <div class="message-actions"><button type="button" class="ghost" data-copy>Copy</button></div>
         </div>`
    );
}

// Minimal Markdown for model answers: **bold**, bullet lists and pipe tables.
function renderAnswer(text) {
    const inline = line => escapeHtml(line).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    const cells = line => line.trim().replace(/^\||\|$/g, '').split('|').map(cell => cell.trim());
    const blocks = [];
    let list = [];
    let table = [];

    const flush = () => {
        if (list.length) blocks.push(`<ul>${list.map(item => `<li>${inline(item)}</li>`).join('')}</ul>`);
        const rows = table.filter(row => !/^\|?\s*:?-{3,}/.test(row)).map(cells);
        if (rows.length) {
            const [head, ...body] = rows;
            blocks.push(`<div class="table-wrap"><table class="quote-table">
                <thead><tr>${head.map(c => `<th>${inline(c)}</th>`).join('')}</tr></thead>
                <tbody>${body.map(r => `<tr>${r.map(c => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody>
            </table></div>`);
        }
        list = [];
        table = [];
    };

    for (const line of text.split('\n')) {
        if (/^\s*\|.*\|\s*$/.test(line)) { if (list.length) flush(); table.push(line); continue; }
        if (/^\s*[-*•]\s+/.test(line)) { if (table.length) flush(); list.push(line.replace(/^\s*[-*•]\s+/, '')); continue; }
        flush();
        if (line.trim()) blocks.push(`<p>${inline(line)}</p>`);
    }
    flush();

    return blocks.join('');
}

// Quotes are exact document text: tables as rows of cells, everything else as a sentence.
function renderQuote(quote) {
    const cite = `<cite>${escapeHtml(quote.document)} · chunk ${escapeHtml(String(quote.chunk))}</cite>`;

    if (!quote.table) {
        return `<blockquote class="quote">“${escapeHtml(quote.text)}”${cite}</blockquote>`;
    }

    const isFigure = cell => /^[$€£]?\s*\(?-?[\d,.]+%?\)?$/.test(cell.trim());
    const row = (cells, tag) => `<tr>${cells.map(cell =>
        `<${tag}${isFigure(cell) ? ' class="num"' : ''}>${escapeHtml(cell)}</${tag}>`
    ).join('')}</tr>`;
    // Without a real header row (table cut by a chunk boundary), every row is data.
    const [header, ...body] = quote.table_header ? quote.table : [null, ...quote.table];

    return `<div class="quote table-wrap">
        <table class="quote-table">
            ${header ? `<thead>${row(header, 'th')}</thead>` : ''}
            <tbody>${body.map(cells => row(cells, 'td')).join('')}</tbody>
        </table>${cite}
    </div>`;
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
}
</script>
@endsection
