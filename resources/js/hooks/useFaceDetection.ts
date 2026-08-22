import { useCallback, useEffect, useRef, useState } from 'react';
import * as faceapi from 'face-api.js';

const BASE_PATH = (import.meta.env.VITE_BASE_PATH as string | undefined) ?? '';
const MODEL_URL = `${BASE_PATH}/models`;
const MIN_FACE_SIZE = 80;
const DETECTION_INTERVAL_MS = 500;

// 枠内判定
const GUIDE_SAMPLE_POINTS = 12;
const GUIDE_REQUIRED_RATIO = 0.8;

// 正面判定: 鼻から左右顔端までの比率（1.0 = 完全正面）
const YAW_MIN = 0.55;
const YAW_MAX = 1.8;

// 目の開き判定 (Eye Aspect Ratio)
const EAR_THRESHOLD = 0.18;

export type FaceRejectReason = 'none' | 'not_frontal' | 'eyes_hidden';

export interface FaceGuideZone {
    cx: number;
    cy: number;
    rx: number;
    ry: number;
}

function isPointInEllipse(px: number, py: number, guide: FaceGuideZone): boolean {
    const dx = (px - guide.cx) / guide.rx;
    const dy = (py - guide.cy) / guide.ry;
    return dx * dx + dy * dy <= 1;
}

function isFaceInGuide(
    box: { x: number; y: number; width: number; height: number },
    videoWidth: number,
    videoHeight: number,
    guide: FaceGuideZone,
): boolean {
    const left = box.x / videoWidth;
    const top = box.y / videoHeight;
    const w = box.width / videoWidth;
    const h = box.height / videoHeight;

    let inside = 0;
    let total = 0;
    for (let row = 0; row <= GUIDE_SAMPLE_POINTS; row++) {
        for (let col = 0; col <= GUIDE_SAMPLE_POINTS; col++) {
            const px = left + (col / GUIDE_SAMPLE_POINTS) * w;
            const py = top + (row / GUIDE_SAMPLE_POINTS) * h;
            if (isPointInEllipse(px, py, guide)) inside++;
            total++;
        }
    }
    return inside / total >= GUIDE_REQUIRED_RATIO;
}

function dist(a: faceapi.Point, b: faceapi.Point): number {
    return Math.sqrt((a.x - b.x) ** 2 + (a.y - b.y) ** 2);
}

function eyeAspectRatio(pts: faceapi.Point[]): number {
    const vertical1 = dist(pts[1], pts[5]);
    const vertical2 = dist(pts[2], pts[4]);
    const horizontal = dist(pts[0], pts[3]);
    if (horizontal === 0) return 0;
    return (vertical1 + vertical2) / (2 * horizontal);
}

function validateFaceLandmarks(landmarks: faceapi.FaceLandmarks68): FaceRejectReason {
    const pts = landmarks.positions;

    // 正面判定: landmark 0=左端, 16=右端, 30=鼻先
    const leftDist = dist(pts[30], pts[0]);
    const rightDist = dist(pts[30], pts[16]);
    const yawRatio = leftDist / rightDist;

    if (yawRatio < YAW_MIN || yawRatio > YAW_MAX) {
        return 'not_frontal';
    }

    // 目の開き: 左目 36-41, 右目 42-47
    const leftEye = pts.slice(36, 42);
    const rightEye = pts.slice(42, 48);
    const leftEAR = eyeAspectRatio(leftEye);
    const rightEAR = eyeAspectRatio(rightEye);

    if (leftEAR < EAR_THRESHOLD || rightEAR < EAR_THRESHOLD) {
        return 'eyes_hidden';
    }

    return 'none';
}

export function useFaceDetection(
    videoRef: React.RefObject<HTMLVideoElement | null>,
    guide?: FaceGuideZone,
) {
    const [modelsLoaded, setModelsLoaded] = useState(false);
    const [faceDetected, setFaceDetected] = useState(false);
    const [faceInGuide, setFaceInGuide] = useState(false);
    const [rejectReason, setRejectReason] = useState<FaceRejectReason>('none');
    const [loading, setLoading] = useState(true);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        const loadModels = async () => {
            try {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL),
                ]);
                setModelsLoaded(true);
            } catch (err) {
                console.error('Failed to load face detection models:', err);
            } finally {
                setLoading(false);
            }
        };
        loadModels();
    }, []);

    const startDetection = useCallback(() => {
        if (!modelsLoaded || !videoRef.current) return;

        if (intervalRef.current) {
            clearInterval(intervalRef.current);
        }

        intervalRef.current = setInterval(async () => {
            const video = videoRef.current;
            if (!video || video.paused || video.ended || video.readyState < 2) {
                setFaceDetected(false);
                setFaceInGuide(false);
                setRejectReason('none');
                return;
            }

            try {
                const result = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))
                    .withFaceLandmarks(true);

                if (!result || result.detection.box.width < MIN_FACE_SIZE || result.detection.box.height < MIN_FACE_SIZE) {
                    setFaceDetected(false);
                    setFaceInGuide(false);
                    setRejectReason('none');
                    return;
                }

                setFaceDetected(true);

                const reason = validateFaceLandmarks(result.landmarks);
                setRejectReason(reason);

                if (reason !== 'none') {
                    setFaceInGuide(false);
                    return;
                }

                if (guide) {
                    setFaceInGuide(
                        isFaceInGuide(result.detection.box, video.videoWidth, video.videoHeight, guide)
                    );
                } else {
                    setFaceInGuide(true);
                }
            } catch {
                setFaceDetected(false);
                setFaceInGuide(false);
                setRejectReason('none');
            }
        }, DETECTION_INTERVAL_MS);
    }, [modelsLoaded, videoRef, guide]);

    const stopDetection = useCallback(() => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }
        setFaceDetected(false);
        setFaceInGuide(false);
        setRejectReason('none');
    }, []);

    useEffect(() => {
        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, []);

    return { modelsLoaded, faceDetected, faceInGuide, rejectReason, loading, startDetection, stopDetection };
}
