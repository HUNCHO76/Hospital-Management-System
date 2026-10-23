import { brand } from '@/lib/brand';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

interface BrandMarkProps {
    className?: string;
}

/**
 * Renders the official St. Joseph DMI logo image. The URL is resolved on the
 * server via `asset()` (shared as `brandLogoUrl`) so it stays correct when the
 * app is deployed under a sub-folder or behind an ASSET_URL; it falls back to
 * `brand.logoSrc`. If the asset is missing it degrades gracefully to nothing
 * (the accompanying wordmark still identifies the app) rather than showing a
 * broken image.
 */
export function BrandMark({ className }: BrandMarkProps) {
    const { brandLogoUrl } = usePage<SharedData>().props;
    const [failed, setFailed] = useState(false);

    if (failed) {
        return null;
    }

    return <img src={brandLogoUrl ?? brand.logoSrc} alt={brand.hospitalName} className={cn('object-contain', className)} onError={() => setFailed(true)} />;
}
