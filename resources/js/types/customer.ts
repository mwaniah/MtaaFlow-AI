export type CustomerStatus = 'pending' | 'overdue' | 'partial' | 'up_to_date';

export interface Customer {
    id: number;
    name: string;
    email: string | null;
    phone: string;
    total_purchases: number;
    outstanding: number;
    open_invoices: number;
    status: CustomerStatus;
    last_purchase: string | null; // '2026-10-30'
    created_at: string; // '2026-03-12'
}