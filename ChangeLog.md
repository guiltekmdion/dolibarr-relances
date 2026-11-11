# CHANGELOG MODULE DUNNING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.1.0

- Ready to use: 3 email templates in French and English and 3 default levels created at the first activation.
- Late penalties and fixed recovery indemnity between companies (France, article L441-10 of the Commercial Code): settings, amounts in the emails (`__DUNNING_PENALTIES__`, `__DUNNING_INDEMNITY__`, `__DUNNING_TOTAL_WITH_PENALTIES__`, `__DUNNING_PENALTIES_TEXT__`) and on the tab of the invoice, button to prepare a separate draft penalty invoice.
- The `__DUNNING_*__` keys in the email templates editor and in the email form of an invoice.
- PHP 8.1 or later, strict types. Tests run by the CI on Dolibarr 24.0.3 with PHP 8.1 and 8.3.

## 1.0.0

Initial version: reminder levels, list of the customers to remind with its mass actions, daily scheduled job, tab of the invoice, payment promise, customers never reminded, one email per customer, levels sent by hand, line of the home page.
