{{--
    DomPDF は太字用フォントが無いと Helvetica-Bold に落ち、日本語が "?" になる。
    IPAexゴシックに bold ファイルは無いので、同一 TTF を normal / bold / italic に割り当てる。
--}}
@font-face {
    font-family: 'ipaexg';
    font-style: normal;
    font-weight: normal;
    src: url("{{ storage_path('fonts/ipaexg.ttf') }}") format('truetype');
}
@font-face {
    font-family: 'ipaexg';
    font-style: normal;
    font-weight: bold;
    src: url("{{ storage_path('fonts/ipaexg.ttf') }}") format('truetype');
}
@font-face {
    font-family: 'ipaexg';
    font-style: italic;
    font-weight: normal;
    src: url("{{ storage_path('fonts/ipaexg.ttf') }}") format('truetype');
}
@font-face {
    font-family: 'ipaexg';
    font-style: italic;
    font-weight: bold;
    src: url("{{ storage_path('fonts/ipaexg.ttf') }}") format('truetype');
}
* { font-family: 'ipaexg', sans-serif; }
