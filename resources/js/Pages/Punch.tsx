import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useCamera } from '@/hooks/useCamera';
import { useFaceDetection, type FaceGuideZone, type FaceRejectReason } from '@/hooks/useFaceDetection';
import axios from 'axios';
import type { Attendance, AttendanceBreak } from '@/types';

const BASE_PATH = (import.meta.env.VITE_BASE_PATH as string | undefined) ?? '';

const FACE_GUIDE: FaceGuideZone = { cx: 0.5, cy: 0.45, rx: 0.18, ry: 0.38 };

interface Props {
    user: { id: number; name: string };
    /** 店舗別画面から遷移した場合のみ設定。打刻後は同じ店舗画面へ戻す。 */
    store?: { id: number; name: string } | null;
    serverTime: string;
    /** 打刻時に顔写真（カメラ・顔認識）を使用するか。false ならカメラを表示しない。 */
    usePhoto?: boolean;
}

type PunchStatus = 'idle' | 'submitting' | 'success' | 'error';

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

function useStampingSound() {
    const audioRef = useRef<HTMLAudioElement | null>(null);

    useEffect(() => {
        audioRef.current = new Audio(`${BASE_PATH}/sounds/stamping.mp3`);
        audioRef.current.preload = 'auto';
    }, []);

    return useCallback(() => {
        if (audioRef.current) {
            audioRef.current.currentTime = 0;
            audioRef.current.play().catch(() => {});
        }
    }, []);
}

function formatTime(datetime: string | null) {
    if (!datetime) return '--:--';
    return new Date(datetime).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
}

