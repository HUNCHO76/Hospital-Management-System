import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { coverageTypeLabel } from '@/lib/insurance-billing-status';
import { type BreadcrumbItem } from '@/types';
import { type CreateInsuranceCoverageRuleFormData } from '@/types/insurance-billing';
import { type ServiceCategory } from '@/types/service';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Insurance Rules', href: '/billing/insurance-rules' },
    { title: 'New Rule', href: '/billing/insurance-rules/create' },
];

interface BillingInsuranceRuleCreateProps {
    schemes: { id: number; name: string; code: string }[];
    services: { id: number; code: string; name: string; category: ServiceCategory }[];
    categories: ServiceCategory[];
}

type TargetMode = 'default' | 'category' | 'service';

/**
 * Configure how much of a service (or a whole service category, or the
 * scheme's own default) an insurance scheme covers (Phase 7, Step 56, spec
 * §13). A rule is never edited — this form only ever creates a new one; an
 * overlapping open-ended rule for the identical target is automatically
 * closed out the day before this one starts
 * (InsuranceCoverageRuleService::create()'s own versioning logic, mirrors
 * service pricing exactly). Backend re-validates and re-checks for overlap
 * regardless of what this form allows.
 */
export default function BillingInsuranceRuleCreate({ schemes, services, categories }: BillingInsuranceRuleCreateProps) {
    const { data, setData, post, processing, errors } = useForm<CreateInsuranceCoverageRuleFormData>({
        insurance_scheme_id: '',
        service_id: '',
        category: '',
        coverage_type: '',
        coverage_percentage: '',
        fixed_copay_amount: '',
        requires_preauthorization: false,
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: '',
    });

    const targetMode: TargetMode = data.service_id ? 'service' : data.category ? 'category' : 'default';

    const setTargetMode = (mode: TargetMode) => {
        setData((current) => ({
            ...current,
            service_id: mode === 'service' ? current.service_id : '',
            category: mode === 'category' ? current.category : '',
        }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing-insurance-rules.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Coverage Rule" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>New Coverage Rule</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label>Insurance Scheme *</Label>
                                <Select value={data.insurance_scheme_id} onValueChange={(value) => setData('insurance_scheme_id', value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select scheme" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {schemes.map((scheme) => (
                                            <SelectItem key={scheme.id} value={String(scheme.id)}>
                                                {scheme.code} — {scheme.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.insurance_scheme_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Applies To</Label>
                                <Select value={targetMode} onValueChange={(value) => setTargetMode(value as TargetMode)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="default">This scheme's default (every service, unless overridden)</SelectItem>
                                        <SelectItem value="category">A whole service category</SelectItem>
                                        <SelectItem value="service">One specific service</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-xs">
                                    The most specific matching rule wins: a specific service overrides its category, which overrides the scheme's
                                    own default.
                                </p>
                            </div>

                            {targetMode === 'category' && (
                                <div className="grid gap-2">
                                    <Label>Category *</Label>
                                    <Select value={data.category} onValueChange={(value) => setData('category', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((category) => (
                                                <SelectItem key={category} value={category} className="capitalize">
                                                    {category}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.category} />
                                </div>
                            )}

                            {targetMode === 'service' && (
                                <div className="grid gap-2">
                                    <Label>Service *</Label>
                                    <Select value={data.service_id} onValueChange={(value) => setData('service_id', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select service" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {services.map((service) => (
                                                <SelectItem key={service.id} value={String(service.id)}>
                                                    {service.code} — {service.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.service_id} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label>Coverage Type *</Label>
                                <Select
                                    value={data.coverage_type}
                                    onValueChange={(value) => setData('coverage_type', value as CreateInsuranceCoverageRuleFormData['coverage_type'])}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select coverage type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(Object.keys(coverageTypeLabel) as (keyof typeof coverageTypeLabel)[]).map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {coverageTypeLabel[type]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.coverage_type} />
                            </div>

                            {data.coverage_type === 'percentage' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="coverage_percentage">Coverage Percentage *</Label>
                                    <Input
                                        id="coverage_percentage"
                                        type="number"
                                        min={0}
                                        max={100}
                                        step="0.01"
                                        value={data.coverage_percentage}
                                        onChange={(e) => setData('coverage_percentage', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.coverage_percentage} />
                                </div>
                            )}

                            {data.coverage_type === 'fixed_copay' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="fixed_copay_amount">Fixed Copayment Amount *</Label>
                                    <Input
                                        id="fixed_copay_amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.fixed_copay_amount}
                                        onChange={(e) => setData('fixed_copay_amount', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.fixed_copay_amount} />
                                    <p className="text-muted-foreground text-xs">
                                        The patient pays this flat amount (capped at the charge's own amount); insurance covers the rest.
                                    </p>
                                </div>
                            )}

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="requires_preauthorization"
                                    checked={data.requires_preauthorization}
                                    onCheckedChange={(checked) => setData('requires_preauthorization', checked === true)}
                                />
                                <Label htmlFor="requires_preauthorization" className="font-normal">
                                    Requires preauthorization (informational only — shown on the coverage breakdown, never blocks calculation)
                                </Label>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="effective_from">Effective From *</Label>
                                    <Input
                                        id="effective_from"
                                        type="date"
                                        value={data.effective_from}
                                        onChange={(e) => setData('effective_from', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.effective_from} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="effective_to">Effective To</Label>
                                    <Input
                                        id="effective_to"
                                        type="date"
                                        value={data.effective_to}
                                        onChange={(e) => setData('effective_to', e.target.value)}
                                        placeholder="Leave blank for ongoing"
                                    />
                                    <InputError message={errors.effective_to} />
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('billing-insurance-rules.index')}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Save Rule
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
