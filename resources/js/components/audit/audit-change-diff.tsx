type Values = Record<string, unknown> | null;

interface AuditChangeDiffProps {
    oldValues: Values;
    newValues: Values;
}

/**
 * Render a stored audit value as readable text rather than raw JSON.
 */
function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

/**
 * A readable field-by-field diff of an audit event's old and new values.
 * Only fields that actually changed are highlighted.
 */
export function AuditChangeDiff({ oldValues, newValues }: AuditChangeDiffProps) {
    const keys = Array.from(new Set([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})])).sort();

    if (keys.length === 0) {
        return <p className="text-muted-foreground text-sm">No field-level changes were recorded for this event.</p>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-left text-sm">
                <thead className="border-b border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border">
                    <tr>
                        <th className="px-4 py-2 font-medium">Field</th>
                        <th className="px-4 py-2 font-medium">Old Value</th>
                        <th className="px-4 py-2 font-medium">New Value</th>
                    </tr>
                </thead>
                <tbody>
                    {keys.map((key) => {
                        const oldValue = oldValues?.[key];
                        const newValue = newValues?.[key];
                        const changed = formatValue(oldValue) !== formatValue(newValue);

                        return (
                            <tr key={key} className="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                                <td className="px-4 py-2 font-medium capitalize">{key.replace(/_/g, ' ')}</td>
                                <td className="text-muted-foreground px-4 py-2">{formatValue(oldValue)}</td>
                                <td className={`px-4 py-2 ${changed ? 'font-medium text-foreground' : 'text-muted-foreground'}`}>
                                    {formatValue(newValue)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
