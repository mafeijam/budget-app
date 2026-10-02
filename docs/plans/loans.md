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
  the loan's name and a role (`drawdown` / `repayment`) in the meta bag. Net worth
  subtracts what is still owed on each day: drawdowns less the principal repaid so far.
  The tags are server-owned, so they go in `SERVER_LINKS` and FormContractTest's
  `SERVER_ONLY`.
- **A repayment counts only its principal toward the loan; the interest stays
  spending.** Every loan must end at exactly 0, not below it.
- **Loans in scope:** HSBC TAX LOAN, HSBC LOAN and the 2016 card loan
  (MASTER INSTALMENT LOAN).

## The two loans

| Loan | Drawdown | Repayments | Principal per payment | Interest per payment |
|---|---|---|---|---|
| HSBC TAX LOAN | id 256, 2019-01-29, SAVING +270,000 | 24 × 11,574 withdrawals from SAVING, to 2021-01-30 | 11,250 | 324 |
| HSBC LOAN | id 345, 2019-10-10, ADVANCE +168,000 | 60 × 2,800 MASTER charges "INSTALMENT n OF 60", to 2024-09-10 | 2,800 | — |

- **TAX LOAN:** the interest is folded into the flat 11,574, so each repayment row
  records a `principal` of 11,250 and the remaining 324 counts as spending.
  24 × 11,250 = 270,000.
- **HSBC LOAN:** the interest is already its own 60 charges of 336, which stay
  untagged spending, and the 2,800 instalments repay the whole 168,000.

## The 2016 card loan: incomplete

36 MASTER instalments of 6,250 principal + 360 interest, so 225,000 borrowed. Only
instalments 6–36 are recorded (2016-12-06 to 2019-06-06, 62 rows), because MASTER's
records begin at 2016-12-06 and the cash accounts' at 2017-01. Instalment 1 fell on
2016-07-06 and the drawdown was about 2016-06. Neither the drawdown nor instalments
1–5 exist.

Decided: the first recorded instalment carries `loan_borrowed` 225,000 and
`loan_repaid_before` 31,250 (5 × 6,250, from its "6 OF 36"). It therefore starts at
193,750 owed and reaches 0 on 2019-06-06, with no transactions made up.
