/**
 * 休日区分の表示ヘルパー（打刻一覧・月別打刻表の共通ソース）
 *
 * バックエンド: app/Services/HolidayCalendar.php が返す
 *   { "2026-09-21": { type: "prescribed", label: "敬老の日" } }
 * を受け取り、色分けとラベルを統一する。
 */

export type DayType = 'legal' | 'prescribed' | 'weekday';

export interface HolidayInfo {
    type: DayType;
    label: string | null;
}

export type HolidayMap = Record<string, HolidayInfo | undefined>;

export const DAY_TYPE_LABEL: Record<DayType, string> = {
    legal: '法定休日',
    prescribed: '所定休日',
    weekday: '平日',
};

export function isHoliday(info?: HolidayInfo): boolean {
    return info?.type === 'legal' || info?.type === 'prescribed';
}

/** 行・セルの背景色。法定休日=赤系、所定休日=橙系、平日=なし。 */
export function dayTypeRowBg(info?: HolidayInfo): string {
    if (info?.type === 'legal') return 'bg-rose-50/70';
    if (info?.type === 'prescribed') return 'bg-amber-50/70';
    return '';
}

/** 月別打刻表のヘッダ用（本文より濃くして列全体が休日と分かるように）。 */
export function dayTypeHeaderBg(info?: HolidayInfo): string {
    if (info?.type === 'legal') return 'bg-rose-100';
    if (info?.type === 'prescribed') return 'bg-amber-100';
    return '';
}

/** 休日区分バッジのクラス。 */
export function dayTypeBadgeClass(info?: HolidayInfo): string {
    if (info?.type === 'legal') return 'bg-rose-100 text-rose-700';
    if (info?.type === 'prescribed') return 'bg-amber-100 text-amber-700';
    return 'bg-gray-100 text-gray-500';
}

/**
 * 休日区分の短縮ラベル。祝日名があればそれを優先して見せる。
 * 例: 「祝 敬老の日」「所定休日」
 */
export function dayTypeShortLabel(info?: HolidayInfo): string | null {
    if (!info || info.type === 'weekday') return info?.label ? `祝 ${info.label}` : null;
    return info.label ? `祝 ${info.label}` : DAY_TYPE_LABEL[info.type];
}

/** ツールチップ用の説明（区分と祝日名の両方）。 */
export function dayTypeTitle(info?: HolidayInfo): string | undefined {
    if (!info) return undefined;
    const type = DAY_TYPE_LABEL[info.type];
    return info.label ? `${info.label}（${type}）` : type;
}
