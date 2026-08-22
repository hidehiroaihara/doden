import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';

interface Card {
    label: string;
    route: string;
    params?: Record<string, string>;
    icon: string;
}

interface Category {
    title: string;
    cards: Card[];
}

interface Props {
    currentYear: number;
    categories: Category[];
}

export default function ReportsIndex({ categories }: Props) {
    return (
        <AdminLayout header={<h2 className="text-xl font-bold text-gray-800">帳票一覧</h2>}>
            <Head title="帳票一覧" />

            <div className="px-4 py-6 sm:p-6">
                <div className="mx-auto max-w-5xl space-y-8">
                    {categories.map((cat) => (
                        <section key={cat.title}>
                            <h3 className="mb-3 border-b border-gray-200 pb-2 text-sm font-bold text-gray-700">{cat.title}</h3>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {cat.cards.map((card, i) => (
                                    <Link
                                        key={`${card.route}-${i}`}
                                        href={route(card.route, card.params)}
                                        className="group flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm transition hover:border-teal-300 hover:bg-teal-50/40"
                                    >
                                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-600 group-hover:bg-teal-100">
                                            <i className={`fa-solid fa-${card.icon}`} />
                                        </span>
                                        <span className="text-sm font-semibold text-teal-700">{card.label}</span>
                                        <i className="fa-solid fa-chevron-right ml-auto text-xs text-gray-300 group-hover:text-teal-400" />
                                    </Link>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
