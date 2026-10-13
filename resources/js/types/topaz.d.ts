/**
 * Minimal typings for the Topaz SigPlusExtLite v3 browser-extension wrapper.
 * The wrapper script is injected by the extension (see `lib/topaz-signature.ts`)
 * and exposes a global `Topaz` object.
 */
interface TopazGlobal {
    Connect(): Promise<number>;
    Disconnect(): Promise<unknown>;
    GetDeviceStatus(): Promise<number | null>;
    GetLastError(): Promise<string>;
}

interface TopazSign {
    SetDisplayPenWidth(width: number): Promise<unknown>;
    SetImagePenWidth(width: number): Promise<unknown>;
    ClearSign(): Promise<unknown>;
    SetTabletState(state: 0 | 1): Promise<unknown>;
    StartSign(canvas: HTMLCanvasElement): Promise<unknown>;
    StopSign(): Promise<unknown>;
    AddPenUpEventListener(): Promise<unknown>;
    RemovePenUpEventListener(): Promise<unknown>;
    GetTotalPoints(): Promise<number>;
    GetSignatureImage(): Promise<string>;
    GetSigString(): Promise<string>;
}

interface TopazNamespace {
    Global: TopazGlobal;
    Canvas: { Sign: TopazSign };
}

interface Window {
    Topaz?: TopazNamespace;
    /** Invoked by the Topaz wrapper whenever the pen is lifted. */
    OnSignPenUp?: () => void | Promise<void>;
}
