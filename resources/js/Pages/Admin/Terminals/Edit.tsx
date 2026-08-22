import AdminLayout from '@/Layouts/AdminLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

interface Terminal {
    id: number;
    name: string;
    terminal_id: string;
    terminal_key: string;
    is_active: boolean;
    description: string | null;
}

interface Props {
    terminal: Terminal;
}

function startUrlForDisplay(terminal: Terminal, showKey: boolean): string {
    const base = route('home');
    const sep = base.includes('?') ? '&' : '?';
    const keyPart = showKey ? encodeURIComponent(terminal.terminal_key) : '••••••••';
    return `${base}${sep}terminal_id=${encodeURIComponent(terminal.terminal_id)}&terminal_key=${keyPart}`;
}

function startUrlForClipboard(terminal: Terminal): string {
    const base = route('home');
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}terminal_id=${encodeURIComponent(terminal.terminal_id)}&terminal_key=${encodeURIComponent(terminal.terminal_key)}`;
}

export default function TerminalEdit({ terminal }: Props) {
    const [showKey, setShowKey] = useState(false);
    const [copyFeedback, setCopyFeedback] = useState<string | null>(null);
    const copyFeedbackTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const { data, setData, patch, processing, errors } = useForm({
        name: terminal.name,
        description: terminal.description ?? '',
        is_active: terminal.is_active,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('admin.terminals.update', terminal.id));
    };

    const handleReissue = () => {
        if (confirm('terminal_key を再発行しますか？\n現在のキーは無効になります（タブレットの設定URLも更新が必要です）')) {
            router.post(route('admin.terminals.reissue-key', terminal.id));
        }
    };

    const handleCopyStartUrl = async () => {
        const text = startUrlForClipboard(terminal);
        try {
            await navigator.clipboard.writeText(text);
            if (copyFeedbackTimerRef.current) {
                clearTimeout(copyFeedbackTimerRef.current);
            }
            setCopyFeedback('Start URL をコピーしました');
            copyFeedbackTimerRef.current = setTimeout(() => {
                setCopyFeedback(null);
                copyFeedbackTimerRef.current = null;
            }, 2500);
        } catch {
            setCopyFeedback('コピーに失敗しました（HTTPS または localhost での利用、ブラウザの権限を確認してください）');
        }
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">端末を編集</h2>}>
            <Head title="端末を編集" />

            <div className="mx-auto max-w-xl px-4 py-8 sm:px-6">
                <div className="mb-6">
                    <Link href={route('admin.terminals.index')} className="text-sm text-gray-500 hover:text-gray-700">
                        &larr; 端末一覧に戻る
                    </Link>
                </div>

                {/* terminal_id / terminal_key 表示 */}
                <div className="mb-6 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100 space-y-4">
                    <h3 className="text-sm font-bold text-gray-700">端末情報（変更不可）</h3>

                    <div>
                        <p className="text-xs text-gray-400 mb-1">terminal_id</p>
                        <code className="block rounded-lg bg-gray-50 px-4 py-2.5 text-sm font-mono text-gray-800 select-all">
                            {terminal.terminal_id}
                        </code>
                    </div>

                    <div>
                        <p className="text-xs text-gray-400 mb-1">terminal_key</p>
                        <div className="flex items-center gap-2">
                            <code className={`flex-1 rounded-lg bg-gray-50 px-4 py-2.5 text-sm font-mono text-gray-800 select-all break-all ${showKey ? '' : 'filter blur-[3px] select-none pointer-events-none'}`}>
                                {terminal.terminal_key}
                            </code>
                            <button
                                type="button"
                                onClick={() => setShowKey(!showKey)}
                                className="shrink-0 rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-600 hover:bg-gray-50 transition"
                            >
                                {showKey ? '隠す' : '表示'}
                            </button>
                        </div>
                    </div>

                    <div className="rounded-lg bg-gray-50 p-3 space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-xs font-semibold text-gray-600">
                                タブレット Start URL 例（打刻トップ＝ユーザー一覧）
                            </p>
                            <button
                                type="button"
                                onClick={handleCopyStartUrl}
                                className="shrink-0 rounded-lg border border-teal-200 bg-white px-3 py-1.5 text-xs font-semibold text-teal-700 shadow-sm hover:bg-teal-50 transition"
                            >
                                URLをコピー
                            </button>
                        </div>
                        <code className="block text-xs text-gray-500 break-all">
                            {startUrlForDisplay(terminal, showKey)}
                        </code>
                        {copyFeedback && (
                            <p className="text-xs font-medium text-teal-700">{copyFeedback}</p>
                        )}
                        <p className="text-[11px] text-gray-400 leading-relaxed">
                            個人の打刻画面へ直接行く場合は{' '}
                            <code className="text-gray-500">/punch/ユーザーID</code>
                            {' '}です（<code className="text-gray-500">/punch</code> だけのURLはルートが無く 404 になります）。
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={handleReissue}
                        className="rounded-lg border border-orange-200 px-4 py-2 text-xs font-medium text-orange-600 hover:bg-orange-50 transition"
                    >
                        terminal_key を再発行する
                    </button>
                </div>

                {/* 編集フォーム */}
                <form onSubmit={submit} className="space-y-6 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                    <div>
                        <InputLabel htmlFor="name" value="端末名（管理用）" />
                        <TextInput
                            id="name"
                            className="mt-1 block w-full"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="設置場所・説明（任意）" />
                        <TextInput
                            id="description"
                            className="mt-1 block w-full"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    <div className="flex items-center gap-3">
                        <input
                            id="is_active"
                            type="checkbox"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                        />
                        <InputLabel htmlFor="is_active" value="有効にする" className="mb-0" />
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route('admin.terminals.index')}
                            className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 transition"
                        >
                            キャンセル
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50 transition"
                        >
                            {processing ? '保存中...' : '保存する'}
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
