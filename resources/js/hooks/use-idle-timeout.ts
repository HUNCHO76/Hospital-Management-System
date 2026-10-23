import { useCallback, useEffect, useRef, useState } from 'react';

const STORAGE_KEY = 'hms_last_activity';
const LOGOUT_BROADCAST_KEY = 'hms_session_logged_out';
const DEFAULT_TIMEOUT_MS = 5 * 60 * 1000; // 5 minutes
const DEFAULT_WARNING_MS = 60 * 1000;      // 60 seconds warning before logout
const THROTTLE_MS = 1000;                  // Throttle activity updates to once per second

export interface UseIdleTimeoutOptions {
    timeoutMs?: number;
    warningMs?: number;
    enabled?: boolean;
    onLogout?: () => void;
}

export function useIdleTimeout({
    timeoutMs = DEFAULT_TIMEOUT_MS,
    warningMs = DEFAULT_WARNING_MS,
    enabled = true,
    onLogout,
}: UseIdleTimeoutOptions = {}) {
    const [isWarningOpen, setIsWarningOpen] = useState(false);
    const [secondsRemaining, setSecondsRemaining] = useState(Math.ceil(warningMs / 1000));
    const [isLoggingOut, setIsLoggingOut] = useState(false);

    const lastActivityRef = useRef<number>(Date.now());
    const lastBroadcastRef = useRef<number>(0);
    const isLoggingOutRef = useRef<boolean>(false);
    const isWarningOpenRef = useRef<boolean>(false);

    // Keep ref in sync with state for access in passive event listeners
    isWarningOpenRef.current = isWarningOpen;

    const performLogout = useCallback(() => {
        if (isLoggingOutRef.current) return;
        isLoggingOutRef.current = true;
        setIsLoggingOut(true);

        try {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.setItem(LOGOUT_BROADCAST_KEY, Date.now().toString());
        } catch {
            // Ignore storage errors
        }

        if (onLogout) {
            onLogout();
            return;
        }

        // Guaranteed hard logout & redirect:
        // Create a standard HTML form to post to /logout with CSRF token and inactivity flag.
        // A native form submission is processed directly by the browser and follows the
        // backend 302 redirect to /login with a complete page reload, bypassing any Inertia 419 traps.
        try {
            const csrfToken =
                (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ||
                (document.querySelector('input[name="_token"]') as HTMLInputElement)?.value;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = typeof route !== 'undefined' ? route('logout') : '/logout';

            if (csrfToken) {
                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = '_token';
                tokenInput.value = csrfToken;
                form.appendChild(tokenInput);
            }

            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'inactivity';
            reasonInput.value = '1';
            form.appendChild(reasonInput);

            document.body.appendChild(form);
            form.submit();
        } catch {
            // Fallback direct navigation if form creation fails
            window.location.replace('/login');
        }

        // Safety fallback timer: if the browser hasn't navigated after 800ms, force navigation
        setTimeout(() => {
            window.location.replace('/login');
        }, 800);
    }, [onLogout]);

    const resetTimer = useCallback(() => {
        if (isLoggingOutRef.current) return;

        const now = Date.now();
        lastActivityRef.current = now;
        setIsWarningOpen(false);
        setSecondsRemaining(Math.ceil(warningMs / 1000));

        // Sync with other tabs via localStorage (throttled)
        if (now - lastBroadcastRef.current >= THROTTLE_MS) {
            lastBroadcastRef.current = now;
            try {
                localStorage.setItem(STORAGE_KEY, now.toString());
            } catch {
                // Ignore storage errors
            }
        }
    }, [warningMs]);

    // Initial sync with localStorage on mount
    useEffect(() => {
        if (!enabled) return;

        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            const now = Date.now();
            if (stored) {
                const storedTime = parseInt(stored, 10);
                if (!isNaN(storedTime) && storedTime <= now && now - storedTime < timeoutMs) {
                    lastActivityRef.current = storedTime;
                } else if (!isNaN(storedTime) && now - storedTime >= timeoutMs) {
                    // Previous session was already idle past timeout
                    performLogout();
                    return;
                } else {
                    localStorage.setItem(STORAGE_KEY, now.toString());
                }
            } else {
                localStorage.setItem(STORAGE_KEY, now.toString());
            }
        } catch {
            // Ignore storage errors
        }
    }, [enabled, timeoutMs, performLogout]);

    // Listen to user activity events
    useEffect(() => {
        if (!enabled) return;

        const handleUserActivity = () => {
            // Do NOT reset activity if already logging out or if the warning dialog is open
            // (When warning is open, user MUST explicitly click "Stay Logged In")
            if (isLoggingOutRef.current || isWarningOpenRef.current) {
                return;
            }

            const now = Date.now();
            if (now - lastActivityRef.current >= THROTTLE_MS) {
                resetTimer();
            }
        };

        const events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'wheel'];
        events.forEach((event) => {
            window.addEventListener(event, handleUserActivity, { passive: true });
        });

        // Listen for activity or logout from other tabs
        const handleStorage = (event: StorageEvent) => {
            if (event.key === LOGOUT_BROADCAST_KEY) {
                window.location.replace('/login');
                return;
            }

            if (event.key === STORAGE_KEY && event.newValue) {
                const updatedTime = parseInt(event.newValue, 10);
                if (!isNaN(updatedTime)) {
                    lastActivityRef.current = updatedTime;
                    setIsWarningOpen(false);
                    setSecondsRemaining(Math.ceil(warningMs / 1000));
                }
            }
        };
        window.addEventListener('storage', handleStorage);

        // Check when tab becomes visible again
        const handleVisibilityChange = () => {
            if (document.visibilityState === 'visible' && !isLoggingOutRef.current) {
                const now = Date.now();
                const elapsed = now - lastActivityRef.current;
                if (elapsed >= timeoutMs) {
                    performLogout();
                } else if (elapsed >= timeoutMs - warningMs) {
                    setIsWarningOpen(true);
                    setSecondsRemaining(Math.max(0, Math.ceil((timeoutMs - elapsed) / 1000)));
                }
            }
        };
        document.addEventListener('visibilitychange', handleVisibilityChange);

        return () => {
            events.forEach((event) => {
                window.removeEventListener(event, handleUserActivity);
            });
            window.removeEventListener('storage', handleStorage);
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        };
    }, [enabled, resetTimer, timeoutMs, warningMs, performLogout]);

    // Interval ticker to check idle duration and update countdown
    useEffect(() => {
        if (!enabled) return;

        const interval = setInterval(() => {
            if (isLoggingOutRef.current) return;

            const now = Date.now();
            const elapsed = now - lastActivityRef.current;

            if (elapsed >= timeoutMs) {
                performLogout();
            } else if (elapsed >= timeoutMs - warningMs) {
                setIsWarningOpen(true);
                const remaining = Math.max(0, Math.ceil((timeoutMs - elapsed) / 1000));
                setSecondsRemaining(remaining);
                if (remaining <= 0) {
                    performLogout();
                }
            } else {
                if (isWarningOpenRef.current) {
                    setIsWarningOpen(false);
                }
            }
        }, 1000);

        return () => clearInterval(interval);
    }, [enabled, timeoutMs, warningMs, performLogout]);

    return {
        isWarningOpen,
        secondsRemaining,
        isLoggingOut,
        stayLoggedIn: resetTimer,
        logoutNow: performLogout,
    };
}
