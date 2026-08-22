<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <style>
        @include('payslips.partials.slip-styles')
    </style>
</head>
<body>
    @include('payslips.partials.slip-body', ['slip' => $slip])
</body>
</html>
