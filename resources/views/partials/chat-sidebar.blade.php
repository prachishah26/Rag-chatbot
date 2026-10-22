{{-- Recent chats, shown on every page. $sidebarConversations comes from the view composer in AppServiceProvider. --}}
@php($openConversation = (int) request()->route('conversation'))
<div class="side-section">
    <a class="button new-chat" href="{{ route('chat.index') }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        New chat
    </a>
    <div class="side-nav-label">Recent chats</div>
    <small id="no-conversations" @if($sidebarConversations->isNotEmpty()) hidden @endif>Your conversations will appear here.</small>
    <ul id="conversation-list" class="conversation-list">
        @foreach($sidebarConversations as $item)
            <li>
                <a href="{{ route('chat.show', $item->id) }}" title="{{ $item->title }}" @class(['active' => $openConversation === $item->id])>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>{{ $item->title }}</span>
                </a>
                <form method="POST" action="{{ route('chat.destroy', $item->id) }}" class="conversation-delete" onsubmit="return confirm('Delete this conversation?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ghost icon-button" title="Delete conversation" aria-label="Delete conversation">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                    </button>
                </form>
            </li>
        @endforeach
    </ul>
    {{-- Delete form for chats added to the list by the chat page's script; it fills in the action. --}}
    <template id="conversation-delete-template">
        <form method="POST" class="conversation-delete" onsubmit="return confirm('Delete this conversation?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="ghost icon-button" title="Delete conversation" aria-label="Delete conversation">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
            </button>
        </form>
    </template>
</div>
