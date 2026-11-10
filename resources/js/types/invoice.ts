export type InvoiceStatus = 'draft' | 'pending' | 'partially_paid' | 'paid' | 'overdue' | 'cancelled'

export interface InvoiceItem {
  description: string
  quantity: number
  unit_price: number
}

export interface Invoice {
  id: number | string
  number: string
  customer_id?: number | string
  customer_name: string
  currency?: string
  issue_date: string
  due_date: string
  amount: number
  paid: number
  status: InvoiceStatus
  items?: InvoiceItem[]
}

export const invoiceStatusLabels: Record<InvoiceStatus, string> = {
  draft: 'Draft',
  pending: 'Pending',
  partially_paid: 'Partially paid',
  paid: 'Paid',
  overdue: 'Overdue',
  cancelled: 'Cancelled',
}

export const outstandingStatuses: InvoiceStatus[] = ['pending', 'partially_paid', 'overdue']

export interface InvoiceActivity {
  label: string
  time: string
  done: boolean
}

export interface InvoiceBusiness {
  name: string
  address?: string
  phone?: string
}

export interface InvoiceCustomer {
  name: string
  phone?: string
  email?: string
}

export interface InvoiceShowProps {
  invoice: Invoice
  business?: InvoiceBusiness
  customer?: InvoiceCustomer
  payment_url?: string
  activity?: InvoiceActivity[]
}
