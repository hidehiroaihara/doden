import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Manager {
    id: number;
    name: string;
    email: string;
    role: number;
    permissions: Record<string, string> | null;
    created_at: string;
}

interface Props {
    managers: Manager[];
}

const SECTION_LABELS: Record<string, string> = {
    dashboard:   'ダッシュボード',
    users:       'ユーザー管理',
    attendances: '打刻管理',
    terminals:   '端末管理',
    settings:    '勤怠設定',
};

const LEVEL_LABELS: Record<string, { label: string; color: string }> = {
    none:  { label: 'なし',       color: 'bg-gray-100 text-gray-400' },
    read:  { label: '閲覧',       color: 'bg-blue-100 text-blue-700' },
    write: { label: '閲覧＋編集', color: 'bg-teal-100 text-teal-700' },
};

function PermissionBadges({ permissions }: { permissions: Record<string, string> | null }) {
    if (!permissions) return <span className="text-gray-300 text-xs">—</span>;
    const active = Object.entries(SECTION_LABELS).filter(
        ([section]) => (permissions[section] ?? 'none') !== 'none',
    );
    if (active.length === 0) return <span className="text-xs text-gray-300">なし</span>;
    return (
        <div className="flex flex-wrap gap-1">
            {active.map(([section, sectionLabel]) => {
                const level = permissions[section] ?? 'none';
                const { label, color } = LEVEL_LABELS[level] ?? LEVEL_LABELS.none;
                return (
                    <span key={section} className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${color}`}>
                        {sectionLabel}: {label}
                    </span>
                );
            })}
        </div>
    );
}

function RoleBadge({ role }: { role: number }) {
    return role === 1 ? (
        <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700">
            <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
            </svg>
            スーパー管理者
        </span>
    ) : (
        <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
            管理者
        </span>
    );
}

export default function ManagersIndex({ managers }: Props) {
    const canWrite = useAdminPermission('admins');
    const { props } = usePage<any>();
    const auth = props.auth as any;
    const currentAdminId = auth?.admin?.id;

    const handleDelete = (manager: Manager) => {
        if (confirm(`「${manager.name}」を削除しますか？この操作は取り消せません。`)) {
            router.delete(route('admin.managers.destroy', manager.id));
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">管理ユーザー管理</h2>}>
            <Head title="管理ユーザー管理" />

            <div className="mx-auto max-w-5xl px-4 py-6 sm:py-8 sm:px-6">

                <div className="mb-4 flex items-center justify-between gap-3">
                    <p className="text-sm text-gray-500">{managers.length}名</p>
                    {canWrite && (
                        <Link
                            href={route('admin.managers.create')}
                            className="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 transition"
                        >
                            + 管理ユーザーを追加
                        </Link>
                    )}
                </div>

                {/* モバイル: カードリスト */}
                <div className="space-y-3 sm:hidden">
                    {managers.map((manager) => (
                        <div key={manager.id} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-semibold text-gray-800 truncate">{manager.name}</p>
                                    <p className="text-xs text-gray-400 truncate">{manager.email}</p>
                                    <div className="mt-2">
                                        <RoleBadge role={manager.role} />
                                    </div>
                                </div>
                                {canWrite && manager.role !== 1 && (
                                    <div className="flex shrink-0 flex-col gap-1.5">
                                        <Link
                                            href={route('admin.managers.edit', manager.id)}
                                            className="rounded-lg border border-blue-200 px-3 py-1.5 text-center text-xs font-medium text-blue-600 hover:bg-blue-50 transition"
                                        >
                                            編集
                                        </Link>
                                        {manager.id !== currentAdminId && (
                                            <button
                                                onClick={() => handleDelete(manager)}
                                                className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                            >
                                                削除
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                            {manager.role !== 1 && (
                                <div className="mt-3 border-t border-gray-50 pt-3">
                                    <p className="mb-1 text-[11px] font-semibold text-gray-400">アクセス権限</p>
                                    <PermissionBadges permissions={manager.permissions} />
                                </div>
                            )}
                        </div>
                    ))}
                    {managers.length === 0 && (
                        <div className="rounded-2xl border border-dashed border-gray-200 py-12 text-center text-sm text-gray-400">
                            管理ユーザーが登録されていません
                        </div>
                    )}
                </div>

                {/* PC: テーブル */}
                <div className="hidden sm:block overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-100 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">
                                <th className="px-5 py-3">名前 / メール</th>
                                <th className="px-5 py-3">種別</th>
                                <th className="px-5 py-3">アクセス権限</th>
                                <th className="px-5 py-3 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {managers.map((manager) => (
                                <tr key={manager.id} className="hover:bg-gray-50/50 transition">
                                    <td className="px-5 py-4">
                                        <p className="font-semibold text-gray-800">{manager.name}</p>
                                        <p className="text-xs text-gray-400">{manager.email}</p>
                                    </td>
                                    <td className="px-5 py-4">
                                        <RoleBadge role={manager.role} />
                                    </td>
                                    <td className="px-5 py-4">
                                        {manager.role === 1 ? (
                                            <span className="text-xs text-gray-400">全権限</span>
                                        ) : (
                                            <PermissionBadges permissions={manager.permissions} />
                                        )}
                                    </td>
                                    <td className="px-5 py-4 text-right">
                                        {canWrite && manager.role !== 1 && (
                                            <div className="flex items-center justify-end gap-2">
                                                <Link
                                                    href={route('admin.managers.edit', manager.id)}
                                                    className="whitespace-nowrap rounded-lg border border-blue-200 px-3 py-1.5 text-xs font-medium text-blue-600 hover:bg-blue-50 transition"
                                                >
                                                    編集
                                                </Link>
                                                {manager.id !== currentAdminId && (
                                                    <button
                                                        onClick={() => handleDelete(manager)}
                                                        className="whitespace-nowrap rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                                    >
                                                        削除
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
