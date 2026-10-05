import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { captureFingerprint, MorFinError } from '@/lib/morfin-auth';
import { router } from '@inertiajs/react';
import { Camera, CheckCircle2, Fingerprint, Loader2, RotateCcw, ShieldOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type BiometricMethod = 'NONE' | 'FINGERPRINT' | 'FACIAL';

export const FINGER_CODES = ['R1', 'R2', 'R3', 'R4', 'RTH', 'L1', 'L2', 'L3', 'L4', 'LTH'] as const;

const FINGER_LABELS: Record<(typeof FINGER_CODES)[number], string> = {
    R1: 'R1 — Right index',
    R2: 'R2 — Right middle',
    R3: 'R3 — Right ring',
    R4: 'R4 — Right little',
    RTH: 'RTH — Right thumb',
    L1: 'L1 — Left index',
    L2: 'L2 — Left middle',
    L3: 'L3 — Left ring',
    L4: 'L4 — Left little',
    LTH: 'LTH — Left thumb',
};

const METHOD_OPTIONS: { value: BiometricMethod; label: string }[] = [
    { value: 'FINGERPRINT', label: 'Fingerprint' },
    { value: 'FACIAL', label: 'Facial' },
    { value: 'NONE', label: 'None' },
];

/** Max width of the webcam snapshot sent to NHIF — keeps the base64 payload small. */
const FACE_MAX_WIDTH = 640;

interface BiometricIssueDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    issueUrl: string;
    onIssued?: () => void;
}

type FieldErrors = Partial<Record<'biometricMethod' | 'fpCode' | 'imageData' | 'status' | 'items' | 'session', string>>;

