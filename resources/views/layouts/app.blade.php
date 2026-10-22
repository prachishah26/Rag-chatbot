<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Laravel RAG' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<nav class="nav">
    <div class="nav-inner">
        <a href="{{ route('documents.index') }}" class="brand">Laravel RAG</a>
        <div class="nav-links">
            @auth
                <a href="{{ route('documents.index') }}">Documents</a>
                <a href="{{ route('chat.index') }}">Chat</a>
                <form method="POST" action="{{ route('logout') }}" class="nav-logout">
                    @csrf
                    <span>{{ auth()->user()->name }}</span>
                    <button type="submit" class="link-button">Log out</button>
                </form>
            @else
                <a href="{{ route('login') }}">Log in</a>
                <a href="{{ route('register') }}">Register</a>
            @endauth
        </div>
    </div>
</nav>

<main class="container">
    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert error">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert error">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
