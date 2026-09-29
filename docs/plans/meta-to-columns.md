# Plan: move queried meta keys out of the JSON bag

Status: **parked**, to revisit. Not started.

## Why

The polymorphic `meta` table (JSON, keyed by `model_type` + `model_id`) now holds keys
that are grouped on, filtered on, joined through or deleted by. AGENTS.md's own rule is
that such a key belongs in a column. What the bag costs today:

- **No foreign keys.** `paired_transaction_id` can point at a deleted row, and
  `destroy()` deletes whatever it names. The same goes for `settled_by` and
  `settlement_account_id`.
- **No types.** Quantity and price are strings in JSON; only app validation stops
  `"abc"`.
- **No plain indexes.** `CardStatement` groups by `JSON_EXTRACT(meta, '$.due_date')`
  across every card row, and the symbol filter runs `DISTINCT JSON_EXTRACT(...)` over
  every meta row.
- **An extra join.** Every trade or charge read loads the meta row too.
- **Guard code that exists only because the bag accepts anything:** `keepLinksOf()`,
  dropping `SERVER_LINKS` from the payload, and the `no_cash === true` check.

A nullable column costs about one bit per row in InnoDB, so sparse columns are not the
concern. The split below is by kind of field, not by whether it can be null.

## Proposal

### 1. Links and statement keys become nullable columns on `transactions`

| Key | Column | Notes |
|---|---|---|
| `due_date` | `date`, nullable | index `(account_id, due_date)` for `CardStatement` |
| `paired_transaction_id` | self-FK, nullable | used by the trade and card-settlement pairs |
| `settled_by` | self-FK, nullable, `nullOnDelete` | |
| `card_amount` | `decimal(12,4)`, nullable | summed into statements |

On `accounts`, `settlement_account_id` becomes a nullable FK column.

### 2. A `trades` child table (1:1 with a transaction)

`trades(transaction_id PK/FK, symbol, quantity decimal(20,8), unit_price decimal(12,4),
fees decimal(12,4) nullable, no_cash bool)`, with an index on `symbol`.

- Buys and sells have every figure. A dividend has only `symbol`, or the symbol could
  sit on `transactions` as a nullable column instead.
- `Positions`, `heldSymbols`, the symbol filter and `prices:fetch` then query it
  directly.

### 2a. A dividend lives on the bank, not the brokerage

**Done** on the `bank-dividend` branch, on the current meta bag: `brokerage_account_id` is a
meta key for now, and becomes a column if the rest of this plan goes ahead.

Decided 2026-09-29. A dividend stops being a brokerage row paired with a bank deposit.
It becomes a single `dividend` row on the bank account, tagged with the symbol that
paid it.

- **Type:** `dividend` becomes legal on `cash` rather than `security`, and moves the
  balance +1 like a deposit. It needs no cash side, so `needsCashSide()` goes back to
  the trades only.
- **Symbol:** required on a dividend, in the same place a trade's symbol is stored (a
  `trades` row with only `symbol`, or a nullable `transactions.symbol`).
- **Brokerage tag:** the dividend also names the brokerage whose holding paid it, in a
  new `brokerage_account_id`, stored as a nullable FK column once the columns land.
  The positions page's Dividends figure sums the `dividend` rows tagged with that
  brokerage, so there is no guessing, even when one bank settles several brokerages or
  two of them hold the same symbol.
- **Form:** on a bank dividend, pick the brokerage first from those that settle into
  this bank. If only one does, it is pre-selected. Then pick the symbol from that
  brokerage's holdings.
- **Validation:**
  - the brokerage is required, and must be a security account settling into this bank;
  - so it shares the bank's currency, since `guardSettledFrom()` already holds a
    settlement to one currency;
  - the symbol is required.
- **Deleting a brokerage** with tagged dividends is refused, the same way as an account
  with transactions, and it becomes one more line in the delete hint.
- **Removed:**
  - TradeCash's dividend branch;
  - the `dividend` pair kind in `linkedCounterparts` and the delete dialog;
  - the "Dividend SYMBOL [Broker]" cash description;
  - the dividend half of the cash-side locks.
- **Lost:** a dividend with "No cash side", for money paid outside the tracked banks,
  has nowhere to live. Accepted: record it on the bank anyway, or not at all.
- **Dev data:** the app is still in dev, so update `DevTransactionSeeder` and refresh.
  There is no migration.

### 3. Card terms as columns

`statement_day` and `term_days` become nullable columns on `accounts`, or a small
`card_terms` table. The database can then enforce ranges such as 1–31.

### 4. Anything display-only stays JSON

Use a plain `json` column on `transactions` rather than the polymorphic table. After
1–3 nothing is display-only, so it starts empty. Drop `meta` once it is unused.

### Alternative if the JSON is kept

MySQL 8 (the dev server runs 8.0.46) can index a generated column over a JSON path, for
example `due_date` as `meta->>'$.due_date'`. That fixes indexing only: it gives no FKs
and no types, and it needs the JSON on `transactions` itself, not in the polymorphic
table.

## Cost, and whether it is worth it

Assessed 2026-09-29: about 270 references to these keys across roughly 20 files in
`app` and `resources/js`, plus 36 test files. The business rules do not change, but
every read and write of these keys does.

- **Not worth it for performance.** A single-user app at thousands of rows does not feel
  JSON scans.
- **Integrity is mostly covered already** by the DTO guards and the test suite. The real
  gap is `destroy()` deleting whatever `paired_transaction_id` points at.
- **Cheaper route if done:** keep `meta_data` as the wire and DTO shape and change only
  where it is saved. The model maps `meta_data.due_date` to a column, and so on. The Vue
  forms, `FormContractTest` and most tests then stay as they are, and the work is the
  model, the queries (`CardStatement`, `Positions`, the symbol filter) and the seeders.
- **Smallest useful slice:** `paired_transaction_id` and `settled_by` as self-FK columns.
  That is 29 references in about 7 files, and it closes the integrity gap on its own.

## Order

Three PRs, 1 then 2 then 3. The app is still in dev, so there is no backfill: change the
migrations, update `Dev*Seeder`, and refresh the DB.

Code to touch for 1:

- `CardStatement` and `CardStatementCycle`
- `TradeCash` and `CashFlow`
- `TransactionData`: `keepLinksOf`, `SERVER_LINKS`, `figureLock`, `guardPeriodCanMove`
- `TransactionMetaData` fields
- `TransactionController`: `settle`, `destroy`, the unpaid filter, `linkedCounterparts`
- `Account::settlementAccount`, and `AccountController`'s delete refusals
- `FormContractTest`'s `SERVER_ONLY` list

## Smaller follow-ups noted at the same time

- **Categories** have no kind (income or expense), which is why a deposit can't require
  a category. Consider a `kind` column.
- **`ccy`** is `string` everywhere but `char(3)` on `prices`. Make them all `char(3)`.
- **Unused Laravel defaults:** `users`, `password_reset_tokens` and
  `personal_access_tokens`. Keep them only if login is planned.
- **`recurring_transactions` and `transaction_templates`** are two shapes for "a
  transaction not yet recorded" (columns versus a JSON payload). Pick one.
