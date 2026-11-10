export type InvoiceStatus =
    | 'paid'
    | 'pending'
    | 'partial'
    | 'overdue'
    | 'draft'
    | 'cancelled';

export interface Invoice {
    id: number;
    number: string; // 'INV-0043'
    customer_name: string;
    description: string;
    total_amount: number;
    amount_paid: number;
    balance: number; // calculated by the backend: total - paid
    status: InvoiceStatus;
    created_at: string; // '2026-11-03'
}