export default function Punch({ user, store, serverTime, usePhoto = false }: Props) {
    const now = useServerClock(serverTime);
    const playStamping = useStampingSound();
    const homeUrl = store ? route('home.store', store.id) : route('home');
    const { videoRef, cameraReady, cameraError, startCamera, stopCamera, capturePhoto } = useCamera();
    const { faceDetected, faceInGuide, rejectReason, loading: modelsLoading, startDetection, stopDetection } = useFaceDetection(videoRef, FACE_GUIDE);

    const rejectMessage = (reason: FaceRejectReason): string => {
        switch (reason) {
            case 'not_frontal': return '正面を向いてください';
            case 'eyes_hidden': return '目を見せてください';
            default: return '';
        }
    };

    const [attendance, setAttendance] = useState<Attendance | null>(null);
    const [breaks, setBreaks] = useState<AttendanceBreak[]>([]);
    const [status, setStatus] = useState<PunchStatus>('idle');
    const [message, setMessage] = useState('');
    const [loadingAttendance, setLoadingAttendance] = useState(true);

    const fetchToday = useCallback(async () => {
        try {
            const res = await axios.get(`/api/attendance/today`, {
                params: { user_id: user.id },
            });
            const att: Attendance | null = res.data.attendance;
            setAttendance(att);
            setBreaks(att?.attendance_breaks ?? []);
        } catch {
            console.error('Failed to fetch today attendance');
        } finally {
            setLoadingAttendance(false);
        }
    }, [user.id]);

    useEffect(() => {
        fetchToday();
        if (usePhoto) {
            startCamera();
        }
        return () => {
            if (usePhoto) {
                stopCamera();
                stopDetection();
            }
        };
    }, []);

    useEffect(() => {
        if (usePhoto && cameraReady) {
            startDetection();
        }
    }, [usePhoto, cameraReady, startDetection]);

    const isOnBreak = useMemo(
        () => breaks.length > 0 && breaks[breaks.length - 1].ended_at === null,
        [breaks],
    );

    // 顔写真ONのときのみガイド枠内判定を必須にする。OFF時はカメラなしで打刻可能。
    const photoOk = usePhoto ? faceInGuide : true;
    const canClockIn = !attendance && photoOk && status !== 'submitting';
    const canClockOut = !!(attendance && !attendance.clock_out_at && photoOk && status !== 'submitting');
    const canBreakStart = !!(attendance && !attendance.clock_out_at && !isOnBreak && photoOk && status !== 'submitting');
    const canBreakEnd = !!(attendance && !attendance.clock_out_at && isOnBreak && photoOk && status !== 'submitting');
    const isAllDone = attendance?.clock_in_at && attendance?.clock_out_at;
    const hasClockedIn = !!(attendance?.clock_in_at && !attendance?.clock_out_at);

    const handlePunch = async (type: 'clock-in' | 'clock-out') => {
        let photo: string | null = null;
        if (usePhoto) {
            photo = capturePhoto(FACE_GUIDE);
            if (!photo) {
                setMessage('写真の撮影に失敗しました');
                setStatus('error');
                return;
            }
        }

        setStatus('submitting');
        setMessage('');

        try {
            const res = await axios.post(`/api/attendance/${type}`, {
                user_id: user.id,
                // 出勤時は打刻した店舗を記録する（店舗別画面から遷移した場合のみ）。
                department_id: type === 'clock-in' ? store?.id ?? null : undefined,
                photo: photo ?? undefined,
            });
            const att: Attendance = res.data.attendance;
            setAttendance(att);
            setBreaks(att.attendance_breaks ?? []);
            setMessage(res.data.message);
            setStatus('success');
            playStamping();
            router.visit(`${homeUrl}?punch_success=1`);
        } catch (err: any) {
            const msg = err.response?.data?.message || '打刻に失敗しました';
            setMessage(msg);
            setStatus('error');
        }
    };

    const handleBreak = async (type: 'break-start' | 'break-end') => {
        let photo: string | null = null;
        if (usePhoto) {
            photo = capturePhoto(FACE_GUIDE);
            if (!photo) {
                setMessage('写真の撮影に失敗しました');
                setStatus('error');
                return;
            }
        }

        setStatus('submitting');
        setMessage('');

        try {
            const res = await axios.post(`/api/attendance/${type}`, {
                user_id: user.id,
                photo: photo ?? undefined,
            });
            const att: Attendance = res.data.attendance;
            setAttendance(att);
            setBreaks(att.attendance_breaks ?? []);
            setMessage(res.data.message);
            setStatus('success');
            playStamping();
            router.visit(`${homeUrl}?punch_success=1`);
        } catch (err: any) {
            const msg = err.response?.data?.message || '休憩の処理に失敗しました';
            setMessage(msg);
            setStatus('error');
        }
    };

    const completedBreaks = breaks.filter(b => b.ended_at !== null);
    const totalBreakMinutes = completedBreaks.reduce((acc, b) => {
        return acc + Math.floor((new Date(b.ended_at!).getTime() - new Date(b.started_at).getTime()) / 60000);
    }, 0);

    return (
        <>
            <Head title={`打刻 - ${user.name}`} />

            <div className="min-h-screen bg-gray-50">
                {/* Header */}
                <header className="border-b border-gray-200 bg-white">
                    <div className="mx-auto flex max-w-2xl items-center justify-between px-4 py-2 sm:px-6 sm:py-3">
                        <Link href={homeUrl} className="text-sm text-gray-500 hover:text-gray-700">
                            &larr; 戻る
                        </Link>
                        {usePhoto ? (
                            <h1 className="text-base font-bold text-gray-800 sm:text-lg">{user.name}</h1>
                        ) : (
                            <span className="text-sm text-gray-400">打刻</span>
                        )}
                        <div className="w-10" />
                    </div>
                </header>

                <div className="mx-auto max-w-2xl px-4 py-2 sm:px-6 sm:py-4">
                    {/* 顔写真OFF時: 名前確認（日付の上に大きく表示） */}
                    {!usePhoto && (
                        <div className="mb-3 overflow-hidden rounded-2xl border-2 border-indigo-200 bg-linear-to-b from-indigo-50 to-white shadow-sm sm:mb-4">
                            <div className="border-b border-indigo-100 bg-indigo-600 px-4 py-2 text-center">
                                <p className="text-xs font-semibold tracking-wide text-indigo-100 sm:text-sm">
                                    打刻する方の名前をご確認ください
                                </p>
                            </div>
                            <div className="px-4 py-5 text-center sm:py-6">
                                <p className="text-3xl font-black leading-tight tracking-tight text-gray-900 sm:text-4xl md:text-5xl">
                                    {user.name}
                                </p>
                                {store?.name && (
                                    <p className="mt-2 text-sm font-medium text-indigo-600 sm:text-base">
                                        {store.name}
                                    </p>
                                )}
                                <p className="mt-3 text-xs text-gray-500 sm:text-sm">
                                    名前が違う場合は「戻る」から正しい方を選び直してください
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Date & Status */}
                    <div className="mb-2 overflow-hidden rounded-xl bg-white shadow-sm sm:mb-3">
                        <div className="border-b border-gray-100 bg-gray-50 px-3 py-2 sm:px-4 sm:py-3">
                            <h3 className="text-sm font-semibold text-gray-700 sm:text-base">
                                {new Date().toLocaleDateString('ja-JP', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' })}
                            </h3>
                        </div>
                        <div className="flex divide-x divide-gray-100 px-3 py-3 sm:px-5 sm:py-4">
                            <div className="flex-1 text-center">
                                <p className="text-xs text-gray-500 sm:text-sm">出勤</p>
                                <p className="mt-0.5 text-xl font-bold text-green-600 sm:text-2xl">
                                    {loadingAttendance ? '...' : formatTime(attendance?.clock_in_at ?? null)}
                                </p>
                            </div>
                            <div className="flex-1 text-center">
                                <p className="text-xs text-gray-500 sm:text-sm">退勤</p>
                                <p className="mt-0.5 text-xl font-bold text-blue-600 sm:text-2xl">
                                    {loadingAttendance ? '...' : formatTime(attendance?.clock_out_at ?? null)}
                                </p>
                            </div>
                            {/* 休憩状況（出勤後のみ表示） */}
                            {hasClockedIn && (
                                <div className="flex-1 text-center">
                                    <p className="text-xs text-gray-500 sm:text-sm">休憩</p>
                                    <p className={`mt-0.5 text-xl font-bold sm:text-2xl ${isOnBreak ? 'text-orange-500' : 'text-gray-400'}`}>
                                        {isOnBreak
                                            ? '休憩中'
                                            : completedBreaks.length > 0
                                              ? `${totalBreakMinutes}分`
                                              : '--'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Camera（顔写真ON時のみ表示） */}
                    {usePhoto && (
                    <div className="mb-2 overflow-hidden rounded-xl bg-black shadow-sm sm:mb-3">
                        <div className="relative aspect-video max-h-[min(38vh,260px)] w-full sm:max-h-[min(48vh,360px)] lg:max-h-none">
                            <video
                                ref={videoRef}
                                className="h-full w-full object-cover"
                                playsInline
                                muted
                            />
                            {/* Face guide overlay */}
                            {cameraReady && (
                                <svg className="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                                    <defs>
                                        <mask id="guide-mask">
                                            <rect x="0" y="0" width="100" height="100" fill="white" />
                                            <ellipse
                                                cx={FACE_GUIDE.cx * 100}
                                                cy={FACE_GUIDE.cy * 100}
                                                rx={FACE_GUIDE.rx * 100}
                                                ry={FACE_GUIDE.ry * 100}
                                                fill="black"
                                            />
                                        </mask>
                                    </defs>
                                    <rect x="0" y="0" width="100" height="100" fill="rgba(0,0,0,0.45)" mask="url(#guide-mask)" />
                                    <ellipse
                                        cx={FACE_GUIDE.cx * 100}
                                        cy={FACE_GUIDE.cy * 100}
                                        rx={FACE_GUIDE.rx * 100}
                                        ry={FACE_GUIDE.ry * 100}
                                        fill="none"
                                        stroke={faceInGuide ? '#22c55e' : '#ffffff'}
                                        strokeWidth="0.5"
                                        strokeDasharray={faceInGuide ? '0' : '2 1.5'}
                                    />
                                </svg>
                            )}
                            {!cameraReady && !cameraError && (
                                <div className="absolute inset-0 flex items-center justify-center bg-gray-900 text-white">
                                    カメラを起動中...
                                </div>
                            )}
                            {cameraError && (
                                <div className="absolute inset-0 flex items-center justify-center bg-gray-900 p-4 text-center text-red-400">
                                    {cameraError}
                                </div>
                            )}
                            {cameraReady && (
                                <div className={`absolute left-4 top-4 flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold ${
                                    faceInGuide
                                        ? 'bg-green-500/90 text-white'
                                        : faceDetected
                                          ? 'bg-yellow-500/90 text-white'
                                          : 'bg-red-500/90 text-white'
                                }`}>
                                    <span className={`inline-block h-2 w-2 rounded-full ${faceInGuide ? 'bg-white' : 'bg-red-200 animate-pulse'}`} />
                                    {modelsLoading
                                        ? 'モデル読込中...'
                                        : faceInGuide
                                          ? '撮影OK'
                                          : faceDetected && rejectReason !== 'none'
                                            ? rejectMessage(rejectReason)
                                            : faceDetected
                                              ? '枠内に顔を合わせてください'
                                              : '顔が検出されません'}
                                </div>
                            )}
                            {cameraReady && !faceInGuide && !modelsLoading && (
                                <div className="absolute inset-x-0 bottom-4 text-center">
                                    <span className="rounded-full bg-black/60 px-4 py-1.5 text-xs text-white">
                                        {faceDetected && rejectReason !== 'none'
                                            ? rejectMessage(rejectReason)
                                            : '枠の中に顔を合わせてください'}
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>
                    )}

                    {/* Server Clock */}
                    <div className="mb-2 rounded-xl border border-gray-200 bg-white py-2 sm:mb-3 sm:py-3">
                        <div className="px-2 text-center sm:px-4">
                            <p className="text-lg font-bold leading-tight text-gray-800 sm:text-xl md:text-2xl">
                                {now.toLocaleDateString('ja-JP', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' })}
                            </p>
                            <p className="mt-0.5 font-mono text-2xl font-bold tabular-nums tracking-wide text-gray-800 sm:text-3xl">
                                {now.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                            </p>
                        </div>
                    </div>

                    {/* Message */}
                    {message && (
                        <div className={`mb-2 rounded-lg px-3 py-2 text-sm font-medium sm:mb-3 ${
                            status === 'success'
                                ? 'bg-green-50 text-green-700 border border-green-200'
                                : 'bg-red-50 text-red-700 border border-red-200'
                        }`}>
                            {message}
                        </div>
                    )}

                    {/* Buttons */}
                    {isAllDone ? (
                        <div className="rounded-xl bg-gray-50 py-4 text-center sm:py-6">
                            <p className="text-base font-semibold text-gray-600 sm:text-lg">本日の打刻は完了しています</p>
                            <p className="mt-0.5 text-sm text-gray-400">お疲れさまでした</p>
                        </div>
                    ) : (
                        <div className="space-y-2 pb-2 sm:space-y-3">
                            {/* 出勤・退勤ボタン */}
                            <div className="grid grid-cols-2 gap-2 sm:gap-4">
                                <button
                                    onClick={() => handlePunch('clock-in')}
                                    disabled={!canClockIn}
                                    className="rounded-xl bg-green-600 px-3 py-3 text-base font-bold text-white shadow-sm transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-40 sm:px-6 sm:py-4 sm:text-lg"
                                >
                                    {status === 'submitting' ? '処理中...' : '出勤'}
                                </button>
                                <button
                                    onClick={() => handlePunch('clock-out')}
                                    disabled={!canClockOut}
                                    className="rounded-xl bg-blue-600 px-3 py-3 text-base font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40 sm:px-6 sm:py-4 sm:text-lg"
                                >
                                    {status === 'submitting' ? '処理中...' : '退勤'}
                                </button>
                            </div>

                            {/* 休憩ボタン（出勤後・退勤前のみ表示） */}
                            {hasClockedIn && (
                                <div>
                                    {isOnBreak ? (
                                        <button
                                            onClick={() => handleBreak('break-end')}
                                            disabled={!canBreakEnd}
                                            className="w-full rounded-xl bg-amber-500 px-3 py-3 text-base font-bold text-white shadow-sm transition hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-40 sm:px-6 sm:py-4 sm:text-lg"
                                        >
                                            {status === 'submitting' ? '処理中...' : '☕ 休憩から戻る'}
                                        </button>
                                    ) : (
                                        <button
                                            onClick={() => handleBreak('break-start')}
                                            disabled={!canBreakStart}
                                            className="w-full rounded-xl bg-orange-500 px-3 py-3 text-base font-bold text-white shadow-sm transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-40 sm:px-6 sm:py-4 sm:text-lg"
                                        >
                                            {status === 'submitting' ? '処理中...' : '☕ 休憩に入る'}
                                        </button>
                                    )}

                                    {/* 休憩履歴（本日分） */}
                                    {breaks.length > 0 && (
                                        <div className="mt-2 rounded-lg border border-orange-100 bg-orange-50 px-3 py-2">
                                            <p className="mb-1 text-xs font-semibold text-orange-700">本日の休憩</p>
                                            <div className="space-y-0.5">
                                                {breaks.map((b, i) => (
                                                    <div key={b.id} className="flex items-center gap-1 text-xs text-orange-800">
                                                        <span className="font-medium">休憩{i + 1}:</span>
                                                        <span>{formatTime(b.started_at)}</span>
                                                        <span>→</span>
                                                        <span>{b.ended_at ? formatTime(b.ended_at) : '休憩中...'}</span>
                                                        {b.ended_at && (
                                                            <span className="ml-1 text-orange-500">
                                                                ({Math.floor((new Date(b.ended_at).getTime() - new Date(b.started_at).getTime()) / 60000)}分)
                                                            </span>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
