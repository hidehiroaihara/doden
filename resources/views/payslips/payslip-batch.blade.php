<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    @include('payslips.partials.robots-noindex')
    <style>
        @include('payslips.partials.slip-styles')
    </style>
</head>
<body>
    @foreach($slips as $slip)
        @include('payslips.partials.slip-body', ['slip' => $slip])
    @endforeach
</body>
</html>
