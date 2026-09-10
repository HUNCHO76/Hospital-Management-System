import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface ConfirmDeleteDialogProps {
    title: string;
    description: string;
    deleteUrl: string;
    trigger: ReactNode;
    confirmLabel?: string;
}

/**
 * Generic delete-confirmation dialog reused by every resource's index page
 * (hospitals, departments, …) so confirmation UX and the Inertia delete
 * request aren't reimplemented per module.
 */
export function ConfirmDeleteDialog({ title, description, deleteUrl, trigger, confirmLabel = 'Delete' }: ConfirmDeleteDialogProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const confirmDelete = () => {
        setProcessing(true);

        router.delete(deleteUrl, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDelete} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
