import { type PaymentMethod, type PaymentStatus } from '@/types/payment';

export const paymentStatusLabel: Record<PaymentStatus, string> = {
    posted: 'Posted',
    failed: 'Failed',
};

export const paymentStatusVariant: Record<PaymentStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    posted: 'default',
    failed: 'destructive',
};

export const paymentMethodLabel: Record<PaymentMethod, string> = {
    cash: 'Cash',
    card: 'Card',
    bank_transfer: 'Bank Transfer',
    mobile_money: 'Mobile Money',
    other: 'Other',
};

export const invoicePaymentStatusLabel: Record<'unpaid' | 'partially_paid' | 'paid', string> = {
    unpaid: 'Unpaid',
    partially_paid: 'Partially Paid',
    paid: 'Paid',
};

export const invoicePaymentStatusVariant: Record<'unpaid' | 'partially_paid' | 'paid', 'default' | 'secondary' | 'destructive' | 'outline'> = {
    unpaid: 'destructive',
    partially_paid: 'secondary',
    paid: 'default',
};
