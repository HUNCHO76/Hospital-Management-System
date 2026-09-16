import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { FolderOpen, LoaderCircle, Upload, X } from 'lucide-react';
import { type ChangeEvent, type FormEventHandler, useEffect, useRef, useState } from 'react';

interface RadiologyImageUploadDialogProps {
    appointmentId: number;
}

function formatSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

/**
 * Upload X-Ray/imaging files against an IN_PROGRESS examination (Phase 5,
 * Radiology UI completion pass, Parts 12-14) — a dialog-based flow, matching
 * the same Button-triggers-Dialog convention every other mutating Radiology
 * action already uses (Start/Complete/Abort/Submit/Verify/Return/Release).
 * The trigger button is always visible and enabled whenever the caller
 * renders this component (gated by `canManage` one level up, in
 * RadiologyImageGallery) — file selection/validation state lives entirely
 * inside the dialog, so there is nothing that can make the trigger itself
 * look inactive or disappear.
 */
export function RadiologyImageUploadDialog({ appointmentId }: RadiologyImageUploadDialogProps) {
    const [open, setOpen] = useState(false);
    const [previews, setPreviews] = useState<string[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, progress, errors, reset, clearErrors } = useForm<{ images: File[] }>({ images: [] });

    // Object URLs are only needed while the dialog is open — revoke them on
    // every change so we never leak memory across repeated selections.
    useEffect(() => {
        const urls = data.images.map((file) => URL.createObjectURL(file));
        setPreviews(urls);

        return () => {
            urls.forEach((url) => URL.revokeObjectURL(url));
        };
    }, [data.images]);

    const handleFilesSelected = (e: ChangeEvent<HTMLInputElement>) => {
        setData('images', [...data.images, ...Array.from(e.target.files ?? [])]);
        e.target.value = '';
    };

    const removeFile = (index: number) => {
        setData(
            'images',
            data.images.filter((_, i) => i !== index),
        );
    };

    const submitUpload: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-images.store', appointmentId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    reset();
                    clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" size="sm">
                    <Upload className="h-4 w-4" />
                    Upload Images
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submitUpload}>
                    <DialogHeader>
                        <DialogTitle>Upload X-Ray Images</DialogTitle>
                        <DialogDescription>Select one or more JPG or PNG images (up to 10MB each).</DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3 py-4">
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            accept="image/jpeg,image/jpg,image/png"
                            onChange={handleFilesSelected}
                            className="hidden"
                            aria-label="Choose image files"
                        />
                        <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
                            <FolderOpen className="h-4 w-4" />
                            Choose Files
                        </Button>
                        <InputError message={errors.images} />

                        {data.images.length > 0 && (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border max-h-48 divide-y overflow-y-auto rounded-md border text-sm">
                                {data.images.map((file, index) => (
                                    <li key={`${file.name}-${index}`} className="flex items-center gap-2 p-2">
                                        {previews[index] && <img src={previews[index]} alt="" className="h-10 w-10 rounded object-cover" />}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate">{file.name}</p>
                                            <p className="text-muted-foreground text-xs">{formatSize(file.size)}</p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7 shrink-0"
                                            onClick={() => removeFile(index)}
                                            aria-label={`Remove ${file.name}`}
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                        <InputError message={(errors as Record<string, string>)[`images.${index}`]} />
                                    </li>
                                ))}
                            </ul>
                        )}

                        {progress && <p className="text-muted-foreground text-xs">Uploading… {progress.percentage ?? 0}%</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || data.images.length === 0}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Upload Images
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
