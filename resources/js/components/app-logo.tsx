import { BrandMark } from './brand-mark';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md">
                <BrandMark className="size-8" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">St. Joseph DMI</span>
                <span className="text-muted-foreground truncate text-xs leading-none">Hospital Management</span>
            </div>
        </>
    );
}
