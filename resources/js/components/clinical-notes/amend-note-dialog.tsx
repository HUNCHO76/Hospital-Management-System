import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type AmendClinicalNoteFormData, type ClinicalNote } from '@/types/clinical-note';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface AmendNoteDialogProps {
    encounterId: number;
    note: ClinicalNote;
    trigger: ReactNode;
}

/**
 * The controlled amendment workflow for a signed/amended note — the
 * pre-amendment content is preserved as its own version on the backend
 * (ClinicalNoteService::amend()), so the original is never lost. A reason is
 * always required.
 */
export function AmendNoteDialog({ encounterId, note, trigger }: AmendNoteDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<AmendClinicalNoteFormData>({
        note_type: note.note_type,
        note_content: note.note_content,
        amendment_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.clinical-notes.amend', [encounterId, note.id]), {
            preserveScroll: true,
            onSuccess: () => {
                reset('amendment_reason');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Amend clinical note</DialogTitle>
                        <DialogDescription>
                            This note is {note.status}. The original content is preserved in the version history — amend only what is clinically
                            necessary and record why.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="amend_note_content">Note Content</Label>
                            <Textarea
                                id="amend_note_content"
                                value={data.note_content}
                                onChange={(e) => setData('note_content', e.target.value)}
                                rows={5}
                            />
                            <InputError message={errors.note_content} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="note_amendment_reason">Reason for amendment *</Label>
                            <Textarea
                                id="note_amendment_reason"
                                value={data.amendment_reason}
                                onChange={(e) => setData('amendment_reason', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.amendment_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save Amendment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
