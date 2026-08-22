import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * 月締め期間ヘルパー（フロント側の単一ソース）
 *
 *   締め日 D の場合、monthKey 'YYYY-MM' の期間は (前月 D+1) 〜 (Y-M D)。
 *   D が NULL もしくは その月に存在しない日は月末で丸める。
 *   D=NULL は従来どおり Y-M の月初〜月末。
 *
 *   バックエンド: app/Services/MonthPeriod.php と同等のロジック。
 */

const pad = (n: number) => String(n).padStart(2, '0');

const daysInMonth = (year: number, month1: number) =>
    new Date(year, month1, 0).getDate();

const safeDay = (year: number, month1: number, day: number) =>
    Math.min(day, daysInMonth(year, month1));

const fmt = (year: number, month1: number, day: number) =>
    `${year}-${pad(month1)}-${pad(day)}`;

/** Asia/Tokyo 等のローカル日付文字列を返す */
const todayLocal = (): { y: number; m: number; d: number } => {
    const now = new Date();
    return { y: now.getFullYear(), m: now.getMonth() + 1, d: now.getDate() };
};

const parseMonthKey = (monthKey: string): { y: number; m: number } => {
    const [y, m] = monthKey.split('-').map((s) => parseInt(s, 10));
    return { y, m };
};

const addMonths = (year: number, month1: number, delta: number) => {
    const idx = (year * 12 + (month1 - 1)) + delta;
    return { y: Math.floor(idx / 12), m: (idx % 12) + 1 };
};

export interface MonthRange {
    /** YYYY-MM-DD */
    from: string;
    /** YYYY-MM-DD */
    to: string;
}

/**
 * monthKey 'YYYY-MM' から期間 {from, to} を計算する。
 * closingDay が null の場合は単純に月初〜月末。
 */
export const resolveMonthRange = (
    monthKey: string,
    closingDay: number | null | undefined,
): MonthRange => {
    const { y, m } = parseMonthKey(monthKey);

    if (!closingDay) {
        const last = daysInMonth(y, m);
        return { from: fmt(y, m, 1), to: fmt(y, m, last) };
    }

    const endDay = safeDay(y, m, closingDay);
    const prev = addMonths(y, m, -1);
    const startBase = safeDay(prev.y, prev.m, closingDay);
    const startBaseDate = new Date(prev.y, prev.m - 1, startBase);
    startBaseDate.setDate(startBaseDate.getDate() + 1);

    return {
        from: fmt(
            startBaseDate.getFullYear(),
            startBaseDate.getMonth() + 1,
            startBaseDate.getDate(),
        ),
        to: fmt(y, m, endDay),
    };
};

/**
 * 今日が属する monthKey を返す。
 * 締め日 D が当日 < D ならその月、超えていれば翌月キー。
 */
export const currentMonthKey = (
    closingDay: number | null | undefined,
    today?: { y: number; m: number; d: number },
): string => {
    const t = today ?? todayLocal();
    if (!closingDay || t.d <= closingDay) {
        return `${t.y}-${pad(t.m)}`;
    }
    const next = addMonths(t.y, t.m, 1);
    return `${next.y}-${pad(next.m)}`;
};

/** monthKey を delta 月だけずらす */
export const shiftMonthKey = (monthKey: string, delta: number): string => {
    const { y, m } = parseMonthKey(monthKey);
    const r = addMonths(y, m, delta);
    return `${r.y}-${pad(r.m)}`;
};

/** "2026年6月（5/21〜6/20）" のようなラベル */
export const monthLabel = (
    monthKey: string,
    closingDay: number | null | undefined,
): string => {
    const { y, m } = parseMonthKey(monthKey);
    const r = resolveMonthRange(monthKey, closingDay);
    const f = new Date(r.from);
    const t = new Date(r.to);
    return `${y}年${m}月（${f.getMonth() + 1}/${f.getDate()}〜${t.getMonth() + 1}/${t.getDate()}）`;
};

/**
 * Inertia 共有 props から closingDay を取得する hook。
 * 値が来ていない場合は null を返す（=月末締め）。
 */
export const useMonthClosingDay = (): number | null => {
    const page = usePage();
    const raw = (page.props as { monthClosingDay?: number | null }).monthClosingDay;
    return raw ?? null;
};

/** monthKey と closingDay から期間 {from,to} を memoize */
export const useMonthRange = (monthKey: string): MonthRange => {
    const closingDay = useMonthClosingDay();
    return useMemo(
        () => resolveMonthRange(monthKey, closingDay),
        [monthKey, closingDay],
    );
};

/**
 * DateDropdown 等の日付プリセット用に「今月 / 先月」を計算する。
 * 締め日 D を考慮し、
 *   今月 → from=現在期間の開始日, to=今日
 *   先月 → from=前期間の開始日, to=前期間の終了日
 */
export const monthPresetRanges = (
    closingDay: number | null | undefined,
): {
    thisMonth: MonthRange;
    lastMonth: MonthRange;
} => {
    const cur = currentMonthKey(closingDay);
    const prev = shiftMonthKey(cur, -1);
    const curRange = resolveMonthRange(cur, closingDay);
    const prevRange = resolveMonthRange(prev, closingDay);

    const t = todayLocal();
    const todayStr = `${t.y}-${pad(t.m)}-${pad(t.d)}`;

    return {
        thisMonth: { from: curRange.from, to: todayStr },
        lastMonth: prevRange,
    };
};

