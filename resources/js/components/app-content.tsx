import { FlashToaster } from '@/components/flash-toaster';
import { SidebarInset } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/toast';
import { ToastProvider } from '@/hooks/use-toast';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
}

export function AppContent({ variant = 'header', children, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset {...props}>
                <ToastProvider>
                    <FlashToaster />
                    {children}
                    <Toaster />
                </ToastProvider>
            </SidebarInset>
        );
    }

    return (
        <main className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl" {...props}>
            <ToastProvider>
                <FlashToaster />
                {children}
                <Toaster />
            </ToastProvider>
        </main>
    );
}
