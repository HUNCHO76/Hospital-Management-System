import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { type NhifLookupMember } from '@/types/patient-insurance';
import { CheckCircle2 } from 'lucide-react';

interface NHIFMemberPreviewProps {
    member: NhifLookupMember;
}

/**
 * Shows the normalized NHIF member data returned by a successful
 * GetCardDetails / GetCardDetailsByNIN lookup, before the receptionist
 * submits registration. NHIF does not return phone, so that still needs to
 * be collected manually — everything else here comes straight from the
 * response (see NhifMemberVerificationService::normalize()).
 */
export function NHIFMemberPreview({ member }: NHIFMemberPreviewProps) {
    const rows: Array<[string, string | null]> = [
        ['Name', member.member_name],
        ['Gender', member.gender ? member.gender.charAt(0).toUpperCase() + member.gender.slice(1) : null],
        ['Date of Birth', member.date_of_birth],
        ['Membership Number', member.membership_number],
        ['Card Number', member.card_number],
        ['Plan', member.plan_name],
    ].filter(([, value]) => Boolean(value)) as Array<[string, string | null]>;

    const pictureSrc = member.member_picture || '/images/default-avatar.svg';

    return (
        <Alert className="border-green-500/40 bg-green-50 text-green-900 dark:border-green-500/30 dark:bg-green-950 dark:text-green-200">
            <CheckCircle2 className="h-4 w-4" />
            <AlertTitle>NHIF member found</AlertTitle>
            <AlertDescription className="text-current">
                <p className="mb-3">Patient details loaded. Review the information below before completing registration.</p>
                <div className="flex flex-col sm:flex-row items-start gap-4">
                    <div className="h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-green-300 dark:border-green-700 bg-white/50 dark:bg-black/20">
                        <img
                            src={pictureSrc}
                            alt={member.member_name ?? 'NHIF Member'}
                            className="h-full w-full object-cover object-top"
                            onError={(e) => {
                                e.currentTarget.src = '/images/default-avatar.svg';
                            }}
                        />
                    </div>
                    <dl className="grid flex-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                        {rows.map(([label, value]) => (
                            <div key={label} className="flex justify-between gap-2 text-sm sm:justify-start">
                                <dt className="font-medium">{label}:</dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </AlertDescription>
        </Alert>
    );
}
