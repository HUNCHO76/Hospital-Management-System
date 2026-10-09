import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { admissionTypeLabel } from '@/lib/admission-status';
import { type BreadcrumbItem } from '@/types';
import {
    type AdmissionDepartment,
    type AdmissionFormData,
    type AdmissionHospital,
    type AdmissionPractitioner,
    type AdmissionService as AdmissionServiceOption,
    type AdmissionType,
} from '@/types/admission';
import { type PatientSearchResult } from '@/types/patient';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, ExternalLink, LoaderCircle, ShieldCheck } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import axios from 'axios';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admissions', href: '/admissions' },
    { title: 'New Request', href: '/admissions/create' },
];

interface AdmissionCreateProps {
    hospitals: AdmissionHospital[];
    departments: AdmissionDepartment[];
    practitioners: AdmissionPractitioner[];
    services: AdmissionServiceOption[];
    admissionTypes: AdmissionType[];
    nhifAdmissionTypes: Array<{ AdmissionTypeID: number; AdmissionTypeName: string }>;
    nhifWardTypes: Array<{ WardTypeID: number; WardTypeName: string }>;
    nhifRoomTypes: Array<{ RoomTypeID: number; RoomTypeName: string }>;
}

/**
 * A single-page guided form (patient → hospital/department → billing tier
 * → submit), mirroring reception/walk-in/create.tsx's own precedent for
 * this app's request-style forms.
 */
