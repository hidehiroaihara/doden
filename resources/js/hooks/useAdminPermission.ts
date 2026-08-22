import { usePage } from '@inertiajs/react';

type Level = 'none' | 'read' | 'write';

/**
 * 現在ログイン中の管理者が指定セクションの write 権限を持つか返す。
 *
 * 使い方:
 *   const canWrite = useAdminPermission('users');
 *   {canWrite && <button>追加</button>}
 */
export function useAdminPermission(section: string): boolean {
    const { auth } = usePage().props as any;
    const permissions: Record<string, Level> = auth?.admin?.permissions ?? {};
    return (permissions[section] ?? 'none') === 'write';
}
