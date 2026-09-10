import { AmendNoteDialog } from '@/components/clinical-notes/amend-note-dialog';
import { CancelNoteDialog } from '@/components/clinical-notes/cancel-note-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import {
    canAmendClinicalNote,
    canCancelClinicalNote,
    canSignClinicalNote,
    clinicalNoteStatusLabel,
    clinicalNoteStatusVariant,
    clinicalNoteTypeLabel,
    isClinicalNoteDraft,
} from '@/lib/clinical-note-status';
import { type ClinicalNote, type ClinicalNoteFormData, type ClinicalNoteType } from '@/types/clinical-note';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown, FilePlus2, LoaderCircle, PenLine, Signature, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface ClinicalNotesSectionProps {
    encounterId: number;
    clinicalNotes: ClinicalNote[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

const NOTE_TYPES: ClinicalNoteType[] = ['progress', 'follow_up', 'nursing', 'clinical_observation', 'procedure', 'other'];

/**
 * The clinical notes workflow embedded on the clinical encounter screen,
 * mirroring DiagnosisSection/CarePlanSection — there is no standalone
 * clinical notes page. Note content is always rendered as plain text
 * (React's default escaping), never dangerouslySetInnerHTML.
 */
export function ClinicalNotesSection({ encounterId, clinicalNotes }: ClinicalNotesSectionProps) {
    const { can } = usePermissions();

    const runSign = (noteId: number) => {
        router.post(route('clinical-encounters.clinical-notes.sign', [encounterId, noteId]), {}, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Clinical Notes</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {clinicalNotes.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {clinicalNotes.map((note) => (
                            <li key={note.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="outline">{clinicalNoteTypeLabel[note.note_type]}</Badge>
                                        <Badge variant={clinicalNoteStatusVariant[note.status]}>{clinicalNoteStatusLabel[note.status]}</Badge>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        {canSignClinicalNote(note.status) && can('clinical-notes.sign') && (
                                            <Button size="sm" onClick={() => runSign(note.id)}>
                                                <Signature className="h-4 w-4" />
                                                Sign
                                            </Button>
                                        )}

                                        {canAmendClinicalNote(note.status) && can('clinical-notes.amend') && (
                                            <AmendNoteDialog
                                                encounterId={encounterId}
                                                note={note}
                                                trigger={
                                                    <Button size="sm" variant="outline">
                                                        <PenLine className="h-4 w-4" />
                                                        Amend
                                                    </Button>
                                                }
                                            />
                                        )}

                                        {canCancelClinicalNote(note.status) && can('clinical-notes.cancel') && (
                                            <CancelNoteDialog
                                                encounterId={encounterId}
                                                noteId={note.id}
                                                trigger={
                                                    <Button size="sm" variant="destructive">
                                                        <XCircle className="h-4 w-4" />
                                                        Cancel
                                                    </Button>
                                                }
                                            />
                                        )}
                                    </div>
                                </div>

                                {isClinicalNoteDraft(note.status) && can('clinical-notes.update') ? (
                                    <EditNoteForm encounterId={encounterId} note={note} />
                                ) : (
                                    <>
                                        <p className="text-sm whitespace-pre-wrap">{note.note_content}</p>
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                            <span>{note.author?.name ?? '—'}</span>
                                            <span>{formatDateTime(note.created_at)}</span>
                                            {note.signed_at && <span>Signed {formatDateTime(note.signed_at)}</span>}
                                            {note.status === 'cancelled' && note.cancellation_reason && (
                                                <span>Cancelled: {note.cancellation_reason}</span>
                                            )}
                                            {note.corrected_at && <span>Amended {formatDateTime(note.corrected_at)}</span>}
                                        </div>
                                    </>
                                )}

                                {note.versions.length > 0 && <VersionHistory note={note} />}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No clinical notes have been recorded for this encounter yet.</p>
                )}

                {can('clinical-notes.create') && <AddNoteForm encounterId={encounterId} />}
            </CardContent>
        </Card>
    );
}

function VersionHistory({ note }: { note: ClinicalNote }) {
    const [open, setOpen] = useState(false);

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="rounded-md border">
            <CollapsibleTrigger asChild>
                <button type="button" className="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-medium">
                    Version History ({note.versions.length})
                    <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-3 border-t p-3">
                {note.versions.map((version) => (
                    <div key={version.id} className="space-y-1 text-xs">
                        <div className="flex flex-wrap items-center gap-2 font-medium">
                            <span>Version {version.version_number}</span>
                            <span className="text-muted-foreground font-normal">
                                {version.author?.name ?? '—'} · {formatDateTime(version.created_at)}
                            </span>
                        </div>
                        <p className="text-muted-foreground whitespace-pre-wrap">{version.note_content}</p>
                        {version.reason && <p className="text-muted-foreground">Reason: {version.reason}</p>}
                    </div>
                ))}
            </CollapsibleContent>
        </Collapsible>
    );
}

function AddNoteForm({ encounterId }: { encounterId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm<ClinicalNoteFormData>({
        note_type: '',
        note_content: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.clinical-notes.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-medium">Add Clinical Note</h3>

            <div className="grid gap-1.5">
                <Label>Note Type</Label>
                <Select value={data.note_type} onValueChange={(value) => setData('note_type', value as ClinicalNoteType)}>
                    <SelectTrigger>
                        <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                        {NOTE_TYPES.map((type) => (
                            <SelectItem key={type} value={type}>
                                {clinicalNoteTypeLabel[type]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.note_type} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="note_content">Note Content</Label>
                <Textarea
                    id="note_content"
                    rows={4}
                    value={data.note_content}
                    onChange={(e) => setData('note_content', e.target.value)}
                    placeholder="Clinical documentation for this encounter"
                />
                <InputError message={errors.note_content} />
            </div>

            <p className="text-muted-foreground text-xs">Saved as a draft — sign it from the list above once you're ready to finalize it.</p>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || !data.note_type || !data.note_content}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <FilePlus2 className="h-4 w-4" />}
                    Save Draft
                </Button>
            </div>
        </form>
    );
}

function EditNoteForm({ encounterId, note }: { encounterId: number; note: ClinicalNote }) {
    const { data, setData, put, processing, errors } = useForm<ClinicalNoteFormData>({
        note_type: note.note_type,
        note_content: note.note_content,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('clinical-encounters.clinical-notes.update', [encounterId, note.id]), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border p-3">
            <div className="grid gap-1.5">
                <Label>Note Type</Label>
                <Select value={data.note_type} onValueChange={(value) => setData('note_type', value as ClinicalNoteType)}>
                    <SelectTrigger>
                        <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                        {NOTE_TYPES.map((type) => (
                            <SelectItem key={type} value={type}>
                                {clinicalNoteTypeLabel[type]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.note_type} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor={`edit_note_content_${note.id}`}>Note Content</Label>
                <Textarea
                    id={`edit_note_content_${note.id}`}
                    rows={4}
                    value={data.note_content}
                    onChange={(e) => setData('note_content', e.target.value)}
                />
                <InputError message={errors.note_content} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save
                </Button>
            </div>
        </form>
    );
}
