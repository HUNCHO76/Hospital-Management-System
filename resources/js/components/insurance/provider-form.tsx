import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type InsuranceProvider, type InsuranceProviderFormData } from '@/types/insurance-provider';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ProviderFormProps {
    provider?: InsuranceProvider;
    types: string[];
}

export function ProviderForm({ provider, types }: ProviderFormProps) {
    const isEditing = Boolean(provider);

    const { data, setData, post, put, processing, errors } = useForm<InsuranceProviderFormData>({
        name: provider?.name ?? '',
        code: provider?.code ?? '',
        registration_number: provider?.registration_number ?? '',
        type: provider?.type ?? '',
        phone: provider?.phone ?? '',
        email: provider?.email ?? '',
        address: provider?.address ?? '',
        api_enabled: provider?.api_enabled ?? false,
        status: provider?.status ?? 'active',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (provider) {
            put(route('insurance.providers.update', provider.id));
        } else {
            post(route('insurance.providers.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Provider Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Code *</Label>
                    <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} required />
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="registration_number">Registration Number</Label>
                    <Input
                        id="registration_number"
                        value={data.registration_number}
                        onChange={(e) => setData('registration_number', e.target.value)}
                    />
                    <InputError message={errors.registration_number} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="type">Type *</Label>
                    <Select value={data.type} onValueChange={(value) => setData('type', value as InsuranceProviderFormData['type'])}>
                        <SelectTrigger id="type">
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="phone">Phone</Label>
                    <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                    <InputError message={errors.phone} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Status *</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value as InsuranceProviderFormData['status'])}>
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

                <div className="flex items-center gap-3 pt-6">
                    <Checkbox
                        id="api_enabled"
                        checked={data.api_enabled}
                        onCheckedChange={(checked) => setData('api_enabled', checked === true)}
                    />
                    <Label htmlFor="api_enabled">API integration enabled (configured separately)</Label>
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="address">Address</Label>
                <Textarea id="address" value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} />
                <InputError message={errors.address} />
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('insurance.providers.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create provider'}
                </Button>
            </div>
        </form>
    );
}
