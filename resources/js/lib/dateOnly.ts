/** Laravel config/app.php の timezone と揃える */
const APP_TIMEZONE = 'Asia/Tokyo';

/**
 * DB の date 型フィールドを暦日 YYYY-MM-DD として取り出す。
 * Laravel の date キャストは JSON 化時に UTC の ISO 文字列（例: 2024-03-31T15:00:00.000000Z）になることがあり、
 * split('T')[0] だけだと JST では前日になってしまう。
 */
export function dateOnlyPart(value?: string | null): string {
    if (!value) return '';
    const trimmed = value.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) return trimmed;

    const parsed = new Date(trimmed);
    if (Number.isNaN(parsed.getTime())) {
        return trimmed.split('T')[0]?.split(' ')[0] ?? '';
    }

    return parsed.toLocaleDateString('sv-SE', { timeZone: APP_TIMEZONE });
}

export function dateOnlyParts(value?: string | null): { y: number; m: number; d: number } | null {
    const part = dateOnlyPart(value);
    if (!part) return null;
    const [y, m, d] = part.split('-').map(Number);
    if (!y || !m || !d) return null;
    return { y, m, d };
}
