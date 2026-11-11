# Reminders for Dolibarr: unpaid customer invoices, by levels

Free module made by [Guiltek](https://www.guiltek.fr), GPL v3 or later. Version française : [README-fr.md](README-fr.md).

The module sends reminder emails for the unpaid customer invoices, level after level (for example 3 days, 15 days and 30 days after the due date), from a list of the customers to remind or by a daily scheduled job. The agenda events of the invoices are its memory: each reminder sent and each payment promise is an event, visible on the invoice.

## Features

- **Levels**: days after the due date (negative = before), label, recipient (a contact type of the invoice, else the email of the customer), email template, active or not.
  - **One email per customer**: the due invoices of a customer at this level go in one email, with the table of the invoices and their PDF.
  - **By hand**: the job never sends this level (a last notice before legal action, for example): it waits in the list until a user sends it.
- **List Billing > Reminders**: one line per customer (invoices, rest to pay, over 30 days late, level reached, promise, next reminder), filters on the level, the sales representative, the amount and the customers to remind. Mass actions: send the due level, send one email per customer, payment promise, do not remind.
- **Tab Reminders on the invoice**: level reached, next reminder, recipients, history, buttons to send the due level, record a payment promise, or open a dispute (a disputed invoice is never reminded).
- **Payment promise**: no reminder up to the promised day, then the next level.
- **Customers never reminded**: a tag/category chosen in the setup (needs the module Tags/Categories).
- **Home page**: a line "Customers to remind" in the Invoices block, which opens the list filtered on them.

## Rules

- The next level is the first active level after the most advanced level sent: never a jump back, never two levels the same day, and the gap between two levels is kept.
- Reminded: validated unpaid invoices (standard, replacement, deposit, situation), except credit notes and invoices with a dispute open. A retained warranty is reminded from its own date.
- **First run of the job**: it sends nothing for the levels reached before it. They wait in the list for a user.
- The email is sent first, then one event per invoice. Each email reads the events again just before it is sent, so a level is never sent twice.
- The job does not run while the reminder job of Dolibarr (`sendEmailsRemindersOnInvoiceDueDate`) is enabled, so customers are not reminded twice.

## Requirements

Dolibarr 24.0 or later, with the modules Customer invoices, Scheduled jobs and Agenda. PHP 7.2 or later. MySQL/MariaDB or PostgreSQL.

## Install

1. Download `module_dunning-X.Y.Z.zip` from the [releases](https://github.com/guiltekmdion/dolibarr-relances/releases).
2. In Dolibarr: Home > Setup > Modules > Deploy/install external app/module, then send the zip. Or unzip it in `htdocs/custom` (the folder `dunning` must be at `htdocs/custom/dunning`).
3. Enable the module **Reminders** (in Financial Modules), then give the rights to the users.

## Setup

1. Create the email templates of the levels (Home > Setup > Emails > Email templates, type **Customer invoices**). A template with the same label in another language is used for the customers of this language. Keys for the templates:
   - `__DUNNING_LEVEL__`: name of the level
   - `__DUNNING_DAYS_LATE__`: days late of the oldest invoice
   - `__DUNNING_INVOICES__`: table of the invoices of the email, with payment links
   - `__DUNNING_TOTAL_REMAIN__`: total remaining unpaid
   - the usual keys (`__REF__`, `__ONLINE_PAYMENT_URL__`...) work too, but in an email with several invoices only the `__DUNNING_*__` keys describe all of them.
2. Setup of the module: one line per level, and the tag of the customers never reminded.
3. To send automatically: enable the job "Send reminders of unpaid customer invoices" in Home > Admin Tools > Scheduled jobs, and keep the reminder job of Dolibarr disabled.

Rights: "Read reminders" (also needs the right to read invoices), "Send reminders, record payment promises" (with the advanced permissions, also needs the right to send invoices by email). The levels are set by an administrator.

Before the first sending, check the list: a mass action sends real emails to your customers.

## Support

Questions and bugs: [issues](https://github.com/guiltekmdion/dolibarr-relances/issues). Custom development: [Guiltek](https://www.guiltek.fr).

## License

Copyright (C) 2026 Maxime DION, Guiltek.
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [COPYING](COPYING).
