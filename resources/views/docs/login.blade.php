@extends('docs.layout')

@section('title', '社内資料')

@section('body')
    <div class="gate">
        <div class="card">
            <h1>社内資料</h1>
            <p class="note">閲覧用のパスワードを入力してください。</p>

            <form method="POST" action="{{ route('docs.login') }}">
                @csrf
                <input type="hidden" name="intended" value="{{ $intended }}">
                <input type="password" name="password" autocomplete="current-password" autofocus
                    placeholder="パスワード">
                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror
                <button type="submit">表示する</button>
            </form>
        </div>
    </div>
@endsection
