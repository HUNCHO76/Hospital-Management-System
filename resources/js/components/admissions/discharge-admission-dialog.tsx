import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { dischargeTypeLabel } from '@/lib/admission-status';
import { type DischargeFormData, type DischargeType } from '@/types/admission';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface DischargeAdmissionDialogProps {
    admissionId: number;
    dischargeTypes: DischargeType[];
    initialDiagnosis?: string | null;
    isNhif?: boolean;
    trigger: ReactNode;
}

/**
 * Discharge requires discharge type and reason, plus optional diagnosis
 * at discharge and referral facility code for NHIF compliance.
 */
export function DischargeAdmissionDialog({
    admissionId,
    dischargeTypes,
    initialDiagnosis,
    isNhif,
    trigger,
}: DischargeAdmissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<DischargeFormData>({
        discharge_type: '',
        discharge_reason: '',
        diagnosis_at_discharge: initialDiagnosis ?? '',
        referred_to_facility_code: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.discharge', admissionId), { onSuccess: () => reset() });
    };

    const isTransferOrReferral = data.discharge_type === 'transferred_out';

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-[500px]">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Discharge patient</DialogTitle>
                        <DialogDescription>
                            Record the discharge details for this admission.
                            {isNhif && ' This will also automatically notify NHIF.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="discharge_type">Discharge type *</Label>
                            <Select value={data.discharge_type} onValueChange={(value) => setData('discharge_type', value as DischargeFormData['discharge_type'])}>
                                <SelectTrigger id="discharge_type">
                                    <SelectValue placeholder="Select a discharge type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {dischargeTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {dischargeTypeLabel[type] ?? type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.discharge_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="diagnosis_at_discharge">Diagnosis at discharge</Label>
                            <Input
                                id="diagnosis_at_discharge"
                                value={data.diagnosis_at_discharge ?? ''}
                                onChange={(e) => setData('diagnosis_at_discharge', e.target.value)}
                                placeholder="e.g. Acute Appendicitis - Resolved"
                            />
                            <InputError message={errors.diagnosis_at_discharge} />
                        </div>

                        {isTransferOrReferral && (
                            <div className="grid gap-2">
                                <Label htmlFor="referred_to_facility_code">Referred / Transferred to Facility Code</Label>
                                <Input
                                    id="referred_to_facility_code"
                                    value={data.referred_to_facility_code ?? ''}
                                    onChange={(e) => setData('referred_to_facility_code', e.target.value)}
                                    placeholder="e.g. 01142"
                                />
                                <InputError message={errors.referred_to_facility_code} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="discharge_reason">Discharge reason / Practitioner's Remarks *</Label>
                            <Textarea
                                id="discharge_reason"
                                value={data.discharge_reason}
                                onChange={(e) => setData('discharge_reason', e.target.value)}
                                rows={3}
                                placeholder="Clinical progress, condition on discharge, advice given..."
                            />
                            <InputError message={errors.discharge_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Discharge Patient
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
