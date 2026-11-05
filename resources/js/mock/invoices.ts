import type { Invoice } from '@/types/invoice'

export const sampleInvoices: Invoice[] = [
  { id: 43, number: 'INV-0043', customer_name: 'Brian Kamau', issue_date: '2026-10-30', due_date: '2026-11-13', amount: 6100, paid: 0, status: 'pending' },
  { id: 41, number: 'INV-0041', customer_name: 'Amina Hassan', issue_date: '2026-10-27', due_date: '2026-11-10', amount: 12500, paid: 12500, status: 'paid' },
  { id: 40, number: 'INV-0040', customer_name: 'Lucy Achieng', issue_date: '2026-10-20', due_date: '2026-11-03', amount: 4600, paid: 2300, status: 'partially_paid' },
  { id: 39, number: 'INV-0039', customer_name: 'Peter Otieno', issue_date: '2026-10-12', due_date: '2026-10-26', amount: 7000, paid: 0, status: 'overdue' },
  { id: 38, number: 'INV-0038', customer_name: 'Grace Njeri', issue_date: '2026-10-25', due_date: '2026-11-08', amount: 3200, paid: 0, status: 'draft' },
  { id: 37, number: 'INV-0037', customer_name: 'Daniel Kiprop', issue_date: '2026-10-18', due_date: '2026-11-01', amount: 3100, paid: 0, status: 'pending' },
  { id: 35, number: 'INV-0035', customer_name: 'Joseph Mutua', issue_date: '2026-10-22', due_date: '2026-11-05', amount: 4800, paid: 4800, status: 'paid' },
  { id: 34, number: 'INV-0034', customer_name: 'Mary Wambui', issue_date: '2026-10-05', due_date: '2026-10-19', amount: 2400, paid: 0, status: 'cancelled' },
]
