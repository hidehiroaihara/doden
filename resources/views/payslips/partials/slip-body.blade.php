{{-- 給与明細1件の本体（勤怠｜支給｜控除｜当月支払）。$slip = PayslipPdfService::viewData() --}}
<div class="slip">
    <table class="ps-head">
        <tr>
            <td>
                <div class="ps-title">{{ $slip['title'] }}</div>
                <div class="ps-meta">
                    @if($slip['paymentDate'])<div>支給日：{{ $slip['paymentDate'] }}</div>@endif
                    @if($slip['targetPeriod'])<div>対象期間：{{ $slip['targetPeriod'] }}</div>@endif
                </div>
                <div class="ps-name">{{ $slip['userName'] }} 様</div>
                <div class="ps-sub">
                    @if($slip['businessLocation'])<div>所属：{{ $slip['businessLocation'] }}</div>@endif
                    @if(!is_null($slip['department']))<div>部門：{{ $slip['department'] }}</div>@endif
                    @if($slip['employeeNo'])<div>従業員番号：{{ $slip['employeeNo'] }}</div>@endif
                </div>
            </td>
            <td class="ps-head-right">
                <div class="ps-seal"></div>
            </td>
        </tr>
    </table>

    <table class="ps-net-row">
        <tr>
            <td class="ps-net-cell">
                <span class="ps-net-label">差引支給額</span>
                <span class="ps-net-value">{{ number_format($slip['netPay']) }}</span>
                <span class="ps-net-yen">円</span>
            </td>
        </tr>
    </table>

    <hr class="ps-divider">

    <table class="cols">
        <tr>
            {{-- 勤怠 --}}
            <td class="col">
                <div class="col-panel" style="height: {{ $slip['columnMinHeight'] }}px">
                    <div class="col-head">勤怠</div>
                    <div class="col-body">
                        @if($slip['showAttendance'])
                            <table class="items">
                                @forelse($slip['attendances'] as $a)
                                    <tr class="{{ $loop->index % 2 === 0 ? 'alt' : '' }}"><td class="name">{{ $a['name'] }}</td><td class="num">{{ $a['value'] }}</td></tr>
                                @empty
                                    <tr class="alt"><td class="name">—</td><td class="num"></td></tr>
                                @endforelse
                            </table>
                            <div class="col-gap" style="height: {{ $slip['attSpacerHeight'] }}px"></div>
                        @endif
                    </div>
                </div>
            </td>

            {{-- 支給 --}}
            <td class="col">
                <div class="col-panel" style="height: {{ $slip['columnMinHeight'] }}px">
                    <div class="col-head">支給</div>
                    <div class="col-body">
                        <table class="items">
                            @foreach($slip['earnings'] as $e)
                                <tr class="{{ $loop->index % 2 === 0 ? 'alt' : '' }}"><td class="name">{{ $e['name'] }}</td><td class="num">{{ number_format($e['amount']) }}</td></tr>
                            @endforeach
                        </table>
                        <div class="col-gap" style="height: {{ $slip['earnSpacerHeight'] }}px"></div>
                        <table class="items items-total">
                            <tr class="total"><td class="name">支給合計</td><td class="num">{{ number_format($slip['totalEarnings']) }}</td></tr>
                        </table>
                    </div>
                </div>
            </td>

            {{-- 控除 --}}
            <td class="col">
                <div class="col-panel" style="height: {{ $slip['columnMinHeight'] }}px">
                    <div class="col-head">控除</div>
                    <div class="col-body">
                        <table class="items">
                            @foreach($slip['deductions'] as $d)
                                <tr class="{{ $loop->index % 2 === 0 ? 'alt' : '' }}"><td class="name">{{ $d['name'] }}</td><td class="num">{{ number_format($d['amount']) }}</td></tr>
                            @endforeach
                        </table>
                        <div class="col-gap" style="height: {{ $slip['dedSpacerHeight'] }}px"></div>
                        <table class="items items-total">
                            <tr class="total"><td class="name">控除合計</td><td class="num">{{ number_format($slip['totalDeductions']) }}</td></tr>
                        </table>
                    </div>
                </div>
            </td>

            {{-- 当月支払 ＋ 給与関連情報 --}}
            <td class="col">
                <div class="col-panel" style="height: {{ $slip['columnMinHeight'] }}px">
                    <div class="col-head">当月支払</div>
                    <div class="col-body">
                        <table class="items">
                            @foreach($slip['payments'] as $i => $p)
                                <tr class="{{ $i % 2 === 0 ? 'alt' : '' }}"><td class="name">{{ $p['name'] }}</td><td class="num">{{ number_format($p['amount']) }}</td></tr>
                            @endforeach
                        </table>
                        <div class="col-gap" style="height: {{ $slip['paySpacerHeight'] }}px"></div>
                    </div>
                </div>
                @if(!empty($slip['relatedInfo']))
                    <div class="col-head">給与関連情報</div>
                    <table class="items">
                        @foreach($slip['relatedInfo'] as $i => $r)
                            <tr class="{{ $i % 2 === 0 ? 'alt' : '' }}"><td class="name">{{ $r['label'] }}</td><td class="num">{{ $r['value'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
            </td>
        </tr>
    </table>

    @if(!empty($slip['ytd']))
        <table class="extra">
            <tr>
                <td></td>
                <td>
                    <div class="col-head" style="border:1px solid #c3d0e0; border-bottom:none;">本年累計</div>
                    <table class="items" style="border:1px solid #c3d0e0;">
                        <tr class="alt"><td class="name">課税支給額</td><td class="num">{{ number_format($slip['ytd']['taxable']) }}</td></tr>
                        <tr><td class="name">社会保険料</td><td class="num">{{ number_format($slip['ytd']['social']) }}</td></tr>
                        <tr class="alt"><td class="name">所得税</td><td class="num">{{ number_format($slip['ytd']['income_tax']) }}</td></tr>
                    </table>
                </td>
                <td></td><td></td>
            </tr>
        </table>
    @endif

    @if(!empty($slip['remarks']))
        <div class="remarks">
            備考
            <div class="box">{{ $slip['remarks'] }}</div>
        </div>
    @endif
</div>
