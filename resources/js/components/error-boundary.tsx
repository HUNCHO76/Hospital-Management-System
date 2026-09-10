import { Component, type ErrorInfo, type ReactNode } from 'react';

interface ErrorBoundaryProps {
    children: ReactNode;
}

interface ErrorBoundaryState {
    hasError: boolean;
}

/**
 * A top-level error boundary. If an unexpected rendering error escapes a page,
 * the user sees a safe fallback with a reload action instead of a blank screen
 * or a stack trace. The technical error is still logged to the console for
 * developers.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
    state: ErrorBoundaryState = { hasError: false };

    static getDerivedStateFromError(): ErrorBoundaryState {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        // Developer-facing only; never surfaced to end users.
        console.error('Unhandled UI error:', error, info.componentStack);
    }

    render(): ReactNode {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">Something went wrong</h1>
                    <p className="text-muted-foreground text-sm">An unexpected error occurred. Please reload the page and try again.</p>
                </div>
                <button
                    type="button"
                    onClick={() => window.location.reload()}
                    className="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex h-9 items-center rounded-md px-4 text-sm font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    Reload page
                </button>
            </div>
        );
    }
}
