import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { radiologyTechnicalFieldLabel } from '@/lib/radiology-status';
import { type RadiologyAppointment, type RadiologyTechnicalFieldsByModality } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface RadiologyExaminationTechnicalFormProps {
    appointment: RadiologyAppointment;
    technicalFields: RadiologyTechnicalFieldsByModality;
    canEdit: boolean;
}

/**
 * Modality-aware technical information capture (Phase 5, Step 41, Part 9) —
 * renders only the fields recognized for this appointment's own modality
 * (server-supplied `technicalFields`, mirroring
 * RadiologyAppointment::TECHNICAL_FIELDS_BY_MODALITY exactly, never
 * duplicated/hard-coded here). Purely operational/technical capture —
 * clinical interpretation belongs to the radiologist/reporting stage (Step
 * 42), never to this form.
 */
export function RadiologyExaminationTechnicalForm({ appointment, technicalFields, canEdit }: RadiologyExaminationTechnicalFormProps) {
    const fields = (appointment.modality && technicalFields[appointment.modality]) || [];

    const { data, setData, put, processing, errors } = useForm({
        technical_notes: appointment.technical_notes ?? '',
        technical_details: fields.reduce<Record<string, string>>((acc, field) => {
            acc[field] = appointment.technical_details?.[field] ?? '';
            return acc;
        }, {}),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('radiology-examinations.update', appointment.id), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Technical Information</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    {fields.length > 0 && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {fields.map((field) => (
                                <div key={field} className="grid gap-1.5">
                                    <Label htmlFor={`tech_${field}`}>{radiologyTechnicalFieldLabel[field] ?? field}</Label>
                                    <Input
                                        id={`tech_${field}`}
                                        value={data.technical_details[field] ?? ''}
                                        disabled={!canEdit}
                                        onChange={(e) => setData('technical_details', { ...data.technical_details, [field]: e.target.value })}
                                    />
                                    <InputError message={(errors as Record<string, string>)[`technical_details.${field}`]} />
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="technical_notes">Technical Notes</Label>
                        <Textarea
                            id="technical_notes"
                            rows={3}
                            disabled={!canEdit}
                            value={data.technical_notes}
                            onChange={(e) => setData('technical_notes', e.target.value)}
                        />
                        <InputError message={errors.technical_notes} />
                    </div>

                    {canEdit && (
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save Technical Information
                        </Button>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}
