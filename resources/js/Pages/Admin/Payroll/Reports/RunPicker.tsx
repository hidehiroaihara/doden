import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';

interface Run {
    id: number;
    period_key: string;
    business_location: string | null;
    status: string;
}

interface Props {
    title: string;
    description: string;
    routeName: string;
    runs: Run[];
}

export default function RunPicker({ title, description, routeName, runs }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">{title}</h2>}>
            <Head title={title} />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-2xl space-y-5">
                    <div className="flex items-center gap-3">
                        <Link href={route('admin.payroll.reports.index')}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-50">
                            <i className="fa-solid fa-arrow-left" />
                        </Link>
                        <p className="text-sm text-gray-500">{description}</p>
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                        <ul className="divide-y divide-gray-100">
                            {runs.map((run) => (
                                <li key={run.id}>
                                    <Link href={route(routeName, run.id)}
                                        className="flex items-center justify-between px-5 py-3.5 transition hover:bg-teal-50/40">
                                        <div>
                                            <div className="text-sm font-bold text-gray-800">{run.period_key}</div>
                                            <div className="text-xs text-gray-400">{run.business_location ?? '全事業所'}</div>
                                        </div>
                                        <i className="fa-solid fa-chevron-right text-xs text-gray-300" />
                                    </Link>
                                </li>
                            ))}
                            {runs.length === 0 && (
                                <li className="px-5 py-12 text-center text-sm text-gray-400">給与バッチがありません。</li>
                            )}
                        </ul>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
