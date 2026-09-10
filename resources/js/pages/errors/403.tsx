import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';

export default function Forbidden() {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center">
            <Head title="403 - Access Denied" />

            <ShieldAlert className="text-muted-foreground h-12 w-12" />

            <div className="space-y-1">
                <h1 className="text-4xl font-semibold">403</h1>
                <h2 className="text-xl font-medium">Access Denied</h2>
            </div>

            <p className="text-muted-foreground max-w-md text-sm">You do not have permission to access this resource.</p>

            <Button asChild className="mt-2">
                <Link href={route('dashboard')}>Return to Dashboard</Link>
            </Button>
        </div>
    );
}
