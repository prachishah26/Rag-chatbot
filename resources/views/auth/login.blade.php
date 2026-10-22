@extends('layouts.app')

@section('content')
<div class="auth-shell">
    <section class="card auth-card">
        <span class="brand-mark">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/></svg>
        </span>
        <h1>Welcome back</h1>
        <p class="subtitle">Log in to chat with your documents.</p>
        <form method="POST" action="{{ route('login') }}" class="auth-form">
            @csrf
            <label>Email
                <input type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" required autofocus autocomplete="username">
            </label>
            <label>Password
                <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
            </label>
            <label class="checkbox"><input type="checkbox" name="remember"> Remember me</label>
            <button type="submit" class="primary-gradient">Log in</button>
        </form>
        <small class="auth-footer">No account? <a href="{{ route('register') }}">Create one</a></small>
    </section>
</div>
@endsection
