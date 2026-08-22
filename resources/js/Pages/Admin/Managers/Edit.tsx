import AdminLayout from '@/Layouts/AdminLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Manager {
    id: number;
    name: string;
    email: string;
    role: number;
    permissions: Record<string, string> | null;
}

interface Props {
    manager: Manager;
    sections: string[];
    levels: string[];
}

const SECTION_LABELS: Record<string, string> = {
    dashboard:   'ダッシュボード',
    users:       'ユーザー管理',
    attendances: '打刻管理',
    terminals:   '端末管理',
    settings:    '勤怠設定',
};

export default function ManagerEdit({ manager, sections }: Props) {
    const defaultPermissions = Object.fromEntries(sections.map((s) => [s, 'none']));
    const initialPermissions = { ...defaultPermissions, ...(manager.permissions ?? {}) };

    const { data, setData, patch, processing, errors } = useForm<{
        name: string;
        email: string;
        password: string;
        permissions: Record<string, string>;
    }>({
        name: manager.name,
        email: manager.email,
        password: '',
        permissions: initialPermissions,
    });

    const setPermission = (section: string, level: string) => {
        setData('permissions', { ...data.permissions, [section]: level });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('admin.managers.update', manager.id));
    };

    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">管理ユーザーを編集</h2>}>
            <Head title="管理ユーザーを編集" />

            <div className="mx-auto max-w-xl px-4 py-8 sm:px-6">
                <div className="mb-6">
                    <Link href={route('admin.managers.index')} className="text-sm text-gray-500 hover:text-gray-700">
                        &larr; 一覧に戻る
                    </Link>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    {/* 基本情報 */}
                    <div className="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                        <h3 className="text-sm font-bold text-gray-700">基本情報</h3>

                        <div>
                            <InputLabel htmlFor="name" value="名前" />
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
                            <InputLabel htmlFor="email" value="メールアドレス" />
                            <TextInput
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                            <InputError message={errors.email} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="password" value="パスワード（変更する場合のみ入力）" />
                            <TextInput
                                id="password"
                                type="password"
                                className="mt-1 block w-full"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoComplete="new-password"
                            />
                            <InputError message={errors.password} className="mt-1" />
                        </div>
                    </div>

                    {/* 権限設定 */}
                    <div className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                        <h3 className="mb-4 text-sm font-bold text-gray-700">権限設定</h3>
                        <p className="mb-4 text-xs text-gray-400">各セクションでできることを設定してください。</p>

                        <div className="space-y-3">
                            {sections.map((section) => (
                                <div key={section} className="rounded-xl border border-gray-100 px-4 py-3">
                                    <p className="mb-2 text-sm font-medium text-gray-700">
                                        {SECTION_LABELS[section] ?? section}
                                    </p>
                                    <div className="flex flex-wrap gap-3">
                                        {(['none', 'read', 'write'] as const).map((level) => (
                                            <label key={level} className="flex cursor-pointer items-center gap-1.5">
                                                <input
                                                    type="radio"
                                                    name={`perm_${section}`}
                                                    value={level}
                                                    checked={data.permissions[section] === level}
                                                    onChange={() => setPermission(section, level)}
                                                    className="h-3.5 w-3.5 text-teal-600 focus:ring-teal-500"
                                                />
                                                <span className={`text-xs font-medium ${
                                                    level === 'none'  ? 'text-gray-400' :
                                                    level === 'read'  ? 'text-blue-600' :
                                                    'text-teal-700'
                                                }`}>
                                                    {level === 'none' ? 'なし' : level === 'read' ? '閲覧' : '閲覧＋編集'}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {errors.permissions && (
                            <InputError message={errors.permissions} className="mt-2" />
                        )}
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route('admin.managers.index')}
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
