import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useIdleTimeout, type UseIdleTimeoutOptions } from '@/hooks/use-idle-timeout';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Clock, LoaderCircle, LogOut, ShieldAlert } from 'lucide-react';

interface SessionTimeoutDialogProps extends UseIdleTimeoutOptions {
    /** Override whether the idle monitor is enabled (defaults to user being authenticated) */
    enabled?: boolean;
}

export function SessionTimeoutDialog({
    timeoutMs = 5 * 60 * 1000, // 5 minutes
    warningMs = 60 * 1000,      // 60 seconds warning
    enabled,
    onLogout,
}: SessionTimeoutDialogProps = {}) {
    const { auth } = usePage<SharedData>().props;
    const isUserLoggedIn = Boolean(auth?.user);
    const isEnabled = enabled !== undefined ? enabled : isUserLoggedIn;

    const { isWarningOpen, secondsRemaining, isLoggingOut, stayLoggedIn, logoutNow } = useIdleTimeout({
        timeoutMs,
        warningMs,
        enabled: isEnabled,
        onLogout,
    });

    if (!isEnabled) {
        return null;
    }

    const minutes = Math.floor(secondsRemaining / 60);
    const seconds = secondsRemaining % 60;
    const formattedTime = minutes > 0
        ? `${minutes}:${seconds.toString().padStart(2, '0')}`
        : `${seconds}s`;

    return (
        <Dialog open={isWarningOpen || isLoggingOut}>
            <DialogContent
                className="max-w-md sm:max-w-lg border-amber-200 dark:border-amber-900/60 shadow-2xl [&>button]:hidden"
                onPointerDownOutside={(e) => e.preventDefault()}
                onEscapeKeyDown={(e) => e.preventDefault()}
            >
                <DialogHeader className="space-y-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                            <ShieldAlert className="h-5 w-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-left text-lg font-bold text-gray-900 dark:text-gray-100">
                                Inactivity Notice
                            </DialogTitle>
                            <p className="text-xs text-muted-foreground">Hospital Management Security</p>
                        </div>
                    </div>

                    <DialogDescription className="text-left text-sm text-gray-600 dark:text-gray-300">
                        {isLoggingOut ? (
                            'Your session has expired. Logging out and redirecting to the login page...'
                        ) : (
                            'You have been inactive for over 4 minutes. To safeguard patient data and system security, you will be automatically logged out in:'
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div className="my-2 flex items-center justify-center rounded-xl bg-amber-50/80 p-4 border border-amber-200/60 dark:bg-amber-950/30 dark:border-amber-800/40">
                    {isLoggingOut ? (
                        <div className="flex items-center gap-3 py-2 text-amber-700 dark:text-amber-300">
                            <LoaderCircle className="h-7 w-7 animate-spin" />
                            <span className="font-medium text-base">Logging out...</span>
                        </div>
                    ) : (
                        <div className="flex items-center gap-3">
                            <Clock className="h-6 w-6 animate-pulse text-amber-600 dark:text-amber-400" />
                            <span className="font-mono text-3xl font-bold tracking-tight text-amber-700 dark:text-amber-300">
                                {formattedTime}
                            </span>
                        </div>
                    )}
                </div>

                <DialogFooter className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={logoutNow}
                        disabled={isLoggingOut}
                        className="flex items-center gap-2 border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    >
                        <LogOut className="h-4 w-4" />
                        Log Out Now
                    </Button>
                    <Button
                        type="button"
                        onClick={stayLoggedIn}
                        disabled={isLoggingOut}
                        autoFocus
                        className="bg-emerald-600 hover:bg-emerald-700 text-white font-medium shadow-sm transition-all"
                    >
                        Stay Logged In
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
