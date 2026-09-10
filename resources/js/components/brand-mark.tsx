import { brand } from '@/lib/brand';
import { cn } from '@/lib/utils';
import { useState } from 'react';

interface BrandMarkProps {
    className?: string;
}

/**
 * Renders the official St. Joseph DMI logo image. If the asset has not been
 * placed at `brand.logoSrc` yet, it degrades gracefully to nothing (the
 * accompanying wordmark still identifies the app) rather than showing a broken
 * image — and starts working automatically once the file is added.
 */
export function BrandMark({ className }: BrandMarkProps) {
    const [failed, setFailed] = useState(false);

    if (failed) {
        return null;
    }

    return <img src={brand.logoSrc} alt={brand.hospitalName} className={cn('object-contain', className)} onError={() => setFailed(true)} />;
}
