@extends('layouts.app')

@section('content')
<section class="card auth-card">
    <h1>Log in</h1>
    <form method="POST" action="{{ route('login') }}" class="auth-form">
        @csrf
        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <label class="checkbox"><input type="checkbox" name="remember"> Remember me</label>
        <button type="submit">Log in</button>
    </form>
    <small>No account? <a href="{{ route('register') }}">Register</a></small>
</section>
@endsection
