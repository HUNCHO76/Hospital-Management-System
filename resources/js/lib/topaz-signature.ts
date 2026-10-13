/**
 * Topaz signature pad client (SigPlusExtLite v3).
 *
 * Typed port of the legacy NHIF-API `topaz-signature.blade.php` widget. The
 * browser extension injects a wrapper script URL into the `<html>` element via
 * the `SigPlusExtLiteWrapperURL` attribute; once that script loads it exposes a
 * global `Topaz` object that talks to the native host on the client PC.
 */

const WRAPPER_ATTRIBUTE = 'SigPlusExtLiteWrapperURL';
const WRAPPER_POLL_INTERVAL_MS = 500;
const WRAPPER_POLL_TIMEOUT_MS = 15000;
const MIN_SIGNATURE_POINTS = 10;

export type TopazPadState = 'ready' | 'no-device' | 'no-extension';

export class TopazError extends Error {
    constructor(
        public readonly code: 'NO_EXTENSION' | 'NO_DEVICE' | 'CONNECT_FAILED' | 'CAPTURE_FAILED',
        message: string,
    ) {
        super(message);
        this.name = 'TopazError';
    }
}

export interface TopazCaptureHandlers {
    /** Called with raw base64 JPEG (no `data:` prefix) once a signature is captured. */
    onSigned: (base64: string) => void;
    onError?: (error: TopazError) => void;
    penWidth?: number;
}

let wrapperPromise: Promise<boolean> | null = null;
let connected = false;

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

function isExtensionAvailable(): boolean {
    return typeof window.Topaz !== 'undefined' && typeof window.Topaz.Global !== 'undefined';
}

function injectWrapper(url: string): Promise<boolean> {
    return new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = url;
        script.onload = () => resolve(isExtensionAvailable());
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}

/**
 * Loads the extension wrapper (once), polling for the injected attribute for up
 * to 15 seconds like the legacy widget. Resolves `false` when the extension is absent.
 */
export function loadTopazWrapper(): Promise<boolean> {
    if (isExtensionAvailable()) {
        return Promise.resolve(true);
    }

    if (wrapperPromise) {
        return wrapperPromise;
    }

    wrapperPromise = (async () => {
        const deadline = Date.now() + WRAPPER_POLL_TIMEOUT_MS;

        while (Date.now() < deadline) {
            const url = document.documentElement.getAttribute(WRAPPER_ATTRIBUTE);

            if (url) {
                return injectWrapper(url);
            }

            await sleep(WRAPPER_POLL_INTERVAL_MS);
        }

        return false;
    })().then((available) => {
        if (!available) {
            wrapperPromise = null;
        }

        return available;
    });

    return wrapperPromise;
}

async function readDeviceStatus(): Promise<number> {
    const status = await window.Topaz!.Global.GetDeviceStatus();

    if (status === null || status === undefined) {
        throw new TopazError('CONNECT_FAILED', 'Topaz host is not reachable.');
    }

    if (status < 0) {
        const detail = await window.Topaz!.Global.GetLastError().catch(() => '');

        throw new TopazError('CONNECT_FAILED', `Topaz device error (${status}): ${detail}`);
    }

    return status;
}

/**
 * Connects to the pad. Treats `Connect() === -1` with an "already running" error as
 * an existing, usable connection, as the legacy widget does.
 */
export async function connectPad(): Promise<TopazPadState> {
    if (!(await loadTopazWrapper())) {
        return 'no-extension';
    }

    const global = window.Topaz!.Global;

    if (connected) {
        try {
            return (await readDeviceStatus()) > 0 ? 'ready' : 'no-device';
        } catch {
            connected = false;
        }
    }

    try {
        let result = await global.Connect();

        if (result === -1) {
            const lastError = await global.GetLastError().catch(() => '');

            if (!lastError.includes('already running')) {
                await global.Disconnect().catch(() => undefined);
                await sleep(600);
                result = await global.Connect();
            }
        }

        await sleep(300);
        const status = await readDeviceStatus();
        connected = true;

        return status > 0 ? 'ready' : 'no-device';
    } catch (error) {
        connected = false;

        throw error instanceof TopazError ? error : new TopazError('CONNECT_FAILED', 'Could not connect to the Topaz pad.');
    }
}

/** Forces the next `connectPad()` call to reconnect from scratch. */
export function resetTopazConnection(): void {
    connected = false;
    wrapperPromise = null;
}

/**
 * Starts live capture on the canvas. Resolves once the tablet is armed; the
 * signature itself is delivered through `handlers.onSigned` on pen-up.
 */
export async function startCapture(canvas: HTMLCanvasElement, handlers: TopazCaptureHandlers): Promise<void> {
    if (!isExtensionAvailable()) {
        throw new TopazError('NO_EXTENSION', 'Topaz SigPlusExtLite extension not detected.');
    }

    const sign = window.Topaz!.Canvas.Sign;
    const penWidth = handlers.penWidth ?? 1.5;

    try {
        await sign.SetDisplayPenWidth(penWidth);
        await sign.SetImagePenWidth(penWidth);
        await sign.ClearSign().catch(() => undefined);
        await sign.SetTabletState(1);
        await sign.StartSign(canvas);
        await sign.AddPenUpEventListener();
    } catch {
        throw new TopazError('CAPTURE_FAILED', 'Could not start signing on the Topaz pad.');
    }

    window.OnSignPenUp = async () => {
        try {
            await sleep(300);

            if ((await sign.GetTotalPoints()) < MIN_SIGNATURE_POINTS) {
                return;
            }

            const image = await sign.GetSignatureImage();

            await sign.StopSign();
            await sign.SetTabletState(0);
            await sign.RemovePenUpEventListener();
            window.OnSignPenUp = undefined;

            if (image) {
                handlers.onSigned(image);
            }
        } catch {
            handlers.onError?.(new TopazError('CAPTURE_FAILED', 'Signature capture failed.'));
        }
    };
}

/** Stops capture and releases the pad's tablet state. Safe to call at any time. */
export async function stopCapture(): Promise<void> {
    window.OnSignPenUp = undefined;

    if (!isExtensionAvailable()) {
        return;
    }

    const sign = window.Topaz!.Canvas.Sign;

    await sign.RemovePenUpEventListener().catch(() => undefined);
    await sign.StopSign().catch(() => undefined);
    await sign.SetTabletState(0).catch(() => undefined);
}

/** Clears strokes held by the pad and disarms it. */
export async function clearCapture(): Promise<void> {
    window.OnSignPenUp = undefined;

    if (!isExtensionAvailable()) {
        return;
    }

    const sign = window.Topaz!.Canvas.Sign;

    await sign.SetTabletState(0).catch(() => undefined);
    await sign.ClearSign().catch(() => undefined);
}
