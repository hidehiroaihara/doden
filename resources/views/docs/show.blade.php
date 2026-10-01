@extends('docs.layout')

@section('title', $title.' | 社内資料')

@section('body')
    <div class="wrap">
        <div class="bar">
            <a href="{{ route('docs.index') }}">&larr; 資料一覧</a>
            <span>最終更新 {{ $updatedAt }}</span>
        </div>

        <div class="card">
            {{-- Markdown は html_input=escape で変換済み --}}
            <div class="md">{!! $html !!}</div>
        </div>

        <div class="bar" style="margin-top:20px;">
            <a href="{{ route('docs.index') }}">&larr; 資料一覧</a>
        </div>
    </div>
@endsection
