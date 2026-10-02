# Loans in net worth

Status: **built**. `php artisan loans:tag --apply` tags the three loans below on any copy
of the ledger, finding them by description rather than by id. `--untag --apply` removes
every tag. What each tag means is in `App\Support\Loans`.

## Why

A loan's drawdown lands in a cash account as a deposit, so net worth counts the cash
and never the debt. Borrowing 270,000 reads as 270,000 richer until it is paid back,
and the repayments then read as spending, so the same money is wrong twice.

## Decisions

- **Tag the existing rows, no loan account.** The drawdown and each repayment carry
  the loan's name in the meta bag. Net worth subtracts what is still owed on each day.
  The tags are server-owned, so they go in `SERVER_LINKS` and FormContractTest's
  `SERVER_ONLY`. What each key means is in `App\Support\Loans`.
- **The interest is owed with the principal.** The drawdown carries the whole loan's
  interest, owed from the day the money is borrowed, and every repayment row, principal
  or interest, pays the loan down by its full amount. The loan is therefore what is left
  to pay. Net worth takes the interest all at once on the day of borrowing, rather than
  month by month as it is paid. Every loan ends at exactly 0.
- **A card instalment is repaid on its statement's due date**, when the money leaves
  the cash, not on the day it is charged.
- **Loans in scope:** HSBC TAX LOAN, HSBC LOAN and the 2016 card loan
  (MASTER INSTALMENT LOAN).

## The three loans

| Loan | Drawdown | Repayments | Principal | Interest |
|---|---|---|---|---|
| HSBC TAX LOAN | 2019-01-29, SAVING +270,000 | 24 × 11,574 withdrawals from SAVING, to 2021-01-30 | 270,000 | 7,776 (324 inside each payment) |
| HSBC LOAN | 2019-10-10, ADVANCE +168,000 | 60 × (2,800 + 336) MASTER charges "INSTALMENT n OF 60", to 2024-09-10 | 168,000 | 20,160 |
| MASTER INSTALMENT LOAN | not recorded, about 2016-06 | 31 × (6,250 + 360) MASTER charges "INSTALMENT n OF 36", 2016-12-06 to 2019-06-06 | 225,000 | 12,960 |

## The 2016 card loan: incomplete

The drawdown and instalments 1–5 predate the records: MASTER's records begin at
2016-12-06, and the cash accounts' at 2017-01. The first recorded instalment therefore
carries `loan_borrowed` 225,000, `loan_interest` 12,960 and `loan_repaid_before` 33,050
(5 × 6,610, from its "6 OF 36"). It starts at 204,910 owed and reaches 0 when the last
instalment falls due in July 2019, with no transactions made up.
