@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <h1>Document Chat</h1>
        <p>Ask questions about your indexed documents. Answers are written from the retrieved document sections, with the supporting passages shown as evidence. Questions that name a region or chapter search only that part of the report.</p>
    </div>
</div>

<div class="chat-layout">
    <aside class="card chat-sidebar">
        <a class="button" href="{{ route('chat.index') }}">+ New chat</a>
        <small id="no-conversations" @if($conversations->isNotEmpty()) hidden @endif>Your conversations will appear here.</small>
        <ul id="conversation-list" class="conversation-list">
            @foreach($conversations as $item)
                <li>
                    <a href="{{ route('chat.show', $item->id) }}" title="{{ $item->title }}" @class(['active' => $conversation?->id === $item->id])>{{ $item->title }}</a>
                </li>
            @endforeach
        </ul>
    </aside>

    <section class="chat-card">
        @if($conversation)
            <div class="chat-toolbar">
                <h2 title="{{ $conversation->title }}">{{ $conversation->title }}</h2>
                <form method="POST" action="{{ route('chat.destroy', $conversation->id) }}" onsubmit="return confirm('Delete this conversation?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="danger">Delete</button>
                </form>
            </div>
        @endif

        <div id="messages" class="messages">
            <div class="message assistant">
                <strong>RAG Assistant</strong>
                <div>Upload and index a document, then ask me a question about it.</div>
            </div>
        </div>

        <form id="chat-form" class="chat-form">
            @csrf
            <textarea id="question" rows="3" placeholder="Ask something about your documents... (Enter to send, Shift+Enter for a new line)" required></textarea>
            <button id="ask-button" type="submit">Ask</button>
        </form>
    </section>
</div>

<script>
const form = document.getElementById('chat-form');
const question = document.getElementById('question');
const messages = document.getElementById('messages');
const button = document.getElementById('ask-button');
let conversationId = @json($conversation?->id);

// Earlier messages of this conversation, saved on the server.
for (const message of @json($history)) {
    if (message.role === 'user') {
        addUserMessage(message.content);
    } else {
        addAssistantMessage({ answer: message.content, sources: message.sources, quotes: message.quotes });
    }
}

function addMessage(role, html) {
    const div = document.createElement('div');
    div.className = `message ${role}`;
    div.innerHTML = html;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
}

// Enter sends; Shift+Enter adds a new line. Composing text (e.g. an IME) is left alone.
question.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (!button.disabled) form.requestSubmit();
    }
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const value = question.value.trim();
    if (!value) return;

    addUserMessage(value);
    question.value = '';
    button.disabled = true;
    button.textContent = 'Thinking...';

    try {
        const response = await fetch('{{ route('chat.ask') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
            },
            body: JSON.stringify({ question: value, conversation_id: conversationId })
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || 'Request failed.');
        }

        addAssistantMessage(data);

        // A new chat gets its own URL, so reloading the page shows the same conversation.
        if (!conversationId && data.conversation_id) {
            conversationId = data.conversation_id;
            history.replaceState(null, '', `{{ url('/chat') }}/${conversationId}`);
            addToSidebar(conversationId, value);
        }
    } catch (error) {
        addMessage('assistant error-message', `<strong>Error</strong><div>${escapeHtml(error.message)}</div>`);
    } finally {
        button.disabled = false;
        button.textContent = 'Ask';
    }
});

// Lists a chat started on this page without a reload; the server titles it from the first question.
function addToSidebar(id, title) {
    const link = document.createElement('a');
    link.href = `{{ url('/chat') }}/${id}`;
    link.className = 'active';
    link.title = title;
    link.textContent = title.replace(/\s+/g, ' ').slice(0, 80);

    const item = document.createElement('li');
    item.appendChild(link);
    document.getElementById('conversation-list').prepend(item);
    document.getElementById('no-conversations').hidden = true;
}

function addUserMessage(text) {
    addMessage('user', `<strong>You</strong><div>${escapeHtml(text)}</div>`);
}

function addAssistantMessage(data) {
    const sources = (data.sources || []).map(source =>
        `<li>${escapeHtml(source.document)}${source.page ? ` · page ${escapeHtml(String(source.page))}` : ''}${source.section ? ` · ${escapeHtml(source.section)}` : ''} · score ${escapeHtml(String(source.score))}${source.cited ? ' · cited' : ''}</li>`
    ).join('');

    // The answer is generated by the model; quotes are the document passages that back it up.
    const evidence = (data.quotes || []).map(renderQuote).join('');

    addMessage(
        'assistant',
        `<strong>RAG Assistant</strong>
         <div class="answer">${renderAnswer(data.answer || '')}</div>
         ${evidence ? `<details><summary>Evidence from documents</summary>${evidence}</details>` : ''}
         ${sources ? `<details><summary>Sources</summary><ul>${sources}</ul></details>` : ''}`
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
    return value.replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
}
</script>
@endsection
