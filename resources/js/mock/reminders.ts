export type ReminderGroup = 'overdue' | 'upcoming' | 'sent'
export type ReminderStatus = 'draft_ready' | 'not_sent' | 'scheduled' | 'sent'

export interface Reminder {
  id: number
  invoice_id: number
  invoice_number: string
  customer_name: string
  customer_phone: string | null
  amount: number
  currency: string
  group: ReminderGroup
  status: ReminderStatus
  status_label: string
  due_label: string
  message: string
}

export interface ReminderKpi { amount: number; count: number; currency: string }

export interface ReminderSummary {
  overdue: ReminderKpi
  due_this_week: ReminderKpi
  due_later: ReminderKpi
  sent_this_week: { count: number; paid_after_reminding: number }
}

export const sampleReminders: Reminder[] = [
  {
    id: 1, invoice_id: 39, invoice_number: 'INV-0039', customer_name: 'Peter Otieno', customer_phone: '0734 567 890',
    amount: 7000, currency: 'KSh', group: 'overdue', status: 'draft_ready', status_label: 'Draft ready', due_label: '4 days overdue',
    message: 'Hi Peter, a quick reminder that invoice INV-0039 for KSh 7,000 was due on 26 Oct. You can pay securely with PayPal here: pay.mtaaflow.app/inv-0039. Thank you for your business. Wanjiru, Mwangi Traders',
  },
  {
    id: 2, invoice_id: 37, invoice_number: 'INV-0037', customer_name: 'Daniel Kiprop', customer_phone: null,
    amount: 3100, currency: 'KSh', group: 'upcoming', status: 'not_sent', status_label: 'Not sent', due_label: 'Due in 2 days',
    message: 'Hi Daniel, a friendly heads-up that invoice INV-0037 for KSh 3,100 is due on 1 Nov. You can pay securely with PayPal here: pay.mtaaflow.app/inv-0037. Wanjiru, Mwangi Traders',
  },
  {
    id: 3, invoice_id: 40, invoice_number: 'INV-0040', customer_name: 'Lucy Achieng', customer_phone: null,
    amount: 2300, currency: 'KSh', group: 'upcoming', status: 'sent', status_label: 'Sent 28 Oct', due_label: 'Due in 4 days',
    message: 'Hi Lucy, a friendly heads-up that the balance of KSh 2,300 on invoice INV-0040 is due on 3 Nov. You can pay securely with PayPal here: pay.mtaaflow.app/inv-0040. Wanjiru, Mwangi Traders',
  },
  {
    id: 4, invoice_id: 43, invoice_number: 'INV-0043', customer_name: 'Brian Kamau', customer_phone: '0712 345 678',
    amount: 6100, currency: 'KSh', group: 'upcoming', status: 'scheduled', status_label: 'Scheduled 8 Nov', due_label: 'Due in 14 days',
    message: 'Hi Brian, a friendly heads-up that invoice INV-0043 for KSh 6,100 is due on 13 Nov. You can pay securely with PayPal here: pay.mtaaflow.app/inv-0043. Wanjiru, Mwangi Traders',
  },
]

export const sampleReminderSummary: ReminderSummary = {
  overdue: { amount: 7000, count: 1, currency: 'KSh' },
  due_this_week: { amount: 5400, count: 2, currency: 'KSh' },
  due_later: { amount: 6100, count: 1, currency: 'KSh' },
  sent_this_week: { count: 5, paid_after_reminding: 3 },
}
