{{-- 1連分の転記データ --}}
@php
    $layout = config('income_tax_statement.fields');
    $rowXs = config('income_tax_statement.row_xs');
    $row01Y = $layout['row01']['y'];
    $row02Y = $layout['row02']['y'];
@endphp

{{-- 整理番号 --}}
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['corporate_number']['y'],
    'xs' => $layout['corporate_number']['xs'],
    'digits' => $form['corporate_number'],
])

{{-- 年度（令和年） --}}
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['reiwa']['y'],
    'xs' => $layout['reiwa']['xs'],
    'digits' => $form['reiwa'],
])

{{-- 税務署名・税務署番号 --}}
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['tax_office_sign']['y'],
    'xs' => $layout['tax_office_sign']['xs'],
    'digits' => $form['tax_office_sign'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['tax_office_number']['y'],
    'xs' => $layout['tax_office_number']['xs'],
    'digits' => $form['tax_office_number'],
])

{{-- 納期等の区分 --}}
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['due_era']['y'],
    'xs' => $layout['due_era']['xs'],
    'digits' => $form['due_period']['era'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['due_month']['y'],
    'xs' => $layout['due_month']['xs'],
    'digits' => $form['due_period']['month'],
])

{{-- 01 俸給・給料等 --}}
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row01Y,
    'xs' => $rowXs['payment'],
    'digits' => array_merge($form['payment_date']['era'], $form['payment_date']['month'], $form['payment_date']['day']),
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row01Y,
    'xs' => $rowXs['count'],
    'digits' => $form['salary']['count'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row01Y,
    'xs' => $rowXs['amount'],
    'digits' => $form['salary']['amount'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row01Y,
    'xs' => $rowXs['tax'],
    'digits' => $form['salary']['tax'],
])

{{-- 02 賞与 --}}
@if(($form['bonus']['amount_value'] ?? 0) > 0)
    @include('payslips.partials.income-tax-overlay-digits', [
        'top' => $slipTop,
        'y' => $row02Y,
        'xs' => $rowXs['payment'],
        'digits' => array_merge($form['bonus_payment_date']['era'], $form['bonus_payment_date']['month'], $form['bonus_payment_date']['day']),
    ])
@endif
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row02Y,
    'xs' => $rowXs['count'],
    'digits' => $form['bonus']['count'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row02Y,
    'xs' => $rowXs['amount'],
    'digits' => $form['bonus']['amount'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $row02Y,
    'xs' => $rowXs['tax'],
    'digits' => $form['bonus']['tax'],
])

{{-- 本税・合計額 --}}
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['principal_tax']['y'],
    'xs' => $layout['principal_tax']['xs'],
    'digits' => $form['principal_tax'],
])
@include('payslips.partials.income-tax-overlay-digits', [
    'top' => $slipTop,
    'y' => $layout['total_tax']['y'],
    'xs' => $layout['total_tax']['xs'],
    'digits' => $form['total_tax'],
])

{{-- 電話番号 --}}
@if($form['payer']['phone'])
    @include('payslips.partials.income-tax-overlay-digits', [
        'top' => $slipTop,
        'y' => $layout['phone']['y'],
        'xs' => $layout['phone']['xs'],
        'digits' => $form['payer']['phone_digits'],
    ])
@endif

{{-- 住所・氏名 --}}
@php
    $addr = $layout['payer_address'];
    $name = $layout['payer_name'];
    $addressText = $form['payer']['address'] ?: $form['payer']['prefecture'];
@endphp
@if($addressText)
    <span
        class="overlay-text"
        style="
            left: {{ $addr['x'] }}mm;
            top: {{ $slipTop + $addr['y'] }}mm;
            max-width: {{ $addr['max_width_mm'] }}mm;
            font-size: {{ $addr['font_size_pt'] }}pt;
        "
    >{{ $addressText }}</span>
@endif
@if($form['payer']['name'])
    <span
        class="overlay-text"
        style="
            left: {{ $name['x'] }}mm;
            top: {{ $slipTop + $name['y'] }}mm;
            max-width: {{ $name['max_width_mm'] }}mm;
            font-size: {{ $name['font_size_pt'] }}pt;
            font-weight: {{ $name['font_weight'] }};
        "
    >{{ $form['payer']['name'] }}</span>
@endif
