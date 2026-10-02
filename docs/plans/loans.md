# Plan: loans in net worth

Status: **parked**, design agreed, not started. Nothing is tagged in the data yet.

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
- **Loans in scope:** HSBC TAX LOAN and HSBC LOAN. The older 36-instalment card loan
  (6,250 + 360 on MASTER, to 2019-06) is left out: its drawdown predates the records.

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

## Pieces

- `loans:tag` command: dry run by default, `--apply` to write, `--untag` to undo.
  Takes the drawdown id, the repayment ids or a description pattern, and an optional
  `--principal` per repayment.
- `App\Support\Loans`: `owedOn($day)` for net worth and its history, and a summary
  per loan for display.
- `NetWorth`: subtract `owedOn()`. A card instalment is card debt, which net worth
  leaves out by design (see its docblock), so the HSBC LOAN's drawdown has to be
  offset there carefully. Check that the debt is not counted twice once the
  instalments land on the card.
- A repayment shows which loan it paid and what is left, on the transaction row.
