import AdminLayout from '@/Layouts/AdminLayout';
import { useAdminPermission } from '@/hooks/useAdminPermission';
import { Head, Link, router } from '@inertiajs/react';

interface Terminal {
    id: number;
    name: string;
    terminal_id: string;
    terminal_key: string;
    is_active: boolean;
    description: string | null;
    created_at: string;
}

interface Props {
    terminals: Terminal[];
}

function formatDate(dateStr: string): string {
    const d = new Date(dateStr);
    return `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`;
}

function punchScreenUrl(terminal: Terminal): string {
    const qs = new URLSearchParams({
        terminal_id: terminal.terminal_id,
        terminal_key: terminal.terminal_key,
    }).toString();
    return `${route('home')}?${qs}`;
}

function openPunchScreen(terminal: Terminal): void {
    window.open(punchScreenUrl(terminal), '_blank', 'noopener,noreferrer');
}

function StatusBadge({ active }: { active: boolean }) {
    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ${
            active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
        }`}>
            <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-green-500' : 'bg-gray-400'}`} />
            {active ? '有効' : '無効'}
        </span>
    );
}

export default function TerminalsIndex({ terminals }: Props) {
    const canWrite = useAdminPermission('terminals');

    const handleDelete = (terminal: Terminal) => {
        if (confirm(`「${terminal.name}」を削除しますか？この操作は取り消せません。`)) {
            router.delete(route('admin.terminals.destroy', terminal.id));
        }
    };

    const handleToggleActive = (terminal: Terminal) => {
        if (confirm(`「${terminal.name}」の${terminal.is_active ? '無効化' : '有効化'}を行いますか？`)) {
            router.patch(route('admin.terminals.update', terminal.id), {
                name: terminal.name,
                description: terminal.description,
                is_active: !terminal.is_active,
            });
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">端末管理</h2>}>
            <Head title="端末管理" />

            <div className="mx-auto max-w-5xl px-4 py-6 sm:py-8 sm:px-6">

                <div className="mb-4 flex items-center justify-between gap-3">
                    <p className="text-sm text-gray-500">登録済み端末: {terminals.length}台</p>
                    {canWrite && (
                        <Link
                            href={route('admin.terminals.create')}
                            className="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 transition"
                        >
                            + 端末を追加
                        </Link>
                    )}
                </div>

                {terminals.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-gray-200 py-16 text-center text-gray-400">
                        <p className="text-sm">端末が登録されていません</p>
                        {canWrite && (
                            <Link href={route('admin.terminals.create')} className="mt-3 inline-block text-sm text-teal-600 hover:underline">
                                最初の端末を追加する
                            </Link>
                        )}
                    </div>
                ) : (
                    <>
                        {/* モバイル: カードリスト */}
                        <div className="space-y-3 sm:hidden">
                            {terminals.map((terminal) => (
                                <div key={terminal.id} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="font-semibold text-gray-800">{terminal.name}</p>
                                            <code className="text-xs text-gray-400">{terminal.terminal_id}</code>
                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                <StatusBadge active={terminal.is_active} />
                                                {terminal.description && (
                                                    <span className="text-xs text-gray-400">{terminal.description}</span>
                                                )}
                                            </div>
                                            <p className="mt-1 text-[11px] text-gray-300">{formatDate(terminal.created_at)}</p>
                                        </div>
                                        <div className="flex shrink-0 flex-col gap-1.5">
                                            <button
                                                type="button"
                                                onClick={() => openPunchScreen(terminal)}
                                                disabled={!terminal.is_active}
                                                title={!terminal.is_active ? '無効な端末では開けません' : '打刻のトップ画面を新しいタブで開きます'}
                                                className="whitespace-nowrap rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-center text-xs font-medium text-teal-700 hover:bg-teal-100 transition disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-teal-50"
                                            >
                                                打刻画面を開く
                                            </button>
                                            {canWrite && (
                                                <>
                                                <button
                                                    onClick={() => handleToggleActive(terminal)}
                                                    className="whitespace-nowrap rounded-lg border border-gray-200 px-3 py-1.5 text-center text-xs font-medium text-gray-600 hover:bg-gray-50 transition"
                                                >
                                                    {terminal.is_active ? '無効化' : '有効化'}
                                                </button>
                                                <Link
                                                    href={route('admin.terminals.edit', terminal.id)}
                                                    className="whitespace-nowrap rounded-lg border border-blue-200 px-3 py-1.5 text-center text-xs font-medium text-blue-600 hover:bg-blue-50 transition"
                                                >
                                                    編集
                                                </Link>
                                                <button
                                                    onClick={() => handleDelete(terminal)}
                                                    className="whitespace-nowrap rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                                >
                                                    削除
                                                </button>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* PC: テーブル */}
                        <div className="hidden sm:block overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        <th className="px-5 py-3">端末名 / terminal_id</th>
                                        <th className="px-5 py-3">説明</th>
                                        <th className="px-5 py-3">ステータス</th>
                                        <th className="px-5 py-3">登録日</th>
                                        <th className="px-5 py-3 text-right">打刻画面 / 操作</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {terminals.map((terminal) => (
                                        <tr key={terminal.id} className="hover:bg-gray-50/50 transition">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold text-gray-800">{terminal.name}</p>
                                                <code className="text-xs text-gray-400">{terminal.terminal_id}</code>
                                            </td>
                                            <td className="px-5 py-4 text-gray-500">
                                                {terminal.description || <span className="text-gray-300">—</span>}
                                            </td>
                                            <td className="px-5 py-4">
                                                <StatusBadge active={terminal.is_active} />
                                            </td>
                                            <td className="px-5 py-4 text-gray-400">{formatDate(terminal.created_at)}</td>
                                            <td className="px-5 py-4 text-right">
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => openPunchScreen(terminal)}
                                                        disabled={!terminal.is_active}
                                                        title={!terminal.is_active ? '無効な端末では開けません' : '打刻のトップ画面を新しいタブで開きます'}
                                                        className="whitespace-nowrap rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-medium text-teal-700 hover:bg-teal-100 transition disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-teal-50"
                                                    >
                                                        打刻画面を開く
                                                    </button>
                                                    {canWrite && (
                                                        <>
                                                        <button
                                                            onClick={() => handleToggleActive(terminal)}
                                                            className="whitespace-nowrap rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 transition"
                                                        >
                                                            {terminal.is_active ? '無効化' : '有効化'}
                                                        </button>
                                                        <Link
                                                            href={route('admin.terminals.edit', terminal.id)}
                                                            className="whitespace-nowrap rounded-lg border border-blue-200 px-3 py-1.5 text-xs font-medium text-blue-600 hover:bg-blue-50 transition"
                                                        >
                                                            編集
                                                        </Link>
                                                        <button
                                                            onClick={() => handleDelete(terminal)}
                                                            className="whitespace-nowrap rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                                        >
                                                            削除
                                                        </button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
