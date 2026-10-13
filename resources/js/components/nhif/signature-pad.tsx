import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { clearCapture, connectPad, resetTopazConnection, startCapture, stopCapture, TopazError, type TopazPadState } from '@/lib/topaz-signature';
import { Eraser, Loader2, PenLine, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const CANVAS_WIDTH = 500;
const CANVAS_HEIGHT = 160;
const PEN_COLOR = '#000080';
const MIN_FALLBACK_POINTS = 10;

type PadMode = 'checking' | 'topaz' | 'fallback';

interface SignaturePadProps {
    /** Raw base64 JPEG (no `data:` prefix), or null when nothing is captured. */
    value: string | null;
    onChange: (base64: string | null) => void;
    disabled?: boolean;
}

function stripDataPrefix(dataUrl: string): string {
    return dataUrl.replace(/^data:[^;]+;base64,/, '').trim();
}

/**
 * Signature capture for NHIF SignFolio (Manual Signature).
 *
 * Uses a Topaz pad (SigPlusExtLite) when one is connected; otherwise falls back to
 * on-screen mouse/touch/pen drawing. Both paths emit a base64 JPEG on a white background.
 */
export function SignaturePad({ value, onChange, disabled = false }: SignaturePadProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawingRef = useRef(false);
    const pointsRef = useRef(0);
    const hasInkRef = useRef(false);

    const [mode, setMode] = useState<PadMode>('checking');
    const [padState, setPadState] = useState<TopazPadState | null>(null);
    const [signing, setSigning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const resetCanvas = useCallback(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        hasInkRef.current = false;
        pointsRef.current = 0;
    }, []);

    const detectPad = useCallback(async () => {
        setMode('checking');
        setError(null);

        try {
            const state = await connectPad();
            setPadState(state);
            setMode(state === 'ready' ? 'topaz' : 'fallback');
        } catch (err) {
            setPadState(null);
            setMode('fallback');
            setError(err instanceof TopazError ? err.message : 'Could not connect to the Topaz pad.');
        }
    }, []);

    useEffect(() => {
        resetCanvas();
        void detectPad();

        return () => {
            void stopCapture();
        };
    }, [detectPad, resetCanvas]);

    // Parent cleared the value (dialog closed, method switched) — wipe the canvas too.
    useEffect(() => {
        if (value === null && hasInkRef.current) {
            resetCanvas();
            void clearCapture();
            setSigning(false);
        }
    }, [value, resetCanvas]);

    const drawCapturedImage = (base64: string) => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        const image = new Image();
        image.onload = () => {
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
        };
        image.src = `data:image/jpeg;base64,${base64}`;
    };

    const handleStartTopaz = async () => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        setError(null);
        setSigning(true);
        resetCanvas();
        onChange(null);

        try {
            await startCapture(canvas, {
                penWidth: 1.5,
                onSigned: (base64) => {
                    hasInkRef.current = true;
                    drawCapturedImage(base64);
                    setSigning(false);
                    onChange(stripDataPrefix(base64));
                },
                onError: (err) => {
                    setSigning(false);
                    setError(err.message);
                },
            });
        } catch (err) {
            setSigning(false);
            setError(err instanceof TopazError ? err.message : 'Could not start signing on the Topaz pad.');
        }
    };

    const handleClear = async () => {
        await clearCapture();
        resetCanvas();
        setSigning(false);
        setError(null);
        onChange(null);
    };

    const handleRetry = async () => {
        resetTopazConnection();
        await detectPad();
    };

    const pointerPosition = (event: React.PointerEvent<HTMLCanvasElement>) => {
        const canvas = event.currentTarget;
        const rect = canvas.getBoundingClientRect();

        return {
            x: ((event.clientX - rect.left) / rect.width) * canvas.width,
            y: ((event.clientY - rect.top) / rect.height) * canvas.height,
        };
    };

    const handlePointerDown = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (mode !== 'fallback' || disabled) {
            return;
        }

        const context = event.currentTarget.getContext('2d');

        if (!context) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);

        if (!hasInkRef.current) {
            onChange(null);
        }

        const { x, y } = pointerPosition(event);
        drawingRef.current = true;
        context.strokeStyle = PEN_COLOR;
        context.lineWidth = 2;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.beginPath();
        context.moveTo(x, y);
    };

    const handlePointerMove = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (!drawingRef.current) {
            return;
        }

        const context = event.currentTarget.getContext('2d');

        if (!context) {
            return;
        }

        const { x, y } = pointerPosition(event);
        context.lineTo(x, y);
        context.stroke();
        pointsRef.current += 1;
        hasInkRef.current = true;
    };

    const handlePointerUp = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (!drawingRef.current) {
            return;
        }

        drawingRef.current = false;

        if (pointsRef.current >= MIN_FALLBACK_POINTS) {
            onChange(stripDataPrefix(event.currentTarget.toDataURL('image/jpeg', 0.92)));
        }
    };

    const badge = (() => {
        if (mode === 'checking') {
            return { label: 'Checking pad…', className: 'border-muted-foreground text-muted-foreground' };
        }

        if (mode === 'topaz') {
            return signing
                ? { label: 'Signing…', className: 'border-sky-500 text-sky-700 dark:text-sky-300' }
                : value
                  ? { label: 'Signed ✓', className: 'border-emerald-500 text-emerald-700 dark:text-emerald-300' }
                  : { label: 'Topaz pad ready', className: 'border-emerald-500 text-emerald-700 dark:text-emerald-300' };
        }

        return value
            ? { label: 'Signed ✓', className: 'border-emerald-500 text-emerald-700 dark:text-emerald-300' }
            : { label: 'On-screen signing', className: 'border-amber-500 text-amber-700 dark:text-amber-300' };
    })();

    const fallbackReason =
        padState === 'no-extension'
            ? 'Topaz SigPlusExtLite extension not detected.'
            : padState === 'no-device'
              ? 'Topaz pad not found — plug it in via USB.'
              : null;

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <span className="text-xs font-semibold">Signatory Signature</span>
                <Badge variant="outline" className={`gap-1 text-xs ${badge.className}`}>
                    {mode === 'checking' && <Loader2 className="h-3 w-3 animate-spin" />}
                    {badge.label}
                </Badge>
            </div>

            {mode === 'fallback' && (fallbackReason || error) && (
                <Alert className="py-2 text-xs">
                    <AlertDescription className="flex items-center justify-between gap-2">
                        <span>
                            {error ?? fallbackReason} Sign on the screen below with a mouse, finger or stylus, or connect the pad and retry.
                        </span>
                        <Button type="button" variant="outline" size="sm" className="h-7 shrink-0 text-xs" onClick={handleRetry}>
                            <RefreshCw className="mr-1 h-3 w-3" />
                            Retry
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {mode === 'topaz' && error && (
                <Alert variant="destructive" className="py-2 text-xs">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <div className="relative overflow-hidden rounded-md border bg-white">
                <canvas
                    ref={canvasRef}
                    width={CANVAS_WIDTH}
                    height={CANVAS_HEIGHT}
                    className={`block w-full touch-none ${mode === 'fallback' && !disabled ? 'cursor-crosshair' : ''}`}
                    style={{ aspectRatio: `${CANVAS_WIDTH} / ${CANVAS_HEIGHT}` }}
                    onPointerDown={handlePointerDown}
                    onPointerMove={handlePointerMove}
                    onPointerUp={handlePointerUp}
                    onPointerCancel={handlePointerUp}
                />
                {!value && !signing && (
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center gap-2 text-xs text-slate-400">
                        <PenLine className="h-4 w-4" />
                        {mode === 'topaz' ? 'Click “Start Signing”, then sign on the Topaz pad' : 'Sign here'}
                    </div>
                )}
            </div>

            <div className="flex items-center gap-2">
                {mode === 'topaz' && (
                    <Button
                        type="button"
                        size="sm"
                        className="h-8 bg-emerald-600 text-xs text-white hover:bg-emerald-700"
                        onClick={handleStartTopaz}
                        disabled={disabled || signing}
                    >
                        {signing ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <PenLine className="mr-1.5 h-3.5 w-3.5" />}
                        {value ? 'Re-sign' : 'Start Signing'}
                    </Button>
                )}
                <Button type="button" variant="ghost" size="sm" className="h-8 text-xs" onClick={handleClear} disabled={disabled}>
                    <Eraser className="mr-1.5 h-3.5 w-3.5" />
                    Clear
                </Button>
            </div>
        </div>
    );
}
