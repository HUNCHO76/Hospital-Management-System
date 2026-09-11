import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type RadiologyReport } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface RadiologyReportFormProps {
    report: RadiologyReport;
    canEdit: boolean;
}

/**
 * The findings/impression editor (Phase 5, Step 42, Parts 8-10). Plain
 * multi-line text, not a rich-text/HTML editor (Part 22 — "plain text is
 * preferable unless rich formatting is genuinely required," and nothing
 * here requires it) — content is rendered back on this same page via plain
 * text interpolation only, never `dangerouslySetInnerHTML`, so there is no
 * stored-XSS surface to defend regardless of what a user types here.
 * `technique` reviews Step 41's own recorded information rather than
 * duplicating it — see RadiologyExaminationSummary for that read-only
 * display.
 */
export function RadiologyReportForm({ report, canEdit }: RadiologyReportFormProps) {
    const { data, setData, put, processing, errors } = useForm({
        technique: report.technique ?? '',
        findings: report.findings ?? '',
        impression: report.impression ?? '',
        recommendations: report.recommendations ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('radiology-results.update', report.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-1.5">
                <Label htmlFor="technique">Technique</Label>
                <Textarea
                    id="technique"
                    rows={2}
                    disabled={!canEdit}
                    value={data.technique}
                    onChange={(e) => setData('technique', e.target.value)}
                    placeholder="e.g. PA and lateral views; non-contrast CT"
                />
                <InputError message={errors.technique} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="findings">Findings {canEdit && '*'}</Label>
                <Textarea
                    id="findings"
                    rows={8}
                    disabled={!canEdit}
                    value={data.findings}
                    onChange={(e) => setData('findings', e.target.value)}
                    placeholder="Describe the relevant clinical observations."
                />
                <InputError message={errors.findings} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="impression">Impression {canEdit && '*'}</Label>
                <Textarea
                    id="impression"
                    rows={4}
                    disabled={!canEdit}
                    value={data.impression}
                    onChange={(e) => setData('impression', e.target.value)}
                    placeholder="The concise clinical conclusion drawn from the findings above."
                />
                <InputError message={errors.impression} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="recommendations">Recommendations</Label>
                <Textarea
                    id="recommendations"
                    rows={2}
                    disabled={!canEdit}
                    value={data.recommendations}
                    onChange={(e) => setData('recommendations', e.target.value)}
                />
                <InputError message={errors.recommendations} />
            </div>

            {canEdit && (
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save Draft
                </Button>
            )}
        </form>
    );
}
