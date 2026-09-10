import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type PatientContactFormData } from '@/types/patient';
import { Plus, Trash2 } from 'lucide-react';

interface PatientContactFormProps {
    contacts: PatientContactFormData[];
    onChange: (contacts: PatientContactFormData[]) => void;
    errors: Partial<Record<string, string>>;
}

const emptyContact: PatientContactFormData = {
    name: '',
    relationship: '',
    phone: '',
    alternative_phone: '',
    address: '',
    is_primary: false,
};

export function PatientContactForm({ contacts, onChange, errors }: PatientContactFormProps) {
    const updateContact = (index: number, field: keyof PatientContactFormData, value: string | boolean) => {
        const next = contacts.map((contact, i) => (i === index ? { ...contact, [field]: value } : contact));
        onChange(next);
    };

    const addContact = () => onChange([...contacts, { ...emptyContact }]);

    const removeContact = (index: number) => onChange(contacts.filter((_, i) => i !== index));

    return (
        <div className="space-y-4">
            {errors.contacts && <p className="text-destructive text-sm">{errors.contacts}</p>}

            {contacts.map((contact, index) => (
                <div key={index} className="space-y-4 rounded-lg border p-4">
                    <div className="flex items-center justify-between">
                        <h4 className="text-sm font-medium">Contact {index + 1}</h4>
                        <Button type="button" variant="ghost" size="icon" onClick={() => removeContact(index)} aria-label="Remove contact">
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor={`contact_${index}_name`}>Name *</Label>
                            <Input
                                id={`contact_${index}_name`}
                                value={contact.name}
                                onChange={(e) => updateContact(index, 'name', e.target.value)}
                            />
                            <InputError message={errors[`contacts.${index}.name`]} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`contact_${index}_relationship`}>Relationship *</Label>
                            <Input
                                id={`contact_${index}_relationship`}
                                placeholder="e.g. Spouse, Parent, Sibling"
                                value={contact.relationship}
                                onChange={(e) => updateContact(index, 'relationship', e.target.value)}
                            />
                            <InputError message={errors[`contacts.${index}.relationship`]} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`contact_${index}_phone`}>Phone *</Label>
                            <Input
                                id={`contact_${index}_phone`}
                                value={contact.phone}
                                onChange={(e) => updateContact(index, 'phone', e.target.value)}
                            />
                            <InputError message={errors[`contacts.${index}.phone`]} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={`contact_${index}_alternative_phone`}>Alternative Phone</Label>
                            <Input
                                id={`contact_${index}_alternative_phone`}
                                value={contact.alternative_phone}
                                onChange={(e) => updateContact(index, 'alternative_phone', e.target.value)}
                            />
                            <InputError message={errors[`contacts.${index}.alternative_phone`]} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor={`contact_${index}_address`}>Address</Label>
                            <Input
                                id={`contact_${index}_address`}
                                value={contact.address}
                                onChange={(e) => updateContact(index, 'address', e.target.value)}
                            />
                            <InputError message={errors[`contacts.${index}.address`]} />
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`contact_${index}_primary`}
                            checked={contact.is_primary}
                            onCheckedChange={(checked) => updateContact(index, 'is_primary', checked === true)}
                        />
                        <Label htmlFor={`contact_${index}_primary`}>Primary contact</Label>
                    </div>
                </div>
            ))}

            <Button type="button" variant="outline" size="sm" onClick={addContact}>
                <Plus className="h-4 w-4" />
                Add Contact
            </Button>
        </div>
    );
}
