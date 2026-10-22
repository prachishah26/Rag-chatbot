@extends('layouts.app')

@section('content')
<div class="auth-shell">
    <section class="card auth-card">
        <span class="brand-mark">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/></svg>
        </span>
        <h1>Create an account</h1>
        <p class="subtitle">Upload documents and get answers backed by evidence.</p>
        <form method="POST" action="{{ route('register') }}" class="auth-form">
            @csrf
            <label>Name
                <input type="text" name="name" value="{{ old('name') }}" placeholder="Jane Doe" required autofocus autocomplete="name">
            </label>
            <label>Email
                <input type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" required autocomplete="username">
            </label>
            <label>Password
                <input type="password" name="password" required autocomplete="new-password">
            </label>
            <label>Confirm password
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>
            <button type="submit" class="primary-gradient">Create account</button>
        </form>
        <small class="auth-footer">Already registered? <a href="{{ route('login') }}">Log in</a></small>
    </section>
</div>
@endsection
