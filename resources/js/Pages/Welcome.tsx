import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface DepartmentItem {
    id: number;
    name: string;
}

interface UserItem {
    id: number;
    name: string;
    avatar_url?: string | null;
    department_id: number | null;
    department?: DepartmentItem | null;
}

interface AttendanceItem {
    id: number;
    user_id: number;
    clock_in_at: string | null;
    clock_out_at: string | null;
    user: { id: number; name: string };
}

interface Props {
    users: UserItem[];
    departments: DepartmentItem[];
    /** 店舗別画面のときのみ設定される。全店舗表示のときは null。 */
    currentDepartment?: DepartmentItem | null;
    todayAttendances: AttendanceItem[];
    serverTime: string;
    punchSuccess?: boolean;
}

function useServerClock(serverTime: string) {
    const offsetRef = useRef(new Date(serverTime).getTime() - Date.now());
    const [now, setNow] = useState(() => new Date(Date.now() + offsetRef.current));

    useEffect(() => {
        const id = setInterval(() => {
            setNow(new Date(Date.now() + offsetRef.current));
        }, 1000);
        return () => clearInterval(id);
    }, []);

    return now;
}

function getInitial(name: string): string {
    return name.charAt(0);
}

const COLORS = [
    'bg-blue-500',
    'bg-green-500',
    'bg-purple-500',
    'bg-pink-500',
    'bg-indigo-500',
    'bg-teal-500',
    'bg-orange-500',
    'bg-cyan-500',
    'bg-rose-500',
    'bg-amber-500',
];

function getColor(id: number): string {
    return COLORS[id % COLORS.length];
}

function formatTime(datetime: string | null): string {
    if (!datetime) return '--:--';
    const d = new Date(datetime);
    return d.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
}

