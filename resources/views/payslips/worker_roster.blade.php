<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    @include('payslips.partials.robots-noindex')
    <style>
        @include('payslips.partials.pdf-fonts')
        body { margin: 0; padding: 32px; color: #1f2937; font-size: 11px; }
        .doc-title { text-align: center; font-size: 20px; font-weight: bold; letter-spacing: 8px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #4b5563; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; width: 130px; font-weight: bold; }
        .history { height: 120px; }
        .sub { width: 90px; background: #f9fafb; }
    </style>
</head>
<body>
    <div class="doc-title">労働者名簿</div>

    <table>
        <tr>
            <th>氏名</th>
            <td colspan="3">{{ $name }}</td>
        </tr>
        <tr>
            <th>生年月日</th>
            <td>{{ $birthDate ?? '' }}</td>
            <th style="width:90px">性別</th>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <th>住所</th>
            <td colspan="3">〒{{ $postalCode ?? '' }}　{{ $address ?? '' }}</td>
        </tr>
        <tr>
            <th>従事する業務の種類</th>
            <td colspan="3">&nbsp;</td>
        </tr>
        <tr>
            <th>雇入年月日</th>
            <td colspan="3">{{ $hireDate ?? '' }}</td>
        </tr>
        <tr>
            <th rowspan="3">退職又は死亡</th>
            <td class="sub">年月日</td>
            <td colspan="2">&nbsp;</td>
        </tr>
        <tr>
            <td class="sub">区分</td>
            <td colspan="2">{{ $isActive ? '' : '退職' }}</td>
        </tr>
        <tr>
            <td class="sub">事由</td>
            <td colspan="2">&nbsp;</td>
        </tr>
        <tr>
            <th>履歴</th>
            <td colspan="3" class="history">&nbsp;</td>
        </tr>
    </table>
</body>
</html>
