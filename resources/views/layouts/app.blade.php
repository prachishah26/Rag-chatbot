<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'RAG Chatbot' }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#6366f1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@auth
<div class="app-shell">
    <aside class="app-sidebar">
        <a href="{{ route('documents.index') }}" class="brand">
            <span class="brand-mark">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/><path d="M19 17l.7 2.3L22 20l-2.3.7L19 23l-.7-2.3L16 20l2.3-.7z"/></svg>
            </span>
            <span class="brand-name">RAG Chatbot</span>
        </a>

        <nav class="side-nav">
            <span class="side-nav-label">Workspace</span>
            <a href="{{ route('documents.index') }}" @class(['active' => request()->routeIs('documents.*')])>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/></svg>
                <span>Documents</span>
            </a>
            <a href="{{ route('chat.index') }}" @class(['active' => request()->routeIs('chat.*')])>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Chat</span>
            </a>
        </nav>

        @include('partials.chat-sidebar')

        <form method="POST" action="{{ route('logout') }}" class="side-user">
            @csrf
            <span class="avatar" aria-hidden="true">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</span>
            <div class="side-user-info">
                <span class="side-user-name">{{ auth()->user()->name }}</span>
                <span class="side-user-email">{{ auth()->user()->email }}</span>
            </div>
            <button type="submit" class="ghost icon-button" title="Log out" aria-label="Log out">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
            </button>
        </form>
    </aside>

    <main class="app-main @yield('main-class')">
        @include('partials.alerts')
        @yield('content')
    </main>
</div>
@else
<nav class="nav">
    <div class="nav-inner">
        <a href="{{ route('login') }}" class="brand">
            <span class="brand-mark">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/><path d="M19 17l.7 2.3L22 20l-2.3.7L19 23l-.7-2.3L16 20l2.3-.7z"/></svg>
            </span>
            <span class="brand-name">RAG Chatbot</span>
        </a>
        <div class="nav-guest">
            <a class="button ghost" href="{{ route('login') }}">Log in</a>
            <a class="button" href="{{ route('register') }}">Register</a>
        </div>
    </div>
</nav>

<main class="container">
    @include('partials.alerts')
    @yield('content')
</main>
@endauth
</body>
</html>
