import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';

export type ToastVariant = 'success' | 'error' | 'warning' | 'info';

export interface ToastItem {
    id: number;
    title?: string;
    description: string;
    variant: ToastVariant;
}

interface ToastInput {
    description: string;
    title?: string;
    variant?: ToastVariant;
    /** Milliseconds before auto-dismiss; 0 keeps the toast until dismissed. */
    duration?: number;
}

interface ToastContextValue {
    toasts: ToastItem[];
    toast: (input: ToastInput) => void;
    dismiss: (id: number) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

/**
 * A minimal, dependency-free toast store. Mounted once inside the app layout
 * so any page can raise success/error/warning/info notifications and the
 * flash-message bridge can surface Laravel session flashes consistently.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<ToastItem[]>([]);
    const idRef = useRef(0);

    const dismiss = useCallback((id: number) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const toast = useCallback(
        ({ description, title, variant = 'info', duration = 5000 }: ToastInput) => {
            const id = (idRef.current += 1);

            setToasts((current) => [...current, { id, title, description, variant }]);

            if (duration > 0) {
                setTimeout(() => dismiss(id), duration);
            }
        },
        [dismiss],
    );

    const value = useMemo(() => ({ toasts, toast, dismiss }), [toasts, toast, dismiss]);

    return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>;
}

export function useToast(): ToastContextValue {
    const context = useContext(ToastContext);

    if (!context) {
        throw new Error('useToast must be used within a ToastProvider.');
    }

    return context;
}
