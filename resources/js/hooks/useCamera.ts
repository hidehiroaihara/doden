import { useCallback, useEffect, useRef, useState } from 'react';

const CAPTURE_WIDTH = 1280;
const JPEG_QUALITY = 0.7;
/** 枠より少し大きい範囲でトリミングするときの係数（1.3 = 約30%余裕） */
const CROP_MARGIN_FACTOR = 1.3;

export interface CropGuide {
    cx: number;
    cy: number;
    rx: number;
    ry: number;
}

export function useCamera() {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [cameraReady, setCameraReady] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);

    const startCamera = useCallback(async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
            streamRef.current = stream;
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
                setCameraReady(true);
            }
        } catch (err) {
            setCameraError('カメラの起動に失敗しました。カメラへのアクセスを許可してください。');
            console.error('Camera error:', err);
        }
    }, []);

    const stopCamera = useCallback(() => {
        if (streamRef.current) {
            streamRef.current.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
        }
        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
        setCameraReady(false);
    }, []);

    const capturePhoto = useCallback((guide?: CropGuide | null): string | null => {
        const video = videoRef.current;
        if (!video) return null;

        const vw = video.videoWidth;
        const vh = video.videoHeight;
        const canvas = document.createElement('canvas');

        const ctx = canvas.getContext('2d');
        if (!ctx) return null;

        if (guide) {
            const rx = guide.rx * vw * CROP_MARGIN_FACTOR;
            const ry = guide.ry * vh * CROP_MARGIN_FACTOR;
            let sx = guide.cx * vw - rx;
            let sy = guide.cy * vh - ry;
            let sw = rx * 2;
            let sh = ry * 2;
            sx = Math.max(0, Math.min(sx, vw - 1));
            sy = Math.max(0, Math.min(sy, vh - 1));
            sw = Math.min(sw, vw - sx);
            sh = Math.min(sh, vh - sy);

            const scale = Math.min(1, CAPTURE_WIDTH / sw);
            canvas.width = Math.round(sw * scale);
            canvas.height = Math.round(sh * scale);
            ctx.drawImage(video, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        } else {
            const ratio = vh / vw;
            canvas.width = Math.min(vw, CAPTURE_WIDTH);
            canvas.height = Math.round(canvas.width * ratio);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        }

        return canvas.toDataURL('image/jpeg', JPEG_QUALITY);
    }, []);

    useEffect(() => {
        return () => {
            stopCamera();
        };
    }, [stopCamera]);

    return { videoRef, cameraReady, cameraError, startCamera, stopCamera, capturePhoto };
}
