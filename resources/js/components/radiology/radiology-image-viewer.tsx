import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type RadiologyImage } from '@/types/radiology';
import { ChevronLeft, ChevronRight, Download, Maximize2, RotateCcw, ZoomIn, ZoomOut } from 'lucide-react';
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

const ZOOM_LEVELS = [1, 1.25, 1.5, 2, 2.5, 3];

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
            <DialogContent className="max-w-5xl sm:max-w-6xl w-[95vw] max-h-[95vh] flex flex-col p-4 sm:p-6 overflow-hidden">
                <DialogHeader className="pb-2">
                    <div className="flex items-center justify-between gap-4 pr-6">
                        <div>
                            <DialogTitle className="text-base sm:text-lg font-semibold flex items-center gap-2">
                                <span>{image.original_filename}</span>
                                {caption && <span className="text-xs font-normal text-muted-foreground">({caption})</span>}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground">
                                Image {index + 1} of {images.length} • {formatSize(image.size_bytes)}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="flex-1 flex flex-col gap-3 min-h-0">
                    {/* Main Image Container: full view, centered, no clipping */}
                    <div className="bg-neutral-950 flex-1 min-h-[50vh] max-h-[72vh] flex items-center justify-center overflow-auto rounded-lg p-2 border border-neutral-800 shadow-inner relative select-none">
                        <img
                            src={src}
                            alt={image.original_filename}
                            className="transition-transform duration-150 ease-out object-contain rounded"
                            style={{
                                transform: `scale(${zoom})`,
                                transformOrigin: 'center center',
                                maxWidth: zoom === 1 ? '100%' : 'none',
                                maxHeight: zoom === 1 ? '70vh' : 'none',
                            }}
                        />
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2 pt-1 border-t">
                        {/* Navigation controls */}
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 gap-1"
                                disabled={images.length < 2}
                                onClick={() => {
                                    setIndex((i) => (i - 1 + images.length) % images.length);
                                    setZoomStep(0);
                                }}
                                aria-label="Previous image"
                            >
                                <ChevronLeft className="h-4 w-4" />
                                <span className="hidden sm:inline text-xs">Prev</span>
                            </Button>
                            <span className="text-muted-foreground font-medium text-xs px-1">
                                {index + 1} / {images.length}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 gap-1"
                                disabled={images.length < 2}
                                onClick={() => {
                                    setIndex((i) => (i + 1) % images.length);
                                    setZoomStep(0);
                                }}
                                aria-label="Next image"
                            >
                                <span className="hidden sm:inline text-xs">Next</span>
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>

                        {/* Zoom controls */}
                        <div className="flex items-center gap-1.5">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="h-8 w-8"
                                disabled={zoomStep === 0}
                                onClick={() => setZoomStep((z) => Math.max(0, z - 1))}
                                aria-label="Zoom out"
                                title="Zoom Out"
                            >
                                <ZoomOut className="h-4 w-4" />
                            </Button>
                            <span className="text-muted-foreground w-12 text-center text-xs font-mono font-medium">
                                {Math.round(zoom * 100)}%
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="h-8 w-8"
                                disabled={zoomStep === ZOOM_LEVELS.length - 1}
                                onClick={() => setZoomStep((z) => Math.min(ZOOM_LEVELS.length - 1, z + 1))}
                                aria-label="Zoom in"
                                title="Zoom In"
                            >
                                <ZoomIn className="h-4 w-4" />
                            </Button>
                            {zoomStep !== 0 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 px-2 text-xs gap-1 text-muted-foreground"
                                    onClick={() => setZoomStep(0)}
                                    title="Reset to Fit View"
                                >
                                    <RotateCcw className="h-3.5 w-3.5" />
                                    <span>Fit</span>
                                </Button>
                            )}
                        </div>

                        {/* Secondary info / Actions */}
                        <div className="flex items-center gap-2">
                            <Button
                                asChild
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 text-xs gap-1"
                            >
                                <a href={src} target="_blank" rel="noopener noreferrer">
                                    <Maximize2 className="h-3.5 w-3.5" />
                                    <span>Open Full Window</span>
                                </a>
                            </Button>
                            <Button
                                asChild
                                type="button"
                                variant="secondary"
                                size="sm"
                                className="h-8 text-xs gap-1"
                            >
                                <a href={src} download={image.original_filename}>
                                    <Download className="h-3.5 w-3.5" />
                                    <span className="hidden sm:inline">Download</span>
                                </a>
                            </Button>
                        </div>
                    </div>

                    <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[11px] pt-1">
                        <div>
                            <span>Uploaded by <strong className="text-foreground">{image.uploaded_by?.name ?? '—'}</strong> on {formatDateTime(image.created_at)}</span>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
