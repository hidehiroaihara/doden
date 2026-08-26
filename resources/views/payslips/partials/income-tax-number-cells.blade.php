@php
    /** @var array<int, string> $digits */
    $digits = $digits ?? [];
@endphp
@foreach ($digits as $digit)
    <div class="number">{!! trim($digit) !== '' ? e($digit) : '&nbsp;' !!}</div>
@endforeach
