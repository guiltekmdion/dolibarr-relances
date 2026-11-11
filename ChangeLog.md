# CHANGELOG MODULE DUNNING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.5.0

- Aged balance in the list of the customers (not due, 1-30, 31-60, 61-90 and over 90 days late) of the invoices in the reminder circuit, with a total line.
- Statement of account: a PDF of the customer with all its invoices to pay as a whole (disputed, excluded customers and direct debits included) and their age, from its Documents tab or attached by a level (setup: PDF attached = formal notice or statement).
- Direct debits: no reminder while the request waits or the order is not credited (with a payment schedule: the request of the installment reminded, not the one of a later installment), no level before the due date for an invoice or an installment paid by direct debit; after a rejection the reminders go on.
- With the module Payment schedules (1.1.0 or later, technical name `installments`): an installment is known by its due date, its levels are kept when the schedule is saved again; with the module disabled, all the levels sent count; schedules that can not be read leave the invoices reminded as a whole.
- Once the penalty invoice is created, the reminders of the invoice no longer ask for the penalties, and a penalty invoice owes none.
- Public email templates only; the email templates of the module are created once (a template deleted by the user after the first activation of 1.5.0 does not come back).
- Checked with phpcs, phpstan and phan with the setup of Dolibarr, by `build/check.sh` in the CI too; tests on MySQL and PostgreSQL, Dolibarr 24.0 and develop.

## 1.4.0

- With the module [Payment schedules](https://github.com/guiltekmdion/dolibarr-echeances) enabled, an invoice with a schedule of expected payments is reminded installment by installment: the oldest unpaid installment and the amount due, the levels kept by installment (a paid installment lets the next one start again from the first level), late penalties counted from the date of each installment, the indemnity still once per invoice.

## 1.3.2

- A customer without language gets the email template chosen for the level, in its language, whatever the language of the scheduled job.
- The `__DUNNING_*__` keys are empty in the email forms other than the one of a reminded invoice (no description text in an email).
- A penalty invoice deleted (a draft) can be created again.
- README: disabling and enabling the module after an update is required.

## 1.3.1

- Statistics: a sales representative without the right to see all customers only counts its customers, like the list; never for an external user.

## 1.3.0

- Statistics of the reminders: by month and by level, amount paid within 30 days after a reminder, average days from the first reminder to the full payment.
- Invoices in a foreign currency: amounts of the emails and of the formal notice in the currency of the invoice, one email per customer and currency, penalties converted at the rate of the invoice.
- Faster list and line of the home page: 306 ms instead of 732 ms with 10,000 more unpaid invoices.

## 1.2.0

- Payment promise with an amount, shown kept, pending or not kept according to the payments received since the promise.
- Formal notice to pay (PDF), a document model of the thirdparties: the late invoices of the customer, the penalties due and the bank details. A level can attach it to its email; the default level "Formal notice" does.

## 1.1.0

- Ready to use: 3 email templates in French and English and 3 default levels created at the first activation.
- Late penalties and fixed recovery indemnity between companies (France, article L441-10 of the Commercial Code): settings, amounts in the emails (`__DUNNING_PENALTIES__`, `__DUNNING_INDEMNITY__`, `__DUNNING_TOTAL_WITH_PENALTIES__`, `__DUNNING_PENALTIES_TEXT__`) and on the tab of the invoice, button to prepare a separate draft penalty invoice.
- The `__DUNNING_*__` keys in the email templates editor and in the email form of an invoice.
- PHP 8.1 or later, strict types. Tests run by the CI on Dolibarr 24.0.3 with PHP 8.1 and 8.3.

## 1.0.0

Initial version: reminder levels, list of the customers to remind with its mass actions, daily scheduled job, tab of the invoice, payment promise, customers never reminded, one email per customer, levels sent by hand, line of the home page.
