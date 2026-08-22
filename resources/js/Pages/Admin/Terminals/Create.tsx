import AdminLayout from '@/Layouts/AdminLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function TerminalCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        terminal_id: '',
        description: '',
        is_active: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.terminals.store'));
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">端末を追加</h2>}>
            <Head title="端末を追加" />

            <div className="mx-auto max-w-xl px-4 py-8 sm:px-6">
                <div className="mb-6">
                    <Link href={route('admin.terminals.index')} className="text-sm text-gray-500 hover:text-gray-700">
                        &larr; 端末一覧に戻る
                    </Link>
                </div>

                <form onSubmit={submit} className="space-y-6 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                    <div>
                        <InputLabel htmlFor="name" value="端末名（管理用）" />
                        <TextInput
                            id="name"
                            className="mt-1 block w-full"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="例: 受付タブレット"
                            required
                        />
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="terminal_id" value="terminal_id" />
                        <TextInput
                            id="terminal_id"
                            className="mt-1 block w-full font-mono"
                            value={data.terminal_id}
                            onChange={(e) => setData('terminal_id', e.target.value)}
                            placeholder="例: tablet01"
                            required
                        />
                        <p className="mt-1 text-xs text-gray-400">半角英数・ハイフン・アンダースコアのみ使用できます（一意）</p>
                        <InputError message={errors.terminal_id} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="設置場所・説明（任意）" />
                        <TextInput
                            id="description"
                            className="mt-1 block w-full"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="例: 1F エントランス"
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

                    <p className="rounded-lg bg-blue-50 px-4 py-3 text-xs text-blue-600">
                        terminal_key は自動生成されます。作成後に編集画面から確認・再発行できます。
                    </p>

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
                            {processing ? '保存中...' : '追加する'}
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
