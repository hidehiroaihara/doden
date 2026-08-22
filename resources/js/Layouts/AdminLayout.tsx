import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect, useRef, useState } from 'react';

/** 打刻トップの「打刻完了」と同様の右側固定通知（管理画面の flash 用） */
const ADMIN_FLASH_DURATION_MS = 4000;

type PermissionLevel = 'none' | 'read' | 'write';
type Permissions = Record<string, PermissionLevel>;

interface NavItem {
    label: string;
    route: string;
    section: string | null; // null = 常に表示（ダッシュボードなど read 以上）
    icon: ReactNode;
}

const navItems: NavItem[] = [
    {
        label: 'ホーム',
        route: 'admin.dashboard',
        section: 'dashboard',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
            </svg>
        ),
    },
    {
        label: '従業員情報',
        route: 'admin.users.index',
        section: 'users',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
        ),
    },
    {
        label: '店舗管理',
        route: 'admin.departments.index',
        section: 'users',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z" />
            </svg>
        ),
    },
    {
        label: '打刻一覧',
        route: 'admin.attendances.index',
        section: 'attendances',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
    },
    {
        label: '月別打刻表',
        route: 'admin.attendances.monthly',
        section: 'attendances',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
            </svg>
        ),
    },
    {
        label: '月次サマリ',
        route: 'admin.monthly-summary',
        section: 'dashboard',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
            </svg>
        ),
    },
    {
        label: '端末管理',
        route: 'admin.terminals.index',
        section: 'terminals',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 8.25h3m-3 3.75h3m-3 3.75h3" />
            </svg>
        ),
    },
    {
        label: '基本設定',
        route: 'admin.payroll.settings.index',
        section: 'payroll',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
            </svg>
        ),
    },
    {
        label: '給与計算',
        route: 'admin.payroll.runs.index',
        section: 'payroll',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25V13.5zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25V18zm2.498-6.75h.007v.008h-.007v-.008zm0 2.25h.007v.008h-.007V13.5zm0 2.25h.007v.008h-.007v-.008zm0 2.25h.007v.008h-.007V18zm2.504-6.75h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V13.5zm0 2.25h.008v.008h-.008v-.008zM8.25 6h7.5v2.25h-7.5V6zM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 002.25 2.25h10.5a2.25 2.25 0 002.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0012 2.25z" />
            </svg>
        ),
    },
    {
        label: '明細ZIP出力',
        route: 'admin.payroll.exports.index',
        section: 'payroll',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776" />
            </svg>
        ),
    },
    {
        label: '帳票一覧',
        route: 'admin.payroll.reports.index',
        section: 'payroll',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
        ),
    },
    {
        label: '年末調整',
        route: 'admin.payroll.year-end.index',
        section: 'payroll',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 3h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        ),
    },
    {
        label: '管理ユーザー',
        route: 'admin.managers.index',
        section: 'admins',
        icon: (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
        ),
    },
];

function canSeeSection(permissions: Permissions, section: string | null): boolean {
    if (section === null) return true;
    return (permissions[section] ?? 'none') !== 'none';
}

function SidebarLink({ item, collapsed, permissions }: { item: NavItem; collapsed: boolean; permissions: Permissions }) {
    const isActive = route().current(item.route) || route().current(item.route + '.*');

    return (
        <Link
            href={route(item.route)}
            className={`group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all ${
                isActive
                    ? 'bg-white/15 text-white shadow-sm'
                    : 'text-white/70 hover:bg-white/10 hover:text-white'
            } ${collapsed ? 'justify-center' : ''}`}
        >
            <span className="shrink-0">{item.icon}</span>
            {!collapsed && <span>{item.label}</span>}
        </Link>
    );
}

