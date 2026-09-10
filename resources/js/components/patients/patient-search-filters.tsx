import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type PatientFilters } from '@/types/patient';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

interface PatientSearchFiltersProps {
    filters: PatientFilters;
    onApply: (overrides: Partial<PatientFilters>) => void;
    onClear: () => void;
    hasActiveFilters: boolean;
}

const emptyDraft = {
    patient_number: '',
    national_id: '',
    passport_number: '',
    phone: '',
    date_of_birth: '',
    region: '',
    district: '',
};

/**
 * The advanced filter panel: exact-identifier and demographic fields a user
 * can combine with the free-text search (e.g. name + date of birth, or
 * national ID + date of birth) to narrow results precisely.
 */
export function PatientSearchFilters({ filters, onApply, onClear, hasActiveFilters }: PatientSearchFiltersProps) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState({
        patient_number: filters.patient_number ?? '',
        national_id: filters.national_id ?? '',
        passport_number: filters.passport_number ?? '',
        phone: filters.phone ?? '',
        date_of_birth: filters.date_of_birth ?? '',
        region: filters.region ?? '',
        district: filters.district ?? '',
    });

    const applyDraft = () => onApply(draft);

    const clearAll = () => {
        setDraft(emptyDraft);
        onClear();
    };

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div className="flex items-center justify-between">
                <CollapsibleTrigger asChild>
                    <Button type="button" variant="outline" size="sm" aria-expanded={open}>
                        <SlidersHorizontal className="h-4 w-4" />
                        Advanced Filters
                    </Button>
                </CollapsibleTrigger>

                {hasActiveFilters && (
                    <Button type="button" variant="ghost" size="sm" onClick={clearAll}>
                        Clear filters
                    </Button>
                )}
            </div>

            <CollapsibleContent className="border-sidebar-border/70 dark:border-sidebar-border mt-3 rounded-lg border p-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-patient-number">Patient Number</Label>
                        <Input
                            id="filter-patient-number"
                            value={draft.patient_number}
                            onChange={(e) => setDraft({ ...draft, patient_number: e.target.value })}
                            placeholder="PT-2026-000123"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-national-id">National ID</Label>
                        <Input
                            id="filter-national-id"
                            value={draft.national_id}
                            onChange={(e) => setDraft({ ...draft, national_id: e.target.value })}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-passport">Passport Number</Label>
                        <Input
                            id="filter-passport"
                            value={draft.passport_number}
                            onChange={(e) => setDraft({ ...draft, passport_number: e.target.value })}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-phone">Phone</Label>
                        <Input id="filter-phone" value={draft.phone} onChange={(e) => setDraft({ ...draft, phone: e.target.value })} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-dob">Date of Birth</Label>
                        <Input
                            id="filter-dob"
                            type="date"
                            value={draft.date_of_birth}
                            onChange={(e) => setDraft({ ...draft, date_of_birth: e.target.value })}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-gender">Gender</Label>
                        <Select value={filters.gender || 'all'} onValueChange={(value) => onApply({ gender: value === 'all' ? '' : value })}>
                            <SelectTrigger id="filter-gender">
                                <SelectValue placeholder="Gender" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All genders</SelectItem>
                                <SelectItem value="male">Male</SelectItem>
                                <SelectItem value="female">Female</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-status">Status</Label>
                        <Select value={filters.status || 'all'} onValueChange={(value) => onApply({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger id="filter-status">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Inactive</SelectItem>
                                <SelectItem value="deceased">Deceased</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-region">Region</Label>
                        <Input id="filter-region" value={draft.region} onChange={(e) => setDraft({ ...draft, region: e.target.value })} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-district">District</Label>
                        <Input id="filter-district" value={draft.district} onChange={(e) => setDraft({ ...draft, district: e.target.value })} />
                    </div>
                </div>

                <div className="mt-4 flex justify-end gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={clearAll}>
                        Reset
                    </Button>
                    <Button type="button" size="sm" onClick={applyDraft}>
                        Apply Filters
                    </Button>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
