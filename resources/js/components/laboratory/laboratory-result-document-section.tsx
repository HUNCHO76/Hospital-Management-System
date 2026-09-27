import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { canSaveResultDraft } from '@/lib/laboratory-status';
import { type LaboratoryResult, type LaboratoryResultDocument } from '@/types/laboratory';
import { router, useForm } from '@inertiajs/react';
import { FileText, LoaderCircle, Paperclip, Trash2 } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface LaboratoryResultDocumentSectionProps {
    result: LaboratoryResult;
    canUpload: boolean;
    canDelete: boolean;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1048576) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1048576).toFixed(1)} MB`;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function MimeIcon({ mimeType }: { mimeType: string }) {
    return mimeType === 'application/pdf' ? (
        <FileText className="text-muted-foreground h-4 w-4 shrink-0" />
    ) : (
        <Paperclip className="text-muted-foreground h-4 w-4 shrink-0" />
    );
}

function DocumentRow({
    document,
    resultId,
    canDelete,
}: {
    document: LaboratoryResultDocument;
    resultId: number;
    canDelete: boolean;
}) {
    const deleteDocument = () => {
        router.delete(route('laboratory.results.documents.destroy', [resultId, document.id]), { preserveScroll: true });
    };

    return (
        <li className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
            <div className="flex min-w-0 items-center gap-2">
                <MimeIcon mimeType={document.mime_type} />
                <div className="min-w-0">
                    <a
                        href={route('laboratory.results.documents.show', [resultId, document.id])}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary truncate text-sm hover:underline"
                    >
                        {document.original_filename}
                    </a>
                    <p className="text-muted-foreground text-xs">
                        {formatBytes(document.size_bytes)} · Uploaded by {document.uploaded_by?.name ?? '—'} ·{' '}
                        {formatDateTime(document.uploaded_at)}
                    </p>
                </div>
            </div>

            {canDelete && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={deleteDocument}
                    className="text-destructive hover:text-destructive shrink-0"
                >
                    <Trash2 className="h-4 w-4" />
                    <span className="sr-only">Remove</span>
                </Button>
            )}
        </li>
    );
}

function UploadForm({ resultId }: { resultId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm<{ document: File | null }>({
        document: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.results.documents.store', resultId), {
            preserveScroll: true,
            onSuccess: () => reset(),
            forceFormData: true,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2 pt-2">
            <div className="grid min-w-0 flex-1 gap-1">
                <Label htmlFor="document-upload" className="text-xs">
                    Attach machine/analyzer document (PDF, JPG, PNG — max 20 MB)
                </Label>
                <Input
                    id="document-upload"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(e) => setData('document', e.target.files?.[0] ?? null)}
                    className="text-sm"
                />
                {errors.document && <p className="text-destructive text-xs">{errors.document}</p>}
            </div>
            <Button type="submit" size="sm" disabled={processing || !data.document}>
                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                Upload
            </Button>
        </form>
    );
}

/**
 * Machine/analyzer document upload and listing panel on the result entry
 * page (Phase 4 Machine Document Upload). Upload and delete are restricted
 * to editable states; all roles with view permission can open/download the
 * documents. Private-disk streaming is handled server-side.
 */
export function LaboratoryResultDocumentSection({ result, canUpload, canDelete }: LaboratoryResultDocumentSectionProps) {
    const editable = canSaveResultDraft(result.status);
    const showUpload = editable && canUpload;
    const showDelete = editable && canDelete;

    if (!showUpload && result.documents.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">
                    Machine Documents
                    {result.result_source === 'machine_document' && (
                        <span className="text-muted-foreground ml-2 text-xs font-normal">(machine entry)</span>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {result.documents.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {result.documents.map((doc) => (
                            <DocumentRow key={doc.id} document={doc} resultId={result.id} canDelete={showDelete} />
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No documents attached yet.</p>
                )}

                {showUpload && <UploadForm resultId={result.id} />}
            </CardContent>
        </Card>
    );
}
