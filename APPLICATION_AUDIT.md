# MtaaFlow application audit

Audit date: 12 November 2026. Branch: `MtaaFlow-Backend`.

This matrix records the existing application before this audit's fixes. Implementation results and executed checks are recorded below as each phase completes. Existing staged and unstaged work is preserved.

## Capability matrix

| Module | Existing application operations | Existing AI support | Confirmed gap or defect | Required change and main files |
| --- | --- | --- | --- | --- |
| Authentication/accounts | Registration, login/logout, password reset/change, profile management, 2FA and passkey flows | Explains account features; no credential or security mutations | No authentication rewrite required; conversation storage cannot depend on login-session lifetime | Preserve Fortify and ownership checks: `app/Providers/FortifyServiceProvider.php`, `routes/settings.php`, `app/Models/User.php` |
| Dashboard | Account-scoped totals, earnings, outstanding invoices and recent activity | Verified summary/revenue reads | No independent aggregation defect found; verify status changes propagate from payments | Preserve completed-only, same-currency earnings: `DashboardController.php`, `pages/Dashboard.vue` |
| Customers | Create, list, view, update and delete without invoices; required name/phone, optional email/address | Confirmed create/update/delete; list/count | No targeted lookup/details or customer invoice/payment history filters | Add scoped selectors, ambiguity choices and filters: `AccountOperations.php`, `AskAccountContext.php` |
| Products/inventory | Create, list/view, edit metadata, soft delete, signed stock adjustments with audit records and low-stock thresholds | Confirmed create, metadata update and stock adjustment; list/count/low stock | Creation versus stock-adjustment intent is underspecified; targeted details/search and duplicate-name ID selection missing | Native tool schemas, explicit creation instructions, scoped selectors and ID-based stock adjustment: `AskAssistant.php`, providers, `AccountOperations.php`, `AskAccountContext.php` |
| Product selling prices | No price or currency fields, price API or price UI | Correctly unsupported in current model | Requested price update cannot be performed against nonexistent fields | User explicitly chose to preserve this model. Explain the limitation and support explicit invoice-item prices; reject price fields rather than silently discard them |
| Invoices/items | Draft creation/edit/delete; calculated totals; draft approval; approved/paid records and balances | Draft preparation/explicit confirmation; status-filtered count/list/outstanding | No AI draft edit/append/approve, targeted details or overdue filters; incomplete invoice turns are not merged into a durable draft | Reuse `InvoiceWriter`, share approval/calculation rules and reject stale previews; extend `AccountOperations.php`, `AskAccountContext.php`, `AIController.php` |
| Invoice sharing | Owner create/revoke/email; expiring/revocable share tokens; guest invoice view | Limited invoice information | AI cannot retrieve sharing metadata; stored hashes cannot reconstruct original public URLs | Return existing sharing metadata and an owner invoice-management link. Never fabricate share URLs or send links automatically |
| Payments/PayPal | Guest and owner order creation/capture; verified amount/currency/reference checks; idempotency; invalid-order recovery | Account-scoped payment lists and completed revenue | Capture submits an empty JSON array instead of an object; logs show `400 MALFORMED_REQUEST_JSON`; processing attempts are not reconciled; status cells display aggregate group names; failure notices concatenate fragments without spaces | Correct capture serialization, safe reconciliation, status labels and whole-sentence notices; retain uncertain outcomes: `PaymentController.php`, `PayPalClient.php`, `PayPalGatewayException.php`, `pages/Payments/Index.vue` |
| Reports/analytics | Account-scoped invoice/customer summaries and completed payment totals per currency | Verified revenue and summary queries | Targeted customer/invoice payment history and processing status filtering missing | Extend existing queries; retain supported currencies and verified balances. No invented expenses/profit data: `ReportsController.php`, `AskAccountContext.php` |
| Business/settings | Own profile, preferred currency, language, appearance and security settings | Explanations only | No need to expose account/security writes as AI tools | Preserve existing settings routes, forms and Light/Dark/System behavior |
| Ask MtaaFlow/providers | Groq primary, Gemini fallback; general advice, calculations/conversions, account reads and confirmed actions | Existing prompt-generated JSON intent envelopes | No native tools; malformed tool-like output can become unreadable answers; no bounded structured-generation repair | Allowlisted native tools, typed envelope validation and at most one generation-only repair before any backend action: `AskAssistant.php`, `GroqProvider.php`, `GeminiProvider.php`, `ProviderManager.php` |
| Conversation context/history | Vue keeps the active message list; server session keeps at most five threads for 30 minutes and bounded provider context | Server-owned follow-up context; clarification merge for account actions | New Conversation clears the visible thread; there is no saved-thread API/UI or conversation/message table. Session expiry/logout removes server context; no cross-device history | Add minimal owned conversation/message persistence, context limits, history/rename/confirmed deletion, and conversation-bound pending confirmations: `AskConversation.php`, `AIController.php`, new models/controller/migrations, chat page/composable |
| Localization | English, French, German and Spanish across existing application-owned pages; persisted language preference | Locale-aware provider responses and verified summaries | New tools/history/errors/notifications must use the existing catalogs; fragment interpolation can lose spaces | Add complete translated messages and new history labels in all four languages; retain existing preference and formatting architecture |

## Safety and implementation boundaries

- All business selectors resolve against the authenticated user's relationships. Ambiguous singular matches require explicit selection.
- Business writes keep `PendingAction` preview, expiry, explicit confirmation and idempotent execution. Reopening a conversation never executes an action.
- Invoice totals use existing currency precision and integer calculations. Paid status requires verified completed payments.
- Public checkout remains authorized by a valid share token. Freelancer-only routes remain authenticated and scoped.
- PayPal diagnosis retrieves existing order state without capturing existing orders. Uncertain outcomes remain nonterminal; previous attempts remain in history.
- No product pricing schema, dependencies, destructive migrations, commits, pushes, merges or deployments are authorized by this implementation.
- Conversation persistence requires additive tables and nullable links to existing pending actions; existing business records remain intact.

## Phase results

| Phase | Result |
| --- | --- |
| 1. Audit | Completed from local code, installed versions, tests, schema and safe PayPal diagnostics. Baselines: workflow 34 passed/160 assertions; payments 80 passed/656 assertions; account/customer/inventory/language 69 passed/9 skipped/399 assertions; frontend 29 passed. |
| 2. Provider tools and parsing | In progress. |
| 3. Supported business workflows | Planned after phase 2 checks. |
| 4. Multi-step context and confirmations | Planned after phase 3 checks. |
| 5. Durable conversation history | Planned after phase 4 checks. |
| 6. PayPal capture/reconciliation/retries | Diagnosis in progress; implementation follows phase 5. |
| 7. Financial synchronization and notifications | Planned. |
| 8. Four-language verification | Planned. |
| 9. Final tests and frontend build | Planned. |
