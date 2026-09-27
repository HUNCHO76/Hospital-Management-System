import { cn } from '@/lib/utils';
import * as React from 'react';

interface TabsContextValue {
    value: string;
    onValueChange: (value: string) => void;
}

const TabsContext = React.createContext<TabsContextValue>({ value: '', onValueChange: () => {} });

interface TabsProps {
    defaultValue?: string;
    value?: string;
    onValueChange?: (value: string) => void;
    className?: string;
    children: React.ReactNode;
}

function Tabs({ defaultValue = '', value, onValueChange, className, children }: TabsProps) {
    const [internalValue, setInternalValue] = React.useState(defaultValue);
    const controlled = value !== undefined;
    const current = controlled ? value : internalValue;

    const handleChange = (next: string) => {
        if (!controlled) {
            setInternalValue(next);
        }
        onValueChange?.(next);
    };

    return (
        <TabsContext.Provider value={{ value: current, onValueChange: handleChange }}>
            <div className={cn('w-full', className)}>{children}</div>
        </TabsContext.Provider>
    );
}

const TabsList = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(({ className, ...props }, ref) => (
    <div
        ref={ref}
        role="tablist"
        className={cn('bg-muted text-muted-foreground inline-flex h-10 items-center justify-start rounded-md p-1', className)}
        {...props}
    />
));
TabsList.displayName = 'TabsList';

interface TabsTriggerProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    value: string;
}

const TabsTrigger = React.forwardRef<HTMLButtonElement, TabsTriggerProps>(({ className, value, ...props }, ref) => {
    const ctx = React.useContext(TabsContext);
    const active = ctx.value === value;

    return (
        <button
            ref={ref}
            role="tab"
            type="button"
            aria-selected={active}
            data-state={active ? 'active' : 'inactive'}
            onClick={() => ctx.onValueChange(value)}
            className={cn(
                'ring-offset-background focus-visible:ring-ring inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium transition-all focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50',
                active ? 'bg-background text-foreground shadow-sm' : 'hover:bg-background/50',
                className,
            )}
            {...props}
        />
    );
});
TabsTrigger.displayName = 'TabsTrigger';

interface TabsContentProps extends React.HTMLAttributes<HTMLDivElement> {
    value: string;
}

const TabsContent = React.forwardRef<HTMLDivElement, TabsContentProps>(({ className, value, ...props }, ref) => {
    const ctx = React.useContext(TabsContext);

    if (ctx.value !== value) {
        return null;
    }

    return (
        <div
            ref={ref}
            role="tabpanel"
            data-state="active"
            className={cn('ring-offset-background focus-visible:ring-ring mt-2 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none', className)}
            {...props}
        />
    );
});
TabsContent.displayName = 'TabsContent';

export { Tabs, TabsContent, TabsList, TabsTrigger };
