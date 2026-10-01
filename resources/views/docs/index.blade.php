@extends('docs.layout')

@section('title', '社内資料')

@section('body')
    <div class="wrap">
        <div class="bar">
            <span>社内資料</span>
            <form method="POST" action="{{ route('docs.logout') }}">
                @csrf
                <button type="submit" style="background:none;border:0;color:#6b7280;cursor:pointer;font-size:13px;">
                    閲覧を終了する
                </button>
            </form>
        </div>

        <div class="card">
            <h1 style="font-size:20px;margin:0 0 4px;">資料一覧</h1>
            <p class="note">管理者向けの運用資料です。外部に共有しないでください。</p>

            <ul class="list">
                @foreach ($pages as $page)
                    <li>
                        <a href="{{ route('docs.show', $page['slug']) }}">
                            <strong>{{ $page['title'] }}</strong>
                            <span>{{ $page['description'] ?? '' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection
