<?php

/**
 * 所得税徴収高計算書の座標レイアウト。
 *
 * image_fields: MF 背景 JPG（1024×535px）上のピクセル座標。
 * levied-income-tax-statement-bg.jpg を画像解析してキャリブレーション（2026-08）。
 */
return [
    /** MF 背景画像（中連・領収済通知書 1 枚） */
    'image_template' => [
        'normal' => 'forms/income-tax/levied-income-tax-statement-bg.jpg',
        'special' => 'forms/income-tax/levied-income-tax-statement-bg.jpg',
        'width_px' => 1024,
        'height_px' => 535,
        'page_width_mm' => 210.0,
    ],

    /** 旧: 国税庁 PDF（3 連複写が必要な場合の予備） */
    'templates' => [
        'normal' => 'forms/income-tax/keisansho-01-general-unlocked.pdf',
        'special' => 'forms/income-tax/keisansho-02-special-unlocked.pdf',
    ],

    /** 背景画像への転記（px / 1024×535 基準） */
    'image_fields' => [
        'corporate_number' => [
            'y' => 70,
            'xs' => [64, 79, 92, 104, 117],
        ],
        'reiwa' => [
            'y' => 70,
            'xs' => [234, 252],
        ],
        'tax_office_sign' => [
            'y' => 70,
            'xs' => [316, 333, 350],
        ],
        'tax_office_number' => [
            'y' => 70,
            'xs' => [401, 418, 435],
        ],
        'due_era' => [
            'y' => 42,
            'xs' => [892, 910],
        ],
        'due_month' => [
            'y' => 62,
            'xs' => [892, 910],
        ],
        'row01' => ['y' => 177],
        'row02' => ['y' => 197],
        'row_xs' => [
            'payment' => [235, 256, 279, 302, 325, 345],
            'count' => [372, 395, 417],
            'amount' => [442, 466, 489, 513, 536, 559, 583, 606, 628],
            'tax' => [634, 653, 676, 699, 718, 727, 746, 769, 792],
        ],
        'principal_tax' => [
            'y' => 261,
            'xs' => [653, 676, 699, 718, 727, 746, 764, 774, 791],
        ],
        'total_tax' => [
            'y' => 267,
            'xs' => [653, 676, 699, 718, 727, 746, 764, 774, 791],
        ],
        'payer_address' => [
            'y' => 345,
            'x' => 90,
            'font_size_pt' => 8,
        ],
        'payer_name' => [
            'y' => 368,
            'x' => 90,
            'font_size_pt' => 8.5,
            'font_weight' => 700,
        ],
        'payer_phone' => [
            'y' => 336,
            'x' => 250,
            'font_size_pt' => 8,
        ],
    ],

    'digit' => [
        'width_px' => 16,
        'height_px' => 20,
        'font_size_pt' => 10,
    ],

    /** 旧 mm 座標（PDF 3 連用・未使用） */
    'slip_tops' => [0, 98.9, 198.0],
    'preview_slip_top' => 98.9,
    'preview_slip_height' => 99.0,
    'backgrounds' => [
        'normal' => 'forms/income-tax/keisansho-01-general.png',
        'special' => 'forms/income-tax/keisansho-02-special.png',
    ],
    'row_xs' => [],
    'fields' => [],
];
