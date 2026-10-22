@extends('layouts.app')

@section('content')
<section class="card auth-card">
    <h1>Create an account</h1>
    <form method="POST" action="{{ route('register') }}" class="auth-form">
        @csrf
        <label>Name
            <input type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
        </label>
        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="new-password">
        </label>
        <label>Confirm password
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button type="submit">Register</button>
    </form>
    <small>Already registered? <a href="{{ route('login') }}">Log in</a></small>
</section>
@endsection
