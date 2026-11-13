# Reminders for Dolibarr: unpaid customer invoices, by levels

Free module made by [Guiltek](https://www.guiltek.fr), GPL v3 or later. Version française : [README-fr.md](README-fr.md).

The module sends reminder emails for the unpaid customer invoices, level after level (for example 3 days, 15 days and 30 days after the due date), from a list of the customers to remind or by a daily scheduled job. The agenda events of the invoices are its memory: each reminder sent and each payment promise is an event, visible on the invoice.

## Features

- **Levels**: days after the due date (negative = before), label, recipient (a contact type of the invoice, else the email of the customer), email template, active or not.
  - **One email per customer**: the due invoices of a customer at this level go in one email, with the table of the invoices and their PDF.
  - **By hand**: the job never sends this level (a last notice before legal action, for example): it waits in the list until a user sends it.
- **List Billing > Reminders**: the work to do, one line per customer with invoices in the reminder circuit (invoices, amount reminded, by days late: 1-30, 31-60, 61-90 and over 90, with a total line; level reached, promise, next reminder), filters on the level, the sales representative, the amount and the customers to remind. It is not an aged balance of all the invoices to pay: disputed invoices, excluded customers, direct debits in progress and invoices before their first level are not in it. A customer with discounts or credit notes not used yet has a warning. Mass actions: send the due level (never a level set to be sent by hand, such as the formal notice; the confirmation gives the number of invoices and their total), send one email per customer, payment promise (on all the invoices of the customer), exclude the customer from the reminders (with an event on the customer).
- **Tab Reminders on the invoice**: level reached, next reminder (automatic, by hand, reached before the first run of the job, or job disabled), recipients, history, buttons to send the due level (the confirmation names the other invoices of a grouped email), record a payment promise, or open a dispute (a disputed invoice is never reminded). An invoice not reminded gives the reason (paid, dispute, customer excluded, direct debit request waiting since N days, no level) and a link to change it.
- **Payment promise**: no reminder up to the promised day, then the next level. With an amount (tab of the invoice), the promise is shown kept, pending or not kept according to the payments received since.
- **Formal notice (PDF)**: a document model of the thirdparties (tab Documents of the customer, to print or send by registered mail): the late invoices of the customer, the penalties due and the bank details. A level can attach it to its email: it then asks for the invoices and the total of the email. "Despite our previous reminders" only after a reminder.
- **Statement of account (PDF)**: all the invoices to pay of the customer as a whole, due or not (disputed and by direct debit too), and their aged balance (with a payment schedule, each installment at its own age); from the tab Documents of the customer, or attached by a level instead of the formal notice. An invoice with a direct debit in progress is marked, and the bank details are left out when all of them are.
- **Credits of the customer**: the formal notice and the statement give the discounts and the credit notes not used yet.
- **Customers never reminded**: a tag/category chosen in the setup (needs the module Tags/Categories).
- **Home page**: a line "Customers to remind" in the Invoices block, which opens the list filtered on them.
- **Ready to use**: at the first activation, 3 email templates in French and English (courtesy, reminder, formal notice) and 3 levels (3 days before the due date, 15 days after with one email per customer, 30 days after by hand).
- **Late penalties and recovery indemnity** (France, between companies): annual rate and fixed indemnity in the setup, computed for each late invoice, shown in the emails and on the tab of the invoice; a button prepares a separate draft invoice of them.
- **Invoices paid in several times**: with the free module [Payment schedules](https://github.com/guiltekmdion/dolibarr-echeances), an invoice with a schedule is reminded installment by installment (the oldest unpaid one, for the amount due), the levels start again from the first one at each new installment, the penalties run from the date of each installment. Payment schedules 1.1.0 or later (technical name `installments`).
- **Direct debits**: no reminder while the direct debit request waits or its order is not credited (with a payment schedule, the request of the installment reminded); no level before the due date for an invoice or an installment paid by direct debit (the bank takes the money that day). After a rejection, the reminders go on.
- **Online payment links**: the references of the invoices in the emails link to the online payment page only when an online payment module is enabled and the security key of the payments is set (`PAYMENT_SECURITY_TOKEN`); then a sentence under the table says it.
- **Statistics** (Billing > Reminders > Statistics): reminders sent and invoices reminded by month and by level, amount paid within 30 days after a reminder, average days from the first reminder to the full payment.
- **Foreign currencies**: the emails and the formal notice show the amounts in the currency of each invoice (payments in this currency), one email per customer and currency; the list adds the amounts in the currency of the company.
- **Keys in the email templates editor**: the `__DUNNING_*__` keys appear in the list of keys of the templates editor, and get their values when an invoice is sent by hand from its card.

## Rules

- The next level is the first active level after the most advanced level sent: never a jump back, never two levels the same day, and the gap between two levels is kept.
- Reminded: validated unpaid invoices (standard, replacement, deposit, situation), except credit notes, invoices with a dispute open and invoices whose direct debit is in progress. A retained warranty is reminded from its own date.
- Once the penalty invoice is created, the reminders of the invoice no longer ask for the penalties; a penalty invoice owes none.
- **First run of the job**: it sends nothing for the levels reached before it. They wait in the list for a user.
- The email is sent first, then one event per invoice, each on its own. Each email reads the events again just before it is sent, so a level is never sent twice.
- One email per customer and level also means one per set of recipients: the contacts of two invoices each get their own invoice.
- Nothing is sent from the module, by the job or by hand, while the reminder job of Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) is enabled, so customers are not reminded twice. A banner says it on the list and on the tab.
- With a payment schedule, an installment never asks for more than the rest to pay of the invoice. If the schedules can not be read, nothing is reminded and the error is shown (an invoice is never reminded as a whole by mistake).
- An email sent without the PDF of one of its invoices: a warning on the page, an error of the job. A customer without email: a warning, not an error of the job every day.

## Email templates

The templates of the module have their own type, **Reminders (module Reminders)**: the reminder jobs of Dolibarr, which take the templates of the type Customer invoices, never send them, and they are no longer in the email form of an invoice. The module only sends its own templates (column `module` = `dunning`): those it created, and those an administrator adopted in the setup of the module. A template created by another user, even with the same label, is never sent: nobody else can change the text that leaves to the customers. To add a language or your own template: create it in Home > Setup > Emails > Email templates with the type Reminders, then click **Adopt** in the setup of the module; the setup lists the templates, their language and the levels that use them.

## Known limits

- The memory of the reminders is the agenda: a user who can delete the events of the agenda can make a reminder go again. Keep this right to the people who need it.
- No lock against two runs at the same time (the job and a user, the same second): the same level could go twice.
- The formal notice and the statement are stored in the Documents tab of the customer: whoever can read the thirdparties can read them.
- The online payment link is for the whole invoice, not for the installment reminded.
- With a payment schedule, the penalty invoice is made once per invoice: once made, the penalties of the later installments are no longer asked for.

## Requirements

Dolibarr 24.0 or later, with the modules Customer invoices, Scheduled jobs and Agenda. PHP 8.1 or later. MySQL/MariaDB or PostgreSQL.

## Install

1. Download `module_dunning-X.Y.Z.zip` from the [releases](https://github.com/guiltekmdion/dolibarr-relances/releases).
2. In Dolibarr: Home > Setup > Modules > Deploy/install external app/module, then send the zip. Or unzip it in `htdocs/custom` (the folder `dunning` must be at `htdocs/custom/dunning`).
3. Enable the module **Reminders** (in Financial Modules), then give the rights to the users.

After an update of the module, disable it and enable it again: **required**, the pages of the module fail until then. The new columns, templates and settings are added; your levels, templates and the date of the first run of the job are kept.

From 1.5 to 1.6: the activation gives the templates of the module their own type, and replaces the texts of the templates "1 Courtesy" and "3 Formal notice" by the new ones only if you did not change them. Between the update of the files and the activation, the reminders keep their templates. A template of Relances chosen in a reminder job of Dolibarr is no longer found by this job: give it one of your own templates. A level set on a template that is not of the module (a template of the invoices, for example) gets a copy of it, named "… (Relances N)", which becomes a template of the module; the original template is not changed.

## Setup

1. Check the email templates "Relances - 1, 2, 3" (Home > Setup > Emails > Email templates, type **Reminders**), or create your own and adopt them (see Email templates). A template of the module with the same label in another language is used for the customers of this language. Keys for the templates:
   - `__DUNNING_LEVEL__`: name of the level
   - `__DUNNING_DAYS_LATE__`: days late of the oldest invoice
   - `__DUNNING_INVOICES__`: table of the invoices of the email, with payment links when online payment is set up
   - `__DUNNING_TOTAL_REMAIN__`: total remaining unpaid
   - `__DUNNING_PENALTIES__`, `__DUNNING_INDEMNITY__`, `__DUNNING_TOTAL_WITH_PENALTIES__`: late penalties, recovery indemnity and total with them
   - `__DUNNING_PENALTIES_TEXT__`: a sentence quoting the penalties and the indemnity due, empty when nothing is due
   - the usual keys (`__REF__`, `__ONLINE_PAYMENT_URL__`...) work too, but in an email with several invoices only the `__DUNNING_*__` keys describe all of them.
2. Setup of the module: one line per level (with "Formal notice" to attach the PDF to its email), and the tag of the customers never reminded.
3. Late penalties (optional): the annual rate, the fixed recovery indemnity (once per late invoice still unpaid), and a tag of the customers who never owe them. Only the customers recognized as companies by Dolibarr (VAT number or professional id) owe penalties. They are shown, never added to the invoice: the button of the tab prepares a separate draft invoice to check and validate.
4. To send automatically: enable the job "Send reminders of unpaid customer invoices" in Home > Admin Tools > Scheduled jobs, and keep the reminder job of Dolibarr disabled.

Rights: "Read reminders" (also needs the right to read invoices), "Send reminders, record payment promises" (with the advanced permissions, also needs the right to send invoices by email). The levels are set by an administrator.

Before the first sending, check the list: a mass action sends real emails to your customers.

## Support

Questions and bugs: [issues](https://github.com/guiltekmdion/dolibarr-relances/issues). Custom development: [Guiltek](https://www.guiltek.fr).

## License

Copyright (C) 2026 Maxime DION, Guiltek.
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [COPYING](COPYING).
