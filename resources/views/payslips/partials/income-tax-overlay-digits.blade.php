{{--
  国税庁様式への数値転記（1桁ずつ絶対配置）
  @var array<int, string> $digits
  @var float $top  スリップ上端（mm）
  @var float $y    スリップ内相対 Y（mm）
  @var array<int, float> $xs  各桁の X 中心（mm）
--}}
@php
    $digitStyle = config('income_tax_statement.digit');
    $w = $digitStyle['width_mm'];
    $h = $digitStyle['height_mm'];
    $fs = $digitStyle['font_size_pt'];
    $absY = $top + $y;
@endphp
@foreach ($xs as $i => $x)
    @php $char = $digits[$i] ?? ''; @endphp
    @if ($char !== '' && $char !== ' ')
        <span
            class="overlay-digit"
            style="
                left: {{ $x }}mm;
                top: {{ $absY }}mm;
                width: {{ $w }}mm;
                height: {{ $h }}mm;
                font-size: {{ $fs }}pt;
            "
        >{{ $char }}</span>
    @endif
@endforeach
