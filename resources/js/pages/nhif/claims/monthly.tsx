import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

interface Submission {
    id: number;
    claim_year: number;
    claim_month: number;
    facility_code: string;
    folios_submitted: number;
    total_amount_claimed: string;
    status: string;
    submitted_at: string | null;
    hospital: { name: string } | null;
}

interface Props {
    submissions: {
        data: Submission[];
        current_page: number;
        last_page: number;
    };
}

const STATUS_COLORS: Record<string, string> = {
    submitted: 'bg-green-100 text-green-700',
    pending: 'bg-yellow-100 text-yellow-700',
    failed: 'bg-red-100 text-red-700',
};

export default function Monthly({ submissions }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        hospital_id: '',
        claim_year: new Date().getFullYear(),
        claim_month: new Date().getMonth() + 1,
        submission_remarks: '',
    });

    return (
        <AppLayout>
            <Head title="Monthly Claim Submission" />

            <div className="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                <h1 className="text-2xl font-semibold">Monthly Claim Submission</h1>

                <form
                    onSubmit={e => {
                        e.preventDefault();
                        post(route('nhif.claims.monthly.submit'));
                    }}
                    className="bg-white border rounded p-6 space-y-4"
                >
                    <h2 className="text-lg font-medium">New Submission</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">Claim Year *</label>
                            <input
                                type="number"
                                value={data.claim_year}
                                onChange={e => setData('claim_year', parseInt(e.target.value))}
                                className="w-full border-gray-300 rounded text-sm"
                                min={2000}
                                max={2099}
                            />
                            {errors.claim_year && <p className="text-red-500 text-xs mt-1">{errors.claim_year}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium mb-1">Claim Month *</label>
                            <select
                                value={data.claim_month}
                                onChange={e => setData('claim_month', parseInt(e.target.value))}
                                className="w-full border-gray-300 rounded text-sm"
                            >
                                {Array.from({ length: 12 }, (_, i) => i + 1).map(m => (
                                    <option key={m} value={m}>{new Date(2000, m - 1).toLocaleString('default', { month: 'long' })} ({m})</option>
                                ))}
                            </select>
                            {errors.claim_month && <p className="text-red-500 text-xs mt-1">{errors.claim_month}</p>}
                        </div>

                        <div className="col-span-2">
                            <label className="block text-sm font-medium mb-1">Submission Remarks</label>
                            <textarea
                                value={data.submission_remarks}
                                onChange={e => setData('submission_remarks', e.target.value)}
                                className="w-full border-gray-300 rounded text-sm"
                                rows={2}
                            />
                        </div>
                    </div>

                    {errors.folios && <p className="text-red-600 text-sm">{errors.folios}</p>}
                    {errors.period && <p className="text-red-600 text-sm">{errors.period}</p>}

                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 text-sm"
                    >
                        Submit Monthly Claim to NHIF
                    </button>
                    <p className="text-xs text-gray-400">Totals are calculated server-side from submitted folios — never entered manually.</p>
                </form>

                <div className="bg-white border rounded overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 border-b">
                            <tr>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Period</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Facility</th>
                                <th className="text-right px-4 py-3 font-medium text-gray-600">Folios</th>
                                <th className="text-right px-4 py-3 font-medium text-gray-600">Total Claimed</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                                <th className="text-left px-4 py-3 font-medium text-gray-600">Submitted At</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {submissions.data.map(s => (
                                <tr key={s.id}>
                                    <td className="px-4 py-3">{s.claim_year}-{String(s.claim_month).padStart(2, '0')}</td>
                                    <td className="px-4 py-3">{s.facility_code}</td>
                                    <td className="px-4 py-3 text-right">{s.folios_submitted}</td>
                                    <td className="px-4 py-3 text-right">{Number(s.total_amount_claimed).toLocaleString()}</td>
                                    <td className="px-4 py-3">
                                        <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[s.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                            {s.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500">{s.submitted_at ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