function UserCard({ user, storeId }: { user: UserItem; storeId?: number | null }) {
    return (
        <Link
            href={storeId ? route('punch', { user: user.id, store: storeId }) : route('punch', user.id)}
            className="flex items-center gap-3 rounded-xl bg-white px-4 py-4 shadow-sm ring-1 ring-gray-100 transition hover:shadow-md hover:ring-gray-200 active:scale-[0.98] sm:gap-4 sm:px-5 sm:py-5"
        >
            {user.avatar_url ? (
                <img
                    src={user.avatar_url}
                    alt={user.name}
                    className="h-11 w-11 shrink-0 rounded-full object-cover sm:h-14 sm:w-14"
                />
            ) : (
                <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-lg font-bold text-white sm:h-14 sm:w-14 sm:text-xl ${getColor(user.id)}`}>
                    {getInitial(user.name)}
                </div>
            )}
            <span className="text-sm font-semibold text-gray-800 sm:text-base">
                {user.name}
            </span>
        </Link>
    );
}

function UserGrid({ users, storeId }: { users: UserItem[]; storeId?: number | null }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3 sm:gap-4">
            {users.map((user) => (
                <UserCard key={user.id} user={user} storeId={storeId} />
            ))}
        </div>
    );
}

function AttendanceRow({ attendance }: { attendance: AttendanceItem }) {
    const isCompleted = !!attendance.clock_out_at;

    return (
        <div className={`
            flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all
            ${isCompleted
                ? 'bg-linear-to-r from-emerald-50 to-teal-50'
                : 'bg-linear-to-r from-blue-50 to-indigo-50'
            }
        `}>
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${getColor(attendance.user.id)}`}>
                {getInitial(attendance.user.name)}
            </div>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-gray-800">
                    {attendance.user.name}
                </p>
                <div className="mt-0.5 flex items-center gap-2 text-[11px] font-medium tabular-nums text-gray-500">
                    <span className="flex items-center gap-0.5">
                        <span className="inline-block h-1.5 w-1.5 rounded-full bg-blue-400" />
                        {formatTime(attendance.clock_in_at)}
                    </span>
                    {isCompleted && (
                        <span className="flex items-center gap-0.5">
                            <span className="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400" />
                            {formatTime(attendance.clock_out_at)}
                        </span>
                    )}
                </div>
            </div>

            <span className={`
                shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold
                ${isCompleted
                    ? 'bg-emerald-500 text-white'
                    : 'bg-blue-500 text-white'
                }
            `}>
                {isCompleted ? '退勤済' : '出勤中'}
            </span>
        </div>
    );
}

function TodayAttendancePanel({ attendances }: { attendances: AttendanceItem[] }) {
    const working = attendances.filter(a => !a.clock_out_at);
    const completed = attendances.filter(a => !!a.clock_out_at);

    return (
        <div className="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div className="border-b border-gray-100 px-4 py-3">
                <div className="flex items-center gap-2">
                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-100">
                        <svg className="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h2 className="text-sm font-bold text-gray-700">本日の打刻状況</h2>
                </div>
                <div className="mt-2 flex items-center gap-3 text-xs text-gray-500">
                    <span className="flex items-center gap-1">
                        <span className="inline-block h-2 w-2 rounded-full bg-blue-500" />
                        出勤中 {working.length}名
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="inline-block h-2 w-2 rounded-full bg-emerald-500" />
                        退勤済 {completed.length}名
                    </span>
                </div>
            </div>

            <div className="max-h-[calc(100vh-320px)] overflow-y-auto p-3">
                {attendances.length > 0 ? (
                    <div className="flex flex-col gap-2">
                        {attendances.map((att) => (
                            <AttendanceRow key={att.id} attendance={att} />
                        ))}
                    </div>
                ) : (
                    <div className="flex items-center justify-center rounded-xl border border-dashed border-gray-200 py-8 text-sm text-gray-400">
                        まだ打刻はありません
                    </div>
                )}
            </div>
        </div>
    );
}

const PUNCH_SUCCESS_DURATION_MS = 4000;

/** 指定タイムゾーンの暦日を YYYY-MM-DD（比較用）。Laravel の config('app.timezone') と揃える */
function calendarDateKey(d: Date, timeZone: string): string {
    const parts = new Intl.DateTimeFormat('ja-JP', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(d);
    const y = parts.find((p) => p.type === 'year')?.value;
    const m = parts.find((p) => p.type === 'month')?.value;
    const day = parts.find((p) => p.type === 'day')?.value;
    return `${y}-${m}-${day}`;
}

export default function Welcome({ users, departments, currentDepartment, todayAttendances, serverTime, punchSuccess: punchSuccessProp }: Props) {
    const now = useServerClock(serverTime);
    const [showPunchSuccess, setShowPunchSuccess] = useState(!!punchSuccessProp);
    const isStoreView = !!currentDepartment;
    const hasDepartments = departments.length > 0;
    const dateKeyForDataRef = useRef<string | null>(null);

    // 画面を開きっぱなしで日が変わっても「本日の打刻状況」が前日のままにならないよう再取得（routes/web.php の Carbon::today() と一致させる）
    useEffect(() => {
        const key = calendarDateKey(now, 'Asia/Tokyo');
        if (dateKeyForDataRef.current === null) {
            dateKeyForDataRef.current = key;
            return;
        }
        if (dateKeyForDataRef.current !== key) {
            dateKeyForDataRef.current = key;
            router.reload({
                only: ['todayAttendances', 'serverTime', 'users', 'departments', 'currentDepartment', 'punchSuccess'],
            });
        }
    }, [now]);

    useEffect(() => {
        if (!punchSuccessProp) return;
        setShowPunchSuccess(true);
        const t = window.setTimeout(() => {
            setShowPunchSuccess(false);
            if (window.history.replaceState) {
                window.history.replaceState({}, '', route('home'));
            }
        }, PUNCH_SUCCESS_DURATION_MS);
        return () => window.clearTimeout(t);
    }, [punchSuccessProp]);

    const groupedByDept = hasDepartments
        ? departments.map((dept) => ({
              department: dept,
              users: users.filter((u) => u.department_id === dept.id),
          }))
        : [];

    const unassignedUsers = hasDepartments
        ? users.filter((u) => !u.department_id)
        : [];

    return (
        <>
            <Head title="打刻" />

            {/* 打刻完了ステータス（固定・右端・約4秒） */}
            {showPunchSuccess && (
                <div
                    className="fixed right-0 top-1/2 z-50 -translate-y-1/2 rounded-l-xl bg-teal-600 px-5 py-4 pr-6 shadow-lg"
                    role="status"
                >
                    <p className="text-sm font-bold text-white whitespace-nowrap">打刻完了しました</p>
                </div>
            )}

            <div className="min-h-screen bg-gray-50">
                <header className="border-b border-gray-200 bg-white">
                    <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6">
                        {isStoreView && (
                            <div className="mx-auto mb-2 flex max-w-max items-center gap-1.5 rounded-full bg-teal-50 px-3 py-1 text-sm font-semibold text-teal-700">
                                <i className="fa-solid fa-store text-xs" />
                                {currentDepartment!.name}
                            </div>
                        )}
                        <h1 className="text-center text-2xl font-bold text-gray-800">打刻システム</h1>
                        <p className="mt-1 text-center text-sm text-gray-500">名前を選択して出勤・退勤をしてください</p>
                        {isStoreView && (
                            <div className="mt-3 text-center">
                                <Link href={route('home')} className="text-xs font-medium text-gray-400 hover:text-gray-600">
                                    全店舗を表示
                                </Link>
                            </div>
                        )}
                    </div>
                </header>

                {/* Server Clock */}
                <div className="bg-white border-b border-gray-100 py-4">
                    <div className="mx-auto max-w-5xl px-4 text-center sm:px-6">
                        <p className="text-2xl font-bold text-gray-800 sm:text-3xl">
                            {now.toLocaleDateString('ja-JP', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' })}
                        </p>
                        <p className="mt-1 font-mono text-3xl font-bold tabular-nums tracking-wide text-gray-800">
                            {now.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                        </p>
                    </div>
                </div>

                <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                    <div className="flex flex-col gap-6 lg:flex-row">
                        {/* User List */}
                        <div className="min-w-0 flex-1">
                            {isStoreView ? (
                                <UserGrid users={users} storeId={currentDepartment!.id} />
                            ) : hasDepartments ? (
                                <div className="space-y-8">
                                    {groupedByDept.map(({ department, users: deptUsers }) =>
                                        deptUsers.length > 0 ? (
                                            <section key={department.id}>
                                                <h2 className="mb-4 text-lg font-bold text-gray-700">{department.name}</h2>
                                                <UserGrid users={deptUsers} />
                                            </section>
                                        ) : null,
                                    )}
                                    {unassignedUsers.length > 0 && (
                                        <section>
                                            <h2 className="mb-4 text-lg font-bold text-gray-400">部署未設定</h2>
                                            <UserGrid users={unassignedUsers} />
                                        </section>
                                    )}
                                </div>
                            ) : (
                                <UserGrid users={users} />
                            )}

                            {users.length === 0 && (
                                <div className="py-20 text-center text-gray-400">
                                    登録されたユーザーがいません
                                </div>
                            )}
                        </div>

                        {/* Today's Attendance Panel */}
                        <div className="w-full shrink-0 lg:w-[320px] lg:sticky lg:top-6 lg:self-start">
                            <TodayAttendancePanel attendances={todayAttendances} />
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
