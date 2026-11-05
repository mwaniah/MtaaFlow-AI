export interface SettingsData {
  business: { name: string; type: string; phone: string; email: string; address: string; kra_pin: string; logo_url: string | null }
  region: { currency: string; timezone: string; date_format: string; number_format: string }
  account: { name: string; role: string; email: string; phone: string }
  invoice: { prefix: string; next_number: string; due_days: number; vat_rate: number; note: string }
  payments: {
    paypal_link_on_invoices: boolean
    auto_mark_paid: boolean
    paypal: { connected: boolean; mode: 'sandbox' | 'live'; email: string }
  }
}

export const sampleSettings: SettingsData = {
  business: {
    name: 'Mwangi Traders', type: 'Retail · Apparel and accessories', phone: '0700 111 222',
    email: 'hello@mwangitraders.co.ke', address: 'Kasarani Road, Nairobi', kra_pin: 'P051234567X', logo_url: null,
  },
  region: { currency: 'KES', timezone: 'Africa/Nairobi', date_format: 'd M Y', number_format: '1,234.00' },
  account: { name: 'Wanjiru Mwangi', role: 'Owner', email: 'wanjiru@mwangitraders.co.ke', phone: '0722 000 111' },
  invoice: { prefix: 'INV-', next_number: '0044', due_days: 14, vat_rate: 0, note: 'Thank you for shopping with Mwangi Traders.' },
  payments: {
    paypal_link_on_invoices: true,
    auto_mark_paid: true,
    paypal: { connected: true, mode: 'sandbox', email: 'mwangi-business@sandbox.paypal.com' },
  },
}
