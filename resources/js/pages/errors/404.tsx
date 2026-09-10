import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { FileQuestion } from 'lucide-react';

export default function NotFound() {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center">
            <Head title="404 - Page Not Found" />

            <FileQuestion className="text-muted-foreground h-12 w-12" />

            <div className="space-y-1">
                <h1 className="text-4xl font-semibold">404</h1>
                <h2 className="text-xl font-medium">Page Not Found</h2>
            </div>

            <p className="text-muted-foreground max-w-md text-sm">The page you are looking for does not exist or may have been moved.</p>

            <Button asChild className="mt-2">
                <Link href={route('dashboard')}>Return to Dashboard</Link>
            </Button>
        </div>
    );
}