export function BiometricIssueDialog({ open, onOpenChange, issueUrl, onIssued }: BiometricIssueDialogProps) {
    const [method, setMethod] = useState<BiometricMethod | ''>('');
    const [fpCode, setFpCode] = useState<string>('');
    const [imageData, setImageData] = useState<string | null>(null);
    const [previewSrc, setPreviewSrc] = useState<string | null>(null);
    const [capturing, setCapturing] = useState(false);
    const [captureError, setCaptureError] = useState<string | null>(null);
    const [captureInfo, setCaptureInfo] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<FieldErrors>({});

    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [cameraActive, setCameraActive] = useState(false);

    const stopCamera = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        setCameraActive(false);
    }, []);

    const clearCapture = useCallback(() => {
        setImageData(null);
        setPreviewSrc(null);
        setCaptureError(null);
        setCaptureInfo(null);
    }, []);

    const reset = useCallback(() => {
        stopCamera();
        setMethod('');
        setFpCode('');
        clearCapture();
        setErrors({});
        setSubmitting(false);
        setCapturing(false);
    }, [clearCapture, stopCamera]);

    useEffect(() => {
        if (!open) {
            reset();
        }
    }, [open, reset]);

    useEffect(() => stopCamera, [stopCamera]);

    const handleMethodChange = (value: string) => {
        stopCamera();
        clearCapture();
        setFpCode('');
        setErrors({});
        setMethod(value as BiometricMethod);
    };

    // ── Fingerprint ────────────────────────────────────────────────────────
    const handleCaptureFingerprint = async () => {
        setCapturing(true);
        setCaptureError(null);
        setCaptureInfo(null);

        try {
            const result = await captureFingerprint();
            setImageData(result.base64);
            setPreviewSrc(result.previewSrc);
            setCaptureInfo(`Captured (${result.format}${result.quality !== null ? `, quality ${result.quality}` : ''})`);
            setErrors((e) => ({ ...e, imageData: undefined }));
        } catch (error) {
            setImageData(null);
            setPreviewSrc(null);
            setCaptureError(error instanceof MorFinError ? error.message : 'Fingerprint capture failed. Please try again.');
        } finally {
            setCapturing(false);
        }
    };

    // ── Facial (webcam) ────────────────────────────────────────────────────
    const startCamera = async () => {
        setCaptureError(null);
        clearCapture();

        if (!navigator.mediaDevices?.getUserMedia) {
            setCaptureError('This browser does not support camera access. Use https:// or http://localhost.');

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 } }, audio: false });
            streamRef.current = stream;
            setCameraActive(true);

            requestAnimationFrame(() => {
                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    void videoRef.current.play();
                }
            });
        } catch {
            setCaptureError('Unable to access the camera. Allow camera permission and try again.');
        }
    };

    const captureFace = () => {
        const video = videoRef.current;

        if (!video || video.videoWidth === 0) {
            setCaptureError('Camera is not ready yet. Wait a moment and try again.');

            return;
        }

        const scale = Math.min(1, FACE_MAX_WIDTH / video.videoWidth);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);

        const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
        setPreviewSrc(dataUrl);
        setImageData(dataUrl.replace(/^data:[^;]+;base64,/, ''));
        setCaptureInfo('Photo captured');
        setErrors((e) => ({ ...e, imageData: undefined }));
        stopCamera();
    };

    // ── Readiness + submit ─────────────────────────────────────────────────
    const isReady = method === 'NONE' || (method === 'FINGERPRINT' && fpCode !== '' && !!imageData) || (method === 'FACIAL' && !!imageData);

    const handleIssue = () => {
        if (!isReady || !method) {
            return;
        }

        setSubmitting(true);
        setErrors({});

        router.post(
            issueUrl,
            {
                biometricMethod: method,
                fpCode: method === 'FINGERPRINT' ? fpCode : null,
                imageData: method === 'NONE' ? null : imageData,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onIssued?.();
                },
                onError: (serverErrors) => setErrors(serverErrors as FieldErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const generalError = errors.status ?? errors.items ?? errors.session ?? errors.biometricMethod;

    return (
        <Dialog open={open} onOpenChange={(next) => !submitting && onOpenChange(next)}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Fingerprint className="text-primary size-5" />
                        Biometric Verification
                    </DialogTitle>
                    <DialogDescription>NHIF requires a biometric method before approved services can be issued.</DialogDescription>
                </DialogHeader>

                <div className="space-y-5">
                    {generalError && (
                        <Alert variant="destructive">
                            <AlertDescription>{generalError}</AlertDescription>
                        </Alert>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="biometric-method">Biometric Method</Label>
                        <Select value={method} onValueChange={handleMethodChange} disabled={submitting}>
                            <SelectTrigger id="biometric-method">
                                <SelectValue placeholder="Select biometric method" />
                            </SelectTrigger>
                            <SelectContent>
                                {METHOD_OPTIONS.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {method === 'NONE' && (
                        <div className="bg-muted/50 text-muted-foreground flex items-start gap-3 rounded-lg border p-3 text-sm">
                            <ShieldOff className="mt-0.5 size-4 shrink-0" />
                            No biometric data will be sent. Finger code and image will be submitted as empty (null).
                        </div>
                    )}

                    {method === 'FINGERPRINT' && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="fp-code">Finger (FP Code)</Label>
                                <Select
                                    value={fpCode}
                                    onValueChange={(value) => {
                                        setFpCode(value);
                                        setErrors((e) => ({ ...e, fpCode: undefined }));
                                    }}
                                    disabled={submitting}
                                >
                                    <SelectTrigger id="fp-code">
                                        <SelectValue placeholder="Select finger scanned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {FINGER_CODES.map((code) => (
                                            <SelectItem key={code} value={code}>
                                                {FINGER_LABELS[code]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.fpCode && <p className="text-destructive text-xs">{errors.fpCode}</p>}
                            </div>

                            <div className="flex items-center gap-4">
                                <div className="bg-muted/40 flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-lg border">
                                    {previewSrc ? (
                                        <img src={previewSrc} alt="Captured fingerprint" className="size-full object-contain" />
                                    ) : (
                                        <Fingerprint className="text-muted-foreground/50 size-12" />
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Button type="button" variant={imageData ? 'outline' : 'default'} onClick={handleCaptureFingerprint} disabled={capturing || submitting}>
                                        {capturing ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : imageData ? (
                                            <RotateCcw className="size-4" />
                                        ) : (
                                            <Fingerprint className="size-4" />
                                        )}
                                        {capturing ? 'Place finger on scanner…' : imageData ? 'Recapture' : 'Capture Fingerprint'}
                                    </Button>
                                    {captureInfo && <p className="text-xs text-green-600">{captureInfo}</p>}
                                </div>
                            </div>
                        </div>
                    )}

                    {method === 'FACIAL' && (
                        <div className="space-y-3">
                            <div className="bg-muted/40 relative flex aspect-video w-full items-center justify-center overflow-hidden rounded-lg border">
                                {cameraActive ? (
                                    <video ref={videoRef} className="size-full -scale-x-100 object-cover" muted playsInline />
                                ) : previewSrc ? (
                                    <img src={previewSrc} alt="Captured face" className="size-full object-cover" />
                                ) : (
                                    <Camera className="text-muted-foreground/50 size-12" />
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {!cameraActive && (
                                    <Button type="button" variant={imageData ? 'outline' : 'default'} onClick={startCamera} disabled={submitting}>
                                        {imageData ? <RotateCcw className="size-4" /> : <Camera className="size-4" />}
                                        {imageData ? 'Retake Photo' : 'Start Camera'}
                                    </Button>
                                )}
                                {cameraActive && (
                                    <>
                                        <Button type="button" onClick={captureFace}>
                                            <Camera className="size-4" />
                                            Capture Photo
                                        </Button>
                                        <Button type="button" variant="ghost" onClick={stopCamera}>
                                            Cancel
                                        </Button>
                                    </>
                                )}
                                {captureInfo && <p className="text-xs text-green-600">{captureInfo}</p>}
                            </div>
                        </div>
                    )}

                    {captureError && (
                        <Alert variant="destructive">
                            <AlertDescription>{captureError}</AlertDescription>
                        </Alert>
                    )}
                    {errors.imageData && <p className="text-destructive text-xs">{errors.imageData}</p>}
                </div>

                <DialogFooter className="items-center gap-2 sm:justify-between">
                    <div>
                        {method && (
                            <Badge variant={isReady ? 'default' : 'outline'} className="gap-1">
                                {isReady && <CheckCircle2 className="size-3" />}
                                {isReady ? 'Ready to issue' : 'Awaiting biometric data'}
                            </Badge>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                            Close
                        </Button>
                        {isReady && (
                            <Button type="button" className="bg-green-600 hover:bg-green-700" onClick={handleIssue} disabled={submitting}>
                                {submitting && <Loader2 className="size-4 animate-spin" />}
                                {submitting ? 'Issuing…' : 'Issue Approved Services'}
                            </Button>
                        )}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
