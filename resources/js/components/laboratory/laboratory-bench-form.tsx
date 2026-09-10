import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type LaboratoryBench, type LaboratoryBenchFormData } from '@/types/laboratory';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface LaboratoryBenchFormProps {
    bench?: LaboratoryBench;
}

export function LaboratoryBenchForm({ bench }: LaboratoryBenchFormProps) {
    const isEditing = Boolean(bench);

    const { data, setData, post, put, processing, errors } = useForm<LaboratoryBenchFormData>({
        name: bench?.name ?? '',
        code: bench?.code ?? '',
        description: bench?.description ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (bench) {
            put(route('laboratory-benches.update', bench.id));
        } else {
            post(route('laboratory-benches.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Bench Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Bench Code *</Label>
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
                    <Link href={route('laboratory-benches.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create bench'}
                </Button>
            </div>
        </form>
    );
}
