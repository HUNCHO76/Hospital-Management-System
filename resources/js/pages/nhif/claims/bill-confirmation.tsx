import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

interface Confirmation {
    id: number;
    nhif_authorization_no: string;
    facility_code: string;
    attendance_date: string;
    total_amount: string;
    confirmation_code: string | null;
    confirmation_status: string;
    confirmed_at: string | null;
    hospital: { name: string } | null;
}

interface Props {
    confirmations: { data: Confirmation[] };
}

const STATUS_COLORS: Record<string, string> = {
    confirmed: 'bg-green-100 text-green-700',
    pending: 'bg-yellow-100 text-yellow-700',
    failed: 'bg-red-100 text-red-700',
};

export default function BillConfirmation({ confirmations }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        patient_insurance_id: '',
        hospital_id: '',
        attendance_date: '',
        total_amount: '',
    });

    return (
        <AuthenticatedLayout>
            <Head title="NHIF Bill Confirmation" />

            <div className="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                <h1 className="text-2xl font-semibold">Bill Confirmation</h1>

                <form
                    onSubmit={e => {
                        e.preventDefault();
                        post(route('nhif.claims.bill-confirmation.store'));
                    }}
                    className="bg-white border rounded p-6 space-y-4"
                >
                    <h2 className="text-lg font-medium">Request Confirmation</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">Patient Insurance ID *</label>
                            <input
                                type="number"
                                value={data.patient_insurance_id}
                                onChange={e => setData('patient_insurance_id', e.target.value)}
                                className="w-full border-gray-300 rounded text-sm"
                            />
                            {errors.patient_insurance_id && <p className="text-red-500 text-xs mt-1">{errors.patient_insurance_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">Hospital ID *</label>
                            <input
                                type="number"
                                value={data.hospital_id}
                                onChange={e => setData('hospital_id', e.target.value)}
                                className="w-full border-gray-300 rounded text-sm"
                            />
                            {errors.hospital_id && <p className="text-red-500 text-xs mt-1">{errors.hospital_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">Attendance Date *</label>
                            <input
                                type="date"
                                value={data.attendance_date}
                                onChange={e => setData('attendance_date', e.target.value)}
                                className="w-full border-gray-300 rounded text-sm"
                            />
                            {errors.attendance_date && <p className="text-red-500 text-xs mt-1">{errors.attendance_date}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">Total Amount (TZS) *</label>
                            <input
                                type="number"
                                min={0}
                                step={0.01}
                                value={data.total_amount}
                                onChange={e => setData('total_amount', e.target.value)}
                                className="w-full border-gray-300 rounded text-sm"
                            />
                            {errors.total_amount && <p className="text-red-500 text-xs mt-1">{errors.total_amount}</p>}
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 text-sm"
                    >
                        Request Confirmation Code
                    </button>
                </form>

                <div className="bg-white border rounded overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b">
                            <tr>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Auth No</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Facility</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                                <th className="text-right px-4 py-3 font-medium text-gray-600">Amount</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Code</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {confirmations.data.map(c => (
                                <tr key={c.id}>
                                    <td className="px-4 py-3 font-mono text-xs">{c.nhif_authorization_no}</td>
                                    <td className="px-4 py-3">{c.facility_code}</td>
                                    <td className="px-4 py-3">{c.attendance_date}</td>
                                    <td className="px-4 py-3 text-right">{Number(c.total_amount).toLocaleString()}</td>
                                    <td className="px-4 py-3 font-mono">{c.confirmation_code ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[c.confirmation_status] ?? 'bg-gray-100'}`}>
                                            {c.confirmation_status}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
