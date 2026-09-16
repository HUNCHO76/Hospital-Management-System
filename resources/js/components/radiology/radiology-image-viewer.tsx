import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type RadiologyImage } from '@/types/radiology';
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RadiologyImageViewerProps {
    appointmentId: number;
    images: RadiologyImage[];
    initialIndex: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    caption?: string;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function formatSize(bytes: number): string {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const ZOOM_LEVELS = [1, 1.5, 2, 2.5];

/**
 * A reusable full-size image viewer (Phase 5, Radiology UI completion pass,
 * Part 8) — open/zoom/navigate/close, used identically from the examination
 * page (technician, upload context), the report/verification/release pages
 * (read-only, clinical-review context), and the doctor's released-result
 * view. Only displays metadata that actually exists — never invents it.
 */
export function RadiologyImageViewer({ appointmentId, images, initialIndex, open, onOpenChange, caption }: RadiologyImageViewerProps) {
    const [index, setIndex] = useState(initialIndex);
    const [zoomStep, setZoomStep] = useState(0);

    useEffect(() => {
        if (open) {
            setIndex(initialIndex);
            setZoomStep(0);
        }
    }, [open, initialIndex]);

    const image = images[index];

    if (!image) {
        return null;
    }

    const src = route('radiology-images.show', [appointmentId, image.id]);
    const zoom = ZOOM_LEVELS[zoomStep];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{image.original_filename}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {caption ? `Radiology image for ${caption}` : 'Radiology image'}, {index + 1} of {images.length}.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-3">
                    <div className="bg-muted flex max-h-[60vh] items-center justify-center overflow-auto rounded-md">
                        <img src={src} alt={image.original_filename} className="max-w-none" style={{ width: `${zoom * 100}%` }} />
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                disabled={images.length < 2}
                                onClick={() => setIndex((i) => (i - 1 + images.length) % images.length)}
                                aria-label="Previous image"
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <span className="text-muted-foreground text-sm">
                                {index + 1} / {images.length}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                disabled={images.length < 2}
                                onClick={() => setIndex((i) => (i + 1) % images.length)}
                                aria-label="Next image"
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>

                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                disabled={zoomStep === 0}
                                onClick={() => setZoomStep((z) => Math.max(0, z - 1))}
                                aria-label="Zoom out"
                            >
                                <ZoomOut className="h-4 w-4" />
                            </Button>
                            <span className="text-muted-foreground w-10 text-center text-sm">{zoom}x</span>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                disabled={zoomStep === ZOOM_LEVELS.length - 1}
                                onClick={() => setZoomStep((z) => Math.min(ZOOM_LEVELS.length - 1, z + 1))}
                                aria-label="Zoom in"
                            >
                                <ZoomIn className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <dl className="text-muted-foreground grid grid-cols-2 gap-1 text-xs sm:grid-cols-4">
                        {caption && (
                            <div className="col-span-2 sm:col-span-4">
                                <dd className="text-foreground">{caption}</dd>
                            </div>
                        )}
                        <dt>Uploaded By</dt>
                        <dd className="text-foreground">{image.uploaded_by?.name ?? '—'}</dd>
                        <dt>Uploaded At</dt>
                        <dd className="text-foreground">{formatDateTime(image.created_at)}</dd>
                        <dt>Size</dt>
                        <dd className="text-foreground">{formatSize(image.size_bytes)}</dd>
                    </dl>
                </div>
            </DialogContent>
        </Dialog>
    );
}
