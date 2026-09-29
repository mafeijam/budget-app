# Plan: stock splits and consolidations

Status: **parked**, to revisit. Not started. Nothing held has split inside its trade
history yet (NVDA's 10-for-1 was June 2024, before any seeded trade), so nothing is
wrong today. The first real split of a held symbol makes it urgent.

## Why

Positions are replayed from buys and sells alone. A split changes the quantity held
without a trade, so after one:

- **The quantity is wrong.** 100 shares held become 1,000 at the broker and stay 100
  here, so today's market value is a tenth of the real one.
- **A sell is refused.** Selling post-split shares trips the shortfall check, since the
  replay never saw them arrive.
- **Past values are wrong, silently.** Yahoo's chart closes are split-adjusted: after a
  1-for-10 split every earlier close comes back divided by ten. The look-back day picker
  and the net worth history multiply the quantity held *then* by that adjusted close,
  and read a tenth of what was held.
- **The stored prices disagree with each other.** Closes fetched before the split are
  raw; any re-fetched after it (the seven-day overlap, or `--history`) are adjusted.
  Nothing marks which is which.

## Proposal

### 1. A `split` transaction type on the brokerage

A row on the security account with `symbol` and `ratio` in the meta bag, dated the
effective day. A 1-for-10 split is ratio `10`; a 10-to-1 consolidation is `0.1`.

- Moves no cash: `movesBalanceOn()` is 0 everywhere, it has no bank side and no
  `TradeCash` pairing. `accountTypes()` is Security only, `carriesSymbol()` true.
- No amount. `amount` is a positive magnitude and today NOT NULL, so either it is stored
  as 0 or the column's rule is relaxed for this type; decide when building. The DTO's
  `rules()` holds `ratio` > 0.
- `Positions::replay()` multiplies the quantity by the ratio and leaves the total cost
  alone, so the average cost divides by it. 100 at 40 becomes 1,000 at 4, still 4,000,
  and unrealised P&L does not move on the day, which is right.
- `tradesOf()` returns splits with the trades, so the shortfall check, the net worth
  history and the look-back all see them in date order.
- Cash in lieu of a fraction left by a consolidation is an ordinary sell of that
  fraction, so realised gain and the bank stay right.
- Form: a ratio control, shown for this type only. `FormContractTest` needs it, and the
  type picker's `filterOrder()` needs a place for it.

Rejected: rewriting past trades to post-split quantities. It destroys the record, and a
trade would no longer match the broker's contract note.

### 2. Store raw closes, not split-adjusted ones

Fetch with `events=split` and multiply each close by the ratios of every split after
its day, so each stored close is what the share actually traded at. Then
`quantity held that day × close on that day` is right before and after a split, which
is what `Positions::valued($broker, $on)` and `NetWorth::on()` already compute.

- `--history` re-fetches whole ranges, so one run after this lands repairs every close
  stored adjusted.
- A manual price is left alone as now: it was typed as the real price.
- Test: fake a chart response with a split event and assert the pre-split closes are
  stored multiplied back.

### 3. Later, optional: flag an unrecorded split

The fetch already receives Yahoo's split events. A held symbol with a split event and no
matching `split` row could raise a note on the Positions page: "0700.HK split 1:5 on …,
not recorded". A note rather than a row written automatically, so what is recorded stays
the user's decision.

## Out of scope

Ticker changes and mergers (one symbol becomes another, perhaps at a ratio). They could
extend the `split` type with an optional new symbol, but are rarer and not needed yet.
