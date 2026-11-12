# MtaaFlow AI

**AI-assisted payment and invoice workflow for small businesses.**
Type an order in plain language, review what the AI extracted, save it as an invoice, and collect payment through PayPal Sandbox.

Built for the [PayPal AI Hackathon](https://paypalaihackathon.devpost.com/).

| | |
|---|---|
| **Live demo** | https://mtaaflow-ai-1.onrender.com/ |
| **Demo video** | [TODO: YouTube link] |
| **Devpost submission** | [TODO: link] |

> The demo is hosted on Render's free tier. If it has been idle, the first load can take 30-60 seconds while the instance wakes up. All payments use the **PayPal Sandbox**; no real money moves.

---

## The Problem

Many small-business owners track orders, payments and balances in chat messages, notebooks or spreadsheets. That makes records inconsistent and makes it hard to see who still owes what or to follow up with customers.

## The Solution

MtaaFlow AI turns a simple instruction into a structured order/invoice record and makes payment status easy to understand.

Example input:

> "Wanjiku ordered goods worth KSh 3,500 and has paid KSh 1,000."

The AI proposes the customer, description, total and amount paid. The owner reviews and confirms. The app saves the invoice and calculates the balance (**KSh 2,500**) in code. The owner can then start a PayPal Sandbox checkout, and the invoice is updated only after the backend verifies the payment with PayPal.

## Features

- **Dashboard** with invoices, outstanding balances and recent payment statuses
- **Customer and invoice management**: create, view and edit, manually or via AI
- **AI extraction** of structured fields from plain-language input
- **Human confirmation**: AI output is shown as a suggestion and nothing is saved or sent until the user confirms
- **Deterministic balance calculation**: balance = total - amount paid, computed by application code with validation against invalid or negative values
- **PayPal Sandbox payment flow** handling success, cancellation and failure
- **Server-side payment verification**: an invoice is never marked paid from a browser redirect or an AI response alone
- **AI summary** of outstanding invoices from saved data
- **Graceful fallback**: if Groq is unavailable the chatbot falls back to Gemini, and if both are down invoices can still be created manually
- **Seeded demo data** for a repeatable walkthrough

## Design Principles

1. The **AI suggests** structured data.
2. The **backend validates** it.
3. The **database stores** only confirmed records.
4. **PayPal confirms** payment state.

The AI is never the authority for amounts or payment status.

## How It Uses PayPal

- PayPal Developer **Sandbox** with the Orders/Checkout API
- Backend creates the order and **verifies/captures** it with PayPal before updating the invoice
- Payment records store the PayPal order/capture IDs, amount, currency and verification time
- Success, cancel and failure paths are all handled

## How It Uses AI

- **Providers:** **Groq** (primary) with **Google Gemini** as the automatic fallback. If a Groq request fails or is unavailable, the app retries with Gemini. 
- **Chatbot:** the AI assistant is delivered through a chat interface
- **Extraction:** natural-language order text to schema-constrained JSON (customer, description, total, amount paid)
- **Summary:** a concise overview of outstanding invoices from saved data
- Malformed or invalid AI output is rejected safely, and the user can edit every field before saving

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | Vue.js |
| Backend | Laravel (PHP) |
| Database | MySQL |
| Payments | PayPal Developer Sandbox |
| AI | Groq (primary), Google Gemini (fallback) |
| Hosting | Render |

## Data Model

- **Customer**: id, name, minimal contact details, created_at
- **Invoice**: id, customer_id, reference, description, total_amount, amount_paid, currency, status, due_date, created_at
- **Payment**: id, invoice_id, provider, provider_order_id, provider_capture_id, amount, currency, status, verified_at, created_at
- **AIRequest** (optional): redacted input, proposed fields JSON, confirmation status

Money is stored as fixed-precision decimals (not floats).

---

## Testing the Live Demo (for Judges)

1. Open https://mtaaflow-ai-1.onrender.com/
2. Sign in: [TODO: demo credentials, or state "no login required / demo mode"]
3. On the dashboard, enter a prompt such as: `Wanjiku ordered goods worth KSh 3,500 and has paid KSh 1,000.`
4. Review the AI-suggested fields, edit if needed, and **confirm**. The invoice is saved with a balance of KSh 2,500.
5. Open the invoice and start the **PayPal Sandbox** checkout.
6. Log in with the sandbox buyer account:
   - Email: `[TODO]`
   - Password: `[TODO]`
7. Complete (or cancel) the payment and return to the app. The status updates only after server-side verification.
8. Try the **outstanding invoices summary** on the dashboard.

Seeded sample records are clearly labelled as demo data.

---

## Run Locally

### Prerequisites

- PHP 8.2+ and Composer
- Node.js 18+ and npm
- MySQL
- A free PayPal developer account: https://developer.paypal.com/ (create a Sandbox app to get a client ID and secret)
- A Groq API key (https://console.groq.com/) and a Google Gemini API key (https://aistudio.google.com/)

### Setup

```bash
git clone [TODO: repo URL]
cd [TODO: repo folder]

composer install
npm install

cp .env.example .env
php artisan key:generate
```

### Environment variables

Set these in `.env` (never commit real keys). Match the names to your `.env.example`:

```env
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mtaaflow
DB_USERNAME=your_user
DB_PASSWORD=your_password

PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=your_sandbox_client_id
PAYPAL_CLIENT_SECRET=your_sandbox_client_secret

# AI providers (Groq is primary, Gemini is the fallback)
GROQ_API_KEY=your_groq_api_key
GEMINI_API_KEY=your_gemini_api_key
```

### Database and run

```bash
php artisan migrate --seed   # creates tables and demo data
npm run dev                  # or: npm run build
php artisan serve
```

Open http://localhost:8000.

### Tests

```bash
php artisan test
```

### Troubleshooting

- **AI features not working:** check `GROQ_API_KEY` and `GEMINI_API_KEY`. If Groq fails the app falls back to Gemini; if both are unavailable, manual invoice creation still works.
- **PayPal errors:** confirm you are using Sandbox credentials and `PAYPAL_MODE=sandbox`.
- **Slow first load on the live demo:** Render's free tier sleeps when idle; wait a minute and refresh.
- **Database errors:** confirm MySQL is running and the credentials in `.env` are correct.

---

## Security and Privacy

- PayPal and AI keys live in server-side environment variables and are never committed
- Sandbox/test data only; no card details are stored
- Minimal personal data is sent to the AI

## Limitations

- Prototype built with PayPal Sandbox; not for real customer payments
- Not accounting software and does not give financial advice
- No direct M-Pesa integration; PayPal may not cover every local payment need
- AI output is a suggestion and always requires user confirmation

## Roadmap

- Sign-in and multi-user support
- Additional local payment options
- Invoice reminders sent only on explicit user action
- Multi-currency support

## Team

- Sylvia Njagi
- Mwania Delphine

## License

Released under the MIT License. 

---

*MtaaFlow AI is a working project name.*
