/**
 * MorFin Auth fingerprint scanner client.
 *
 * Typed port of the legacy NHIF-API `morfinauth.js` + `fingerprint-auth.blade.php`
 * capture flow. The scanner service runs on the *client* PC, so the browser talks
 * to it directly. Override the base URL with `VITE_MORFIN_AUTH_URL`.
 */

const DEFAULT_BASE_URL = 'https://localhost:8030/morfinauth/';

/** WSQ image format code expected by NHIF. */
const WSQ_IMAGE_FORMAT = 2;

/** SDK error code returned when a previous device session was left open. */
const DEVICE_ALREADY_INITIALIZED = '-2023';

export type MorFinErrorCode = 'DEVICE_NOT_CONNECTED' | 'NO_DEVICE_FOUND' | 'FINGER_NOT_CAPTURED' | 'CAPTURE_TIMEOUT' | string;

export class MorFinError extends Error {
    constructor(
        public readonly code: MorFinErrorCode,
        message: string,
    ) {
        super(message);
        this.name = 'MorFinError';
    }
}

export interface FingerprintCaptureResult {
    /** Raw base64 (no data: prefix) to send to NHIF as imageData — WSQ when available, BMP fallback. */
    base64: string;
    /** `data:` URL suitable for an <img> preview (BMP). */
    previewSrc: string;
    quality: number | null;
    nfiq: number | null;
    format: 'WSQ' | 'BMP';
}

type SdkData = Record<string, unknown>;

export function getMorFinBaseUrl(): string {
    const configured = (import.meta.env.VITE_MORFIN_AUTH_URL as string | undefined)?.trim();
    const base = configured || DEFAULT_BASE_URL;

    return base.endsWith('/') ? base : `${base}/`;
}

function firstValue<T = unknown>(...values: unknown[]): T | undefined {
    return values.find((v) => v !== undefined && v !== null && v !== '') as T | undefined;
}

function stripDataPrefix(value: string): string {
    return value.replace(/^data:[^;]+;base64,/, '').trim();
}

async function post(method: string, body?: Record<string, unknown>): Promise<SdkData> {
    let response: Response;

    try {
        response = await fetch(`${getMorFinBaseUrl()}${method}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch {
        throw new MorFinError(
            'DEVICE_NOT_CONNECTED',
            `Cannot reach the MorFin Auth service at ${getMorFinBaseUrl()}. Make sure the service is running and its certificate is trusted.`,
        );
    }

    if (!response.ok) {
        throw new MorFinError('DEVICE_NOT_CONNECTED', `MorFin service responded with HTTP ${response.status}.`);
    }

    let data: unknown;

    try {
        data = await response.json();
    } catch {
        throw new MorFinError('DEVICE_NOT_CONNECTED', 'MorFin service returned an invalid response.');
    }

    if (Array.isArray(data)) {
        data = data[0];
    }

    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch {
            // leave as-is
        }
    }

    const sdkData = (data && typeof data === 'object' ? data : {}) as SdkData;
    const errorCode = sdkData.ErrorCode;

    if (errorCode !== undefined && String(errorCode) !== '0') {
        throw new MorFinError(
            String(errorCode),
            String(firstValue(sdkData.ErrorDescription, sdkData.errorDescription) ?? `Device error ${String(errorCode)}.`),
        );
    }

    return sdkData;
}

export async function getConnectedDevices(): Promise<string[]> {
    const data = await post('connecteddevicelist');

    let raw = firstValue<unknown>(data.DeviceList, data.deviceList, data.ConnectedDevices, data.connectedDevices, data.ErrorDescription) ?? '';

    if (typeof raw !== 'string') {
        raw = Array.isArray(raw) ? raw.join(',') : JSON.stringify(raw);
    }

    let list = raw as string;
    const parts = list.split(':');

    if (parts.length > 1) {
        list = parts.slice(1).join(':');
    }

    return list
        .split(',')
        .map((name) => name.trim())
        .filter(Boolean);
}

export async function uninitDevice(): Promise<void> {
    try {
        await post('uninitdevice');
    } catch {
        // Ignore — device may simply not have been initialized.
    }
}

export async function initDevice(deviceName: string): Promise<void> {
    try {
        await post('initdevice', { ConnectedDvc: deviceName, ClientKey: '' });
    } catch (error) {
        if (error instanceof MorFinError && error.code === DEVICE_ALREADY_INITIALIZED) {
            await uninitDevice();
            await post('initdevice', { ConnectedDvc: deviceName, ClientKey: '' });

            return;
        }

        throw error;
    }
}

/**
 * Full capture flow: reset → pick first connected device → init → capture (BMP preview)
 * → fetch WSQ image (falls back to BMP if WSQ retrieval fails).
 */
export async function captureFingerprint({ quality = 60, timeout = 10 }: { quality?: number; timeout?: number } = {}): Promise<FingerprintCaptureResult> {
    await uninitDevice();

    const devices = await getConnectedDevices();

    if (devices.length === 0) {
        throw new MorFinError('NO_DEVICE_FOUND', 'No fingerprint scanner detected. Plug in the device and try again.');
    }

    await initDevice(devices[0]);

    let captureData: SdkData;

    try {
        captureData = await post('capture', { Quality: quality, TimeOut: timeout });
    } catch (error) {
        if (error instanceof MorFinError && /time\s*out/i.test(error.message)) {
            throw new MorFinError('CAPTURE_TIMEOUT', 'Capture timed out. Ask the patient to place their finger on the scanner again.');
        }

        throw error;
    }

    const bitmap = firstValue<string>(
        captureData.BitmapData,
        captureData.bitmapData,
        captureData.ImageData,
        captureData.imageData,
        captureData.Bitmap,
        captureData.bitmap,
        captureData.FingerImage,
        captureData.fingerImage,
    );

    if (!bitmap) {
        throw new MorFinError('FINGER_NOT_CAPTURED', 'No finger detected by the scanner. Please try again.');
    }

    const bmpBase64 = stripDataPrefix(String(bitmap));
    let base64 = bmpBase64;
    let format: FingerprintCaptureResult['format'] = 'BMP';

    try {
        const imageData = await post('getimage', { ImgFormat: WSQ_IMAGE_FORMAT });
        const wsq = firstValue<string>(imageData.ImgData, imageData.imgData, imageData.ImageData, imageData.imageData);

        if (wsq) {
            base64 = stripDataPrefix(String(wsq));
            format = 'WSQ';
        }
    } catch (error) {
        console.warn('MorFin WSQ retrieval failed, using BMP fallback:', error);
    }

    const toNumber = (value: unknown): number | null => {
        const n = Number(value);

        return Number.isFinite(n) ? n : null;
    };

    return {
        base64,
        previewSrc: `data:image/bmp;base64,${bmpBase64}`,
        quality: toNumber(firstValue(captureData.Quality, captureData.quality)),
        nfiq: toNumber(firstValue(captureData.Nfiq, captureData.nfiq)),
        format,
    };
}
