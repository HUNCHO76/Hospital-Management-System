import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { type InsuranceScheme, type InsuranceSchemeFormData } from '@/types/insurance-scheme';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface SchemeFormProps {
    scheme?: InsuranceScheme;
    providers: InsuranceProviderSummary[];
}

export function SchemeForm({ scheme, providers }: SchemeFormProps) {
    const isEditing = Boolean(scheme);

    const { data, setData, post, put, processing, errors } = useForm<InsuranceSchemeFormData>({
        insurance_provider_id: scheme ? String(scheme.insurance_provider_id) : '',
        name: scheme?.name ?? '',
        code: scheme?.code ?? '',
        description: scheme?.description ?? '',
        status: scheme?.status ?? 'active',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (scheme) {
            put(route('insurance.schemes.update', scheme.id));
        } else {
            post(route('insurance.schemes.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="insurance_provider_id">Insurance Provider *</Label>
                    <Select value={data.insurance_provider_id} onValueChange={(value) => setData('insurance_provider_id', value)}>
                        <SelectTrigger id="insurance_provider_id">
                            <SelectValue placeholder="Select a provider" />
                        </SelectTrigger>
                        <SelectContent>
                            {providers.map((provider) => (
                                <SelectItem key={provider.id} value={String(provider.id)}>
                                    {provider.name} ({provider.code})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.insurance_provider_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as InsuranceSchemeFormData['status'])}>
                        <SelectTrigger id="status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Scheme Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Scheme Code *</Label>
                    <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} required />
                    <InputError message={errors.code} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} />
                <InputError message={errors.description} />
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('insurance.schemes.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create scheme'}
                </Button>
            </div>
        </form>
    );
}
