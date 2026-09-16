import { RadiologyImageUploadDialog } from '@/components/radiology/radiology-image-upload-dialog';
import { RadiologyImageViewer } from '@/components/radiology/radiology-image-viewer';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { type RadiologyImage } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { ImageOff, LoaderCircle, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface RadiologyImageGalleryProps {
    appointmentId: number;
    images: RadiologyImage[];
    canManage: boolean;
    caption?: string;
}

function formatSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

/**
 * Imaging / X-Ray images for one examination (Phase 5, Radiology UI
 * completion pass, Parts 7-8) — upload (only when `canManage`, i.e. the
 * examination is still IN_PROGRESS and the user holds
 * radiology-examination.update), a thumbnail grid, and the shared
 * RadiologyImageViewer for full-size/zoom/navigate viewing. Read-only
 * everywhere else (report/verification/release pages, doctor's released
 * result) — the backend independently re-enforces both the permission and
 * the IN_PROGRESS gate regardless of what this component shows.
 */
export function RadiologyImageGallery({ appointmentId, images, canManage, caption }: RadiologyImageGalleryProps) {
    const [viewerIndex, setViewerIndex] = useState<number | null>(null);

    return (
        <div className="space-y-4">
            {canManage && <RadiologyImageUploadDialog appointmentId={appointmentId} />}

            {images.length === 0 ? (
                <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-md border border-dashed py-8 text-sm">
                    <ImageOff className="h-6 w-6" />
                    {canManage ? 'No images uploaded yet.' : 'No images available for this examination.'}
                </div>
            ) : (
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                    {images.map((image, i) => (
                        <ImageThumbnail
                            key={image.id}
                            appointmentId={appointmentId}
                            image={image}
                            canManage={canManage}
                            onOpen={() => setViewerIndex(i)}
                        />
                    ))}
                </div>
            )}

            {viewerIndex !== null && (
                <RadiologyImageViewer
                    appointmentId={appointmentId}
                    images={images}
                    initialIndex={viewerIndex}
                    open={true}
                    onOpenChange={(open) => !open && setViewerIndex(null)}
                    caption={caption}
                />
            )}
        </div>
    );
}

function ImageThumbnail({
    appointmentId,
    image,
    canManage,
    onOpen,
}: {
    appointmentId: number;
    image: RadiologyImage;
    canManage: boolean;
    onOpen: () => void;
}) {
    const { delete: destroy, processing } = useForm({});

    const confirmDelete: FormEventHandler = (e) => {
        e.preventDefault();
        destroy(route('radiology-images.destroy', [appointmentId, image.id]), { preserveScroll: true });
    };

    return (
        <div className="group relative">
            <button
                type="button"
                onClick={onOpen}
                className="border-sidebar-border/70 dark:border-sidebar-border block aspect-square w-full overflow-hidden rounded-md border"
                title={image.original_filename}
            >
                <img
                    src={route('radiology-images.show', [appointmentId, image.id])}
                    alt={image.original_filename}
                    className="h-full w-full object-cover"
                    loading="lazy"
                />
            </button>
            <p className="text-muted-foreground mt-1 truncate text-[10px]" title={image.original_filename}>
                {image.original_filename} · {formatSize(image.size_bytes)}
            </p>

            {canManage && (
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            type="button"
                            variant="destructive"
                            size="icon"
                            className="absolute top-1 right-1 h-6 w-6 opacity-0 transition-opacity group-hover:opacity-100"
                            aria-label="Remove image"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <form onSubmit={confirmDelete}>
                            <DialogHeader>
                                <DialogTitle>Remove image</DialogTitle>
                                <DialogDescription>This permanently removes "{image.original_filename}" from the examination.</DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button type="submit" variant="destructive" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Remove Image
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