export default function AdminLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const page = usePage();
    const { admin } = page.props.auth as any;
    const flash = page.props.flash as { success?: string; error?: string; token?: string | null } | undefined;
    const permissions: Permissions = (admin?.permissions ?? {}) as Permissions;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
    const flashTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const visibleNavItems = navItems.filter((item) => canSeeSection(permissions, item.section));

    useEffect(() => {
        const success = flash?.success;
        const error = flash?.error;
        if (!success && !error) {
            return;
        }
        const type = error ? 'error' : 'success';
        const message = (error ?? success) as string;
        if (flashTimerRef.current) {
            clearTimeout(flashTimerRef.current);
        }
        setToast({ type, message });
        flashTimerRef.current = setTimeout(() => {
            setToast(null);
            flashTimerRef.current = null;
        }, ADMIN_FLASH_DURATION_MS);
        return () => {
            if (flashTimerRef.current) {
                clearTimeout(flashTimerRef.current);
                flashTimerRef.current = null;
            }
        };
    }, [flash?.success, flash?.error, flash?.token]);

    return (
        <>
            {toast && (
                <div
                    className={`fixed right-0 top-1/2 z-50 max-w-[min(100vw-1rem,20rem)] -translate-y-1/2 rounded-l-xl px-5 py-4 pr-6 shadow-lg sm:max-w-sm ${
                        toast.type === 'success' ? 'bg-teal-600' : 'bg-red-600'
                    }`}
                    role="status"
                >
                    <p className="text-sm font-bold leading-snug text-white">{toast.message}</p>
                </div>
            )}
        <div className="flex min-h-screen bg-gray-50">
            {/* Overlay */}
            {mobileOpen && (
                <div
                    className="fixed inset-0 z-30 bg-black/40 lg:hidden"
                    onClick={() => setMobileOpen(false)}
                />
            )}

            {/* Sidebar */}
            <aside className={`
                fixed inset-y-0 left-0 z-40 flex flex-col bg-linear-to-b from-teal-600 to-teal-800 transition-all duration-300
                ${mobileOpen ? 'translate-x-0' : '-translate-x-full'}
                ${collapsed ? 'w-[72px]' : 'w-64'}
                lg:relative lg:translate-x-0
            `}>
                {/* Logo + Toggle */}
                <div className="flex h-16 shrink-0 items-center justify-between border-b border-white/10 px-4">
                    {collapsed ? (
                        <button
                            onClick={() => setCollapsed(false)}
                            className="group mx-auto hidden lg:flex h-9 w-9 items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white transition"
                        >
                            <svg className="h-5 w-5 group-hover:hidden" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                            </svg>
                            <svg className="hidden h-5 w-5 group-hover:block" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </button>
                    ) : (
                        <>
                            <Link
                                href={route('admin.dashboard')}
                                className="text-sm font-bold leading-tight text-white"
                            >
                                どでん給与システム
                            </Link>
                            <button
                                onClick={() => setCollapsed(true)}
                                className="group hidden lg:flex h-8 w-8 items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white transition"
                            >
                                <svg className="h-5 w-5 group-hover:hidden" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                                <svg className="hidden h-5 w-5 group-hover:block" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                </svg>
                            </button>
                        </>
                    )}
                </div>

                {/* Nav */}
                <nav className="flex-1 overflow-y-auto px-3 py-4">
                    <div className="flex flex-col gap-1">
                        {visibleNavItems.map((item) => (
                            <SidebarLink key={item.label} item={item} collapsed={collapsed} permissions={permissions} />
                        ))}
                    </div>
                </nav>

                {/* Footer */}
                <div className="border-t border-white/10 px-3 py-4">
                    {!collapsed && (
                        <p className="mb-3 px-2 text-[11px] font-semibold tracking-wide text-white/40">
                            株式会社フロティア
                        </p>
                    )}
                    <div className={`flex items-center gap-3 rounded-xl px-2 py-2 ${collapsed ? 'justify-center' : ''}`}>
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/20 text-sm font-bold text-white">
                            {admin?.name?.charAt(0) || 'A'}
                        </div>
                        {!collapsed && (
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-semibold text-white">{admin?.name || '管理者'}</p>
                                <Link
                                    href={route('admin.logout')}
                                    method="post"
                                    as="button"
                                    className="text-xs text-white/50 hover:text-white/80 transition"
                                >
                                    ログアウト
                                </Link>
                            </div>
                        )}
                    </div>
                </div>

            </aside>

            {/* Main */}
            <div className="flex min-w-0 flex-1 flex-col">
                {/* Top bar */}
                <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-4 border-b border-gray-200 bg-white px-4 sm:px-6">
                    <button
                        onClick={() => setMobileOpen(true)}
                        className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 lg:hidden"
                    >
                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>

                    {header && <div className="flex-1">{header}</div>}
                </header>

                {/* Page content */}
                <main className="flex-1">{children}</main>
            </div>
        </div>
        </>
    );
}

