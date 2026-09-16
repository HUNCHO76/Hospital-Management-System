import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { serviceCategoryLabel } from '@/lib/service-status';
import { type CreateServiceFormData, type Service, type ServiceCategory } from '@/types/service';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ServiceFormProps {
    service?: Service;
    categories: ServiceCategory[];
}

/**
 * Shared create/edit form (Phase 7, Step 53) — mirrors MedicationForm's own
 * create/edit-sharing convention exactly. `code` is locked once the service
 * has been priced (`service.is_referenced`) — the backend is the real
 * authority; disabling the field here is only a UI convenience.
 */
export function ServiceForm({ service, categories }: ServiceFormProps) {
    const isEditing = Boolean(service);
    const isCodeLocked = Boolean(service?.is_referenced);

    const { data, setData, post, put, processing, errors } = useForm<CreateServiceFormData>({
        code: service?.code ?? '',
        name: service?.name ?? '',
        description: service?.description ?? '',
        category: service?.category ?? '',
        is_billable: service?.is_billable ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (service) {
            put(route('billing-services.update', service.id));
        } else {
            post(route('billing-services.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="code">Service Code *</Label>
                    <Input
                        id="code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                        disabled={isCodeLocked}
                        required
                        autoFocus
                    />
                    {isCodeLocked && <p className="text-muted-foreground text-xs">Locked — this service has already been priced.</p>}
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label>Category *</Label>
                    <Select value={data.category} onValueChange={(value) => setData('category', value as ServiceCategory)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Select category" />
                        </SelectTrigger>
                        <SelectContent>
                            {categories.map((category) => (
                                <SelectItem key={category} value={category}>
                                    {serviceCategoryLabel[category]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.category} />
                </div>

                <div className="flex items-center gap-2 pt-7">
                    <Checkbox id="is_billable" checked={data.is_billable} onCheckedChange={(checked) => setData('is_billable', checked === true)} />
                    <Label htmlFor="is_billable" className="font-normal">
                        Billable — this service may be charged to a patient account
                    </Label>
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                <InputError message={errors.description} />
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('billing-services.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create service'}
                </Button>
            </div>
        </form>
    );
}