export default function AdmissionCreate({ hospitals, departments, practitioners, services, admissionTypes, nhifAdmissionTypes, nhifWardTypes, nhifRoomTypes }: AdmissionCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(null);
    const [authChecking, setAuthChecking] = useState(false);
    const [activeAuth, setActiveAuth] = useState<string | null>(null);
    const [authExpiredInfo, setAuthExpiredInfo] = useState<{
        expired: boolean;
        previous_auth_no?: string;
        patient_insurance_id?: number;
    } | null>(null);
    const [existingNhifAdmission, setExistingNhifAdmission] = useState<{
        admissionNo?: string;
        dateAdmitted?: string;
        diagnosis?: string;
    } | null>(null);

    const checkExistingNhifAdmission = (authNumber: string) => {
        if (!authNumber?.trim()) {
            setExistingNhifAdmission(null);
            return;
        }

        axios.get(route('nhif.admissions.lookup'), { params: { authorization_no: authNumber.trim() } })
            .then((res) => {
                const payload = Array.isArray(res.data) && res.data.length > 0 ? res.data[0] : res.data;
                if (payload?.AdmissionNo) {
                    setExistingNhifAdmission({
                        admissionNo: payload.AdmissionNo,
                        dateAdmitted: payload.DateAdmitted,
                        diagnosis: payload.DiagnosisAtAdmission || payload.Diagnosis,
                    });
                } else {
                    setExistingNhifAdmission(null);
                }
            })
            .catch(() => {
                setExistingNhifAdmission(null);
            });
    };

    const { data, setData, post, processing, errors } = useForm<AdmissionFormData>({
        hospital_id: '',
        patient_id: '',
        department_id: '',
        practitioner_id: '',
        service_id: '',
        admission_type: '',
        ward_type: '',
        room_type: '',
        nhif_admission_type_id: '',
        nhif_ward_type_id: '',
        nhif_room_type_id: '',
        nhif_authorization_no: '',
        diagnosis_at_admission: '',
        notes: '',
    });

    const filteredDepartments = data.hospital_id ? departments.filter((d) => String(d.hospital_id) === data.hospital_id) : departments;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.store'));
    };

    const selectedService = services.find((s) => String(s.id) === data.service_id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Admission Request" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>New Admission Request</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="patient_id">Patient *</Label>
                                <PatientSelector
                                    value={data.patient_id}
                                    selected={selectedPatient}
                                    onSelect={(patient) => {
                                        setSelectedPatient(patient);
                                        const initialAuth = patient.active_nhif_authorization_no || '';
                                        const initialInsId = patient.patient_insurance_id ? String(patient.patient_insurance_id) : '';

                                        setData((prev) => ({
                                            ...prev,
                                            patient_id: String(patient.id),
                                            nhif_authorization_no: patient.is_nhif_auth_expired ? '' : (initialAuth || prev.nhif_authorization_no),
                                            patient_insurance_id: initialInsId || prev.patient_insurance_id,
                                        }));

                                        if (patient.is_nhif_auth_expired) {
                                            setActiveAuth(null);
                                            setAuthExpiredInfo({
                                                expired: true,
                                                previous_auth_no: initialAuth,
                                                patient_insurance_id: patient.patient_insurance_id ?? undefined,
                                            });
                                        } else if (initialAuth) {
                                            setActiveAuth(initialAuth);
                                            setAuthExpiredInfo(null);
                                            checkExistingNhifAdmission(initialAuth);
                                        } else {
                                            setActiveAuth(null);
                                            setAuthExpiredInfo(null);
                                        }

                                        setAuthChecking(true);
                                        
                                        axios.post(route('nhif.authorization-context.lookup'), { patient_id: patient.id })
                                            .then((res) => {
                                                const authNo = res.data?.authorization_no || res.data?.preauthorization?.authorization_no;
                                                const insId = res.data?.patient_insurance_id ? String(res.data.patient_insurance_id) : '';
                                                if (res.data?.found && authNo) {
                                                    setActiveAuth(authNo);
                                                    setAuthExpiredInfo(null);
                                                    setData((prev) => ({
                                                        ...prev,
                                                        nhif_authorization_no: authNo,
                                                        patient_insurance_id: insId || prev.patient_insurance_id,
                                                    }));
                                                    checkExistingNhifAdmission(authNo);
                                                } else if (res.data?.auth_expired) {
                                                    setActiveAuth(null);
                                                    setAuthExpiredInfo({
                                                        expired: true,
                                                        previous_auth_no: res.data.previous_auth_no,
                                                        patient_insurance_id: res.data.patient_insurance_id ? Number(res.data.patient_insurance_id) : undefined,
                                                    });
                                                }
                                            })
                                            .catch(() => {})
                                            .finally(() => setAuthChecking(false));
                                    }}
                                />
                                <InputError message={errors.patient_id} />
                                <p className="text-muted-foreground text-sm">
                                    Can't find the patient?{' '}
                                    <a
                                        href={route('patients.create')}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-primary inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                    >
                                        Register New Patient
                                        <ExternalLink className="h-3.5 w-3.5" />
                                    </a>{' '}
                                    in a new tab, then search again here once they're registered.
                                </p>
                                {authChecking && (
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground mt-2">
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                        Checking NHIF authorization...
                                    </div>
                                )}
                                {authExpiredInfo?.expired && (
                                    <Alert className="mt-2 border-amber-500/50 bg-amber-500/10 text-amber-900 dark:text-amber-200">
                                        <AlertTriangle className="h-4 w-4 text-amber-600" />
                                        <AlertDescription className="text-xs space-y-1">
                                            <div>
                                                <span className="font-semibold">NHIF 24-Hour Authorization Expired: </span>
                                                Authorization ({authExpiredInfo.previous_auth_no}) has expired. NHIF authorizations are strictly valid for 24 hours.
                                            </div>
                                            {authExpiredInfo.patient_insurance_id && (
                                                <div>
                                                    <a
                                                        href={`/patients/${data.patient_id}/insurance/${authExpiredInfo.patient_insurance_id}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary inline-flex items-center gap-1 font-semibold underline hover:text-primary/80"
                                                    >
                                                        Re-verify card with NHIF
                                                        <ExternalLink className="h-3 w-3" />
                                                    </a>
                                                </div>
                                            )}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {activeAuth && (
                                    <div className="mt-2">
                                        <Badge variant="secondary" className="flex w-fit items-center gap-1.5 px-3 py-1 text-sm bg-green-500/15 text-green-700 hover:bg-green-500/25">
                                            <ShieldCheck className="h-4 w-4" />
                                            Active NHIF Auth Found: {activeAuth}
                                        </Badge>
                                    </div>
                                )}
                                {existingNhifAdmission && (
                                    <Alert className="mt-2 border-amber-500/50 bg-amber-500/10 text-amber-900 dark:text-amber-200">
                                        <AlertTriangle className="h-4 w-4 text-amber-600" />
                                        <AlertDescription className="text-xs space-y-1">
                                            <div>
                                                <span className="font-semibold">Active NHIF Admission Found: </span>
                                                Admission No: <span className="font-mono font-bold">{existingNhifAdmission.admissionNo}</span>
                                                {existingNhifAdmission.dateAdmitted ? ` (Admitted: ${new Date(existingNhifAdmission.dateAdmitted).toLocaleDateString()})` : ''}
                                            </div>
                                            <div className="text-muted-foreground">
                                                This patient already has an admission submitted to NHIF for this authorization. Submitting this form will allow you to update or resubmit the admission.
                                            </div>
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="hospital_id">Hospital *</Label>
                                    <Select
                                        value={data.hospital_id}
                                        onValueChange={(value) => {
                                            setData('hospital_id', value);
                                            setData('department_id', '');
                                        }}
                                    >
                                        <SelectTrigger id="hospital_id">
                                            <SelectValue placeholder="Select a hospital" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {hospitals.map((hospital) => (
                                                <SelectItem key={hospital.id} value={String(hospital.id)}>
                                                    {hospital.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.hospital_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="department_id">Department / Ward</Label>
                                    <Select value={data.department_id} onValueChange={(value) => setData('department_id', value)}>
                                        <SelectTrigger id="department_id">
                                            <SelectValue placeholder="Select a department" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {filteredDepartments.map((department) => (
                                                <SelectItem key={department.id} value={String(department.id)}>
                                                    {department.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.department_id} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="admission_type">Admission Type</Label>
                                    <Select
                                        value={data.nhif_admission_type_id}
                                        onValueChange={(value) => {
                                            const type = nhifAdmissionTypes.find(t => t.AdmissionTypeID.toString() === value);
                                            let internalStatus: AdmissionFormData['admission_type'] = 'other';
                                            if (type?.AdmissionTypeName.toLowerCase().includes('emergency')) internalStatus = 'emergency';
                                            else if (type?.AdmissionTypeName.toLowerCase().includes('elective') || type?.AdmissionTypeName.toLowerCase().includes('planned')) internalStatus = 'elective';
                                            
                                            setData((prev) => ({
                                                ...prev,
                                                nhif_admission_type_id: value,
                                                admission_type: internalStatus,
                                            }));
                                        }}
                                    >
                                        <SelectTrigger id="admission_type">
                                            <SelectValue placeholder="Select a type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {nhifAdmissionTypes.map((type) => (
                                                <SelectItem key={type.AdmissionTypeID} value={String(type.AdmissionTypeID)}>
                                                    {type.AdmissionTypeName}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.admission_type} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="practitioner_id">Admitting Practitioner</Label>
                                    <Select
                                        value={data.practitioner_id}
                                        onValueChange={(value) => setData('practitioner_id', value === 'none' ? '' : value)}
                                    >
                                        <SelectTrigger id="practitioner_id">
                                            <SelectValue placeholder="Select a practitioner" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">No practitioner selected</SelectItem>
                                            {practitioners.map((p) => (
                                                <SelectItem key={p.id} value={String(p.id)}>
                                                    {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.practitioner_id} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="service_id">
                                    Billing Tier <span className="text-muted-foreground font-normal">(daily rate — determines the admission charge)</span>
                                </Label>
                                <Select value={data.service_id} onValueChange={(value) => setData('service_id', value === 'none' ? '' : value)}>
                                    <SelectTrigger id="service_id">
                                        <SelectValue placeholder="No billing tier selected" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No billing tier selected</SelectItem>
                                        {services.map((service) => (
                                            <SelectItem key={service.id} value={String(service.id)}>
                                                {service.code} — {service.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.service_id} />
                                <p className="text-muted-foreground text-xs">
                                    If selected, an admission charge will be captured immediately. Payment (and NHIF pre-authorization, if required)
                                    must be cleared before the request can be confirmed and the patient admitted.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="ward_type">Ward</Label>
                                    <Select
                                        value={data.nhif_ward_type_id}
                                        onValueChange={(value) => {
                                            const type = nhifWardTypes.find(t => t.WardTypeID.toString() === value);
                                            setData((prev) => ({
                                                ...prev,
                                                nhif_ward_type_id: value,
                                                ward_type: type?.WardTypeName || '',
                                            }));
                                        }}
                                    >
                                        <SelectTrigger id="ward_type">
                                            <SelectValue placeholder="Select a ward" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {nhifWardTypes.map((type) => (
                                                <SelectItem key={type.WardTypeID} value={String(type.WardTypeID)}>
                                                    {type.WardTypeName}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.nhif_ward_type_id || errors.ward_type} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="room_type">Room</Label>
                                    <Select
                                        value={data.nhif_room_type_id}
                                        onValueChange={(value) => {
                                            const type = nhifRoomTypes.find(t => t.RoomTypeID.toString() === value);
                                            setData((prev) => ({
                                                ...prev,
                                                nhif_room_type_id: value,
                                                room_type: type?.RoomTypeName || '',
                                            }));
                                        }}
                                    >
                                        <SelectTrigger id="room_type">
                                            <SelectValue placeholder="Select a room" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {nhifRoomTypes.map((type) => (
                                                <SelectItem key={type.RoomTypeID} value={String(type.RoomTypeID)}>
                                                    {type.RoomTypeName}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.nhif_room_type_id || errors.room_type} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="nhif_authorization_no">NHIF Authorization Number</Label>
                                <Input
                                    id="nhif_authorization_no"
                                    value={data.nhif_authorization_no || ''}
                                    onChange={(e) => setData('nhif_authorization_no', e.target.value)}
                                    onBlur={() => checkExistingNhifAdmission(data.nhif_authorization_no || '')}
                                    placeholder="e.g. 201627864221 (auto-populated if patient has active NHIF)"
                                />
                                <InputError message={errors.nhif_authorization_no} />
                                <p className="text-muted-foreground text-xs">
                                    Captured automatically from patient NHIF insurance verification, or enter manually if required.
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="diagnosis_at_admission">Diagnosis / Reason for Admission</Label>
                                <Textarea
                                    id="diagnosis_at_admission"
                                    value={data.diagnosis_at_admission}
                                    onChange={(e) => setData('diagnosis_at_admission', e.target.value)}
                                    rows={2}
                                />
                                <InputError message={errors.diagnosis_at_admission} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                                <InputError message={errors.notes} />
                            </div>

                            {selectedPatient && data.hospital_id && (
                                <div className="bg-muted/50 rounded-md border p-4 text-sm space-y-2">
                                    <p className="font-medium">Request Summary</p>
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <span className="text-muted-foreground">Patient: </span>
                                            <span className="font-medium text-foreground">
                                                {[selectedPatient.first_name, selectedPatient.middle_name, selectedPatient.last_name]
                                                    .filter(Boolean)
                                                    .join(' ')}{' '}
                                                ({selectedPatient.patient_number})
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Hospital: </span>
                                            <span className="font-medium text-foreground">
                                                {hospitals.find((h) => String(h.id) === data.hospital_id)?.name}
                                            </span>
                                        </div>
                                        {selectedService && (
                                            <div>
                                                <span className="text-muted-foreground">Billing Tier: </span>
                                                <span className="font-bold text-foreground">{selectedService.name}</span>
                                            </div>
                                        )}
                                        {activeAuth && (
                                            <div className="col-span-2">
                                                <span className="text-muted-foreground">NHIF Authorization: </span>
                                                <span className="font-medium text-foreground">{activeAuth}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Submit Admission Request
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
