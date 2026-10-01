# How the Forecast's typical figures are calculated

The Forecast draws two lines. The **known** line is what is on file: balances, pending
rows, recurring rules, and card statements. The **typical** line is the known line plus an
estimate of everything that is not on file yet: spending and income in the days ahead that
nobody has entered. This document is about that estimate.

Everything is in `app/Support/Forecast.php`. The figures quoted below are the development
data as at **1 October 2026** and are there to show the arithmetic; they will drift.

## The shape of it

```
typical balance = known balance
                  - cash spending, a day's worth for every day ahead
                  - irregular spending, a day's worth for every day ahead
                  - card spending, on each card's due dates
                  + typical income, a day's worth for every day ahead
                  + dividends, bonuses and double pay, on their dates
```

Three things decide every figure:

1. **The window is the 12 complete months.** The month we are in is never used. On the
   first it is one day, and as one of twelve it reads as a month with nothing in it
   (`completeMonths()`). Today is 1 Oct 2026, so the window is Oct 2025 to Sep 2026.
2. **A median, not a mean,** for the ordinary month. A holiday's charges, a bonus or a
   tax refund would set a mean and be carried into every month ahead. The mean is kept
   only as a figure for comparison (and for the irregular allowance, below).
3. **One-off rows are not in any of it.** A transaction flagged *one-off* is taken out of
   each month before any median or mean is read (`ordinary()`), and is not projected a
   year on. The cash flow report and balances still count it, because the money moved.

A "day's worth" is always `monthly × 12 ÷ 365`, so a 30-day month gets 30 days of it and a
31-day month gets 31 (`perDay`).

## 1. Typical spending

Spending splits into four parts, because they reach the bank at different times.

### 1a. Cash spending

Cash spending is withdrawals and the other cash out-types on cash accounts.

1. For each of the 12 months, sum cash spending (total spending less card spending, with
   one-offs removed).
2. Take the **median** of the twelve.
3. Subtract the monthly share of the active cash rules that spend (a yearly rule counts
   a twelfth). These are placed on their own dates by the forecast, so leaving them in
   would count them twice.
4. Never below zero.

| Month | Cash spending |
|---|---|
| 2025-10 | 5,275.00 |
| 2025-11 | 9,108.00 |
| 2025-12 | 3,610.00 |
| 2026-01 | 3,625.00 |
| 2026-02 | 3,785.00 |
| 2026-03 | 11,193.00 |
| 2026-04 | 12,073.00 |
| 2026-05 | 10,975.00 |
| 2026-06 | 5,411.00 |
| 2026-07 | 13,611.00 |
| 2026-08 | 8,085.00 |
| 2026-09 | 6,185.00 |

Sorted, the middle two are 6,185 and 8,085, so the median is **7,135**. The CUSTODIAN FEE
rule is 25 a month, so cash is **7,110**, or 233.75 a day.

Cash is spent the day it happens, so it is spread daily from tomorrow.

### 1b. Card spending

Card spending is worked out **card by card** (`cardTypical()`), because each card has its
own statement day and term, and one start date for all of them left one card's charges in a
gap and piled another's into the following month.

For each card:

1. Sum the card's charges by the month they were **made** in, over the 12 months. The
   figure is `card_amount ?? amount`, the card's own figure, which is already HKD. A month
   with no charges counts as zero. One-offs are skipped.
2. Take the **median** of the twelve.
3. Subtract the card's active recurring charges (monthly share). The forecast places
   those on their own dates.
4. A card with nothing left over is dropped.

| Card | Median month | Recurring covered | Typical a month |
|---|---|---|---|
| SC ASIA MILES | 27,633.68 | 1,156.32 | 26,477.36 |
| SIGNATURE | 1,892.00 | 0.00 | 1,892.00 |
| PREMIER | 3,169.00 | 3,233.00 (the rent rule) | dropped (below zero) |
| VISA | 0.00 | 45.45 | dropped |

Together **28,369.36** a month (Asia Miles 870.49 a day, Signature 62.20 a day).

**Cards are paid on due dates, not spent on charge dates.** Each day from tomorrow gets its
day's worth of the card's charges, and that amount is paid on the due date of the statement
that day falls in (`CardStatementCycle::dueDateFor()`). So card money reaches a month's
figure because of the *statement cycle*, not the calendar:

- Charges already made are in the **known** statements, so the typical figure starts
  tomorrow and repeats nothing.
- A statement that has not closed yet will take more charges. Asia Miles closes on the 9th
  with 26 days to pay, so charges on 2 to 8 Oct join the statement due 4 Nov. Those 7 days
  are 6,093.42 and are the "top-up" on a statement whose known part is 35,965.51. Signature
  adds 6 days, 373.22.
- Charges after the October close go on statements due in December.

This is why November's typical spending is low (see the worked example) and December's is
high. Across the year it adds up to the same total.

### 1c. Recurring rules

Rules that spend are not part of the typical figure. They are **known** events, placed on
their dates by the forecast: cash rules, and card rules, whose amounts reach the bank with
the statement. They are only *subtracted* from the medians above, so the median's share of
them is not counted a second time. They total **4,459.77** a month.

### 1d. Irregular spending

A median ignores the annual bill and the odd appliance, and those are not nothing. The
irregular allowance puts back the part of the year's spending that the ordinary month and
the rules do not cover:

```
irregular = average month  -  cash  -  cards  -  recurring rules        (never below 0)
```

| | Monthly |
|---|---|
| Average month (mean of the 12 months' spending) | 40,384.89 |
| Cash (1a) | -7,110.00 |
| Cards (1b) | -28,369.36 |
| Recurring rules (1c) | -4,459.77 |
| **Irregular** | **445.76** |

It is a daily allowance (445.76 × 12 ÷ 365 = 14.65 a day), not a payment, so it is spread
like cash instead of being guessed onto a due date. It is on the typical line by default and
has its own *Irregular spending* switch in the What-if row. One-off rows are in neither the
average nor the medians: left in, one 76,305 purchase was being charged to every month
ahead at 6,360 a month.

### The spending total

```
typical spending a month  =  cash + cards + irregular + recurring rules
                          =  7,110.00 + 28,369.36 + 445.76 + 4,459.77
                          =  40,384.89      (= the year's average month)
```

That equality is deliberate. The Forecast's "a month" box adds up to last year's average
month to the cent.

## 2. Typical income

Typical income is *everyday* income: whatever is left once the things placed on their own
dates are taken out.

### 2a. Everyday income

For each of the 12 months:

```
other income  =  income - dividends          (one-offs removed first)
everyday      =  other income - what the earning rules actually paid that month
```

Then take the **median** of the twelve, never below zero.

"Earning rules" are the active recurring rules that pay into a cash account (the SALARY
rule, 42,946.19). What each rule *actually paid* is read from the rows themselves, matched
by account + type + description (`broughtByRules()`). The rule's *current* amount is not
used, because after a raise that reads every month at the old pay as income lost: nine
months of salary at 40,829.70 each came out 2,116 short, four of them below nothing, and the
median with them. A rule with no rows in the window falls back to its current amount every
month.

Result: **1,189.04** a month, or 39.1 a day. What it is made of: 76 CASH DEPOSIT rows
(12,707, the cash taken from an ATM and put back), 14 CREDIT INTEREST rows (188.88), and the
odd refund.

### 2b. Dividends

Each dividend a cash account was paid in the last year is expected a year on, scaled by the
shares held now over the shares held then. A symbol that has since been sold out pays
nothing. One is left out if another dividend on the same symbol and account is on file near
that date (within half the symbol's usual gap between payments, at most 45 days), as that is
this year's payment, already counted.

### 2c. Bonus

Every `BONUS` deposit in the last year is expected a year on, on its date and at last
year's figure. It is not spread, and it is not in 2a: the median ignores the one large
month.

### 2d. Double pay

For each month, add up the `SALARY` deposits. A month whose salary came to **half again** or
more of the median month is two months' pay (`2 × paid ≥ 3 × median`). The part above the
median is expected on the same date a year on. In this data that is January: 83,159.40
against 40,829.70 is a double pay of 42,329.70 in Jan 2027.

That salary is the rule's, so it comes off the January leftover in 2a. January's leftover is
only 687.81, and the double pay does not leak into the 1,189.

### Where the pieces fall

| Month | Everyday | Dividends | Bonus | Double pay | Typical income |
|---|---|---|---|---|---|
| Oct 2026 (rest of) | 1,172.75 | 3,566.47 | | | 4,739.22 |
| Nov | 1,172.75 | 3,815.00 | | | 4,987.75 |
| Dec | 1,211.84 | 1,393.45 | | | 2,605.29 |
| Jan 2027 | 1,211.84 | 890.96 | | 42,329.70 | 44,432.50 |
| Feb | 1,094.56 | | 20,318.26 | | 21,412.82 |
| Mar | 1,211.84 | | | | 1,211.84 |

The everyday figure is 1,189.04 a month spread by day, so it reads 1,094 in February and
1,212 in a 31-day month.

## 3. Reading it in the page

The *Spare cash* card at the top of the page reads the typical line's lowest day against a
reserve of months of spending; it has its own page, [forecast-spare-cash.md](forecast-spare-cash.md).

### The chart's lines

Each day ahead, from tomorrow:

```
allowance  +=  cash a day  +  card charges due that day
irregular  +=  irregular a day
earned     +=  typical income a day  (and each dividend, bonus and double pay on its date)

typical balance  =  known balance  -  allowance  -  irregular  +  earned
```

The What-if row takes these away independently: *Typical spending* turns off `allowance`,
*Irregular spending* turns off `irregular`, and recurring income off `recurring_in`.

### A month's typical figures (the month list and chart bars)

`monthsAhead[month]` sums, for the days of that month: cash a day + irregular a day + the
card amounts due that month = **typical spending**; and typical income a day + dividends
+ bonus + double pay = **typical income**. Net typical is
`in - out - typical spending + typical income`, where *in* and *out* are the known money.

#### Worked example: November 2026 spending, 13,918.89

| Part | Working | Amount |
|---|---|---|
| Cash | 7,110 × 12 ÷ 365 = 233.75 a day × 30 days | 7,012.60 |
| Cards due in Nov | Asia Miles 7 days × 870.49 + Signature 6 days × 62.20 | 6,466.64 |
| Irregular | 445.76 × 12 ÷ 365 × 30 days | 439.65 |
| **Typical spending** | | **13,918.89** |

Known out for November is 39,596.51 in addition, and includes the two card statements
(Asia Miles 35,965.51, due 4 Nov, and Signature 334.00, due 3 Nov). The 6,093.42 and 373.22
are the not-yet-charged days of those same statements, not a second payment: the 4 Nov
payment will probably be about 42,000, not 35,965.51.

### The month outlook on Home

For the month we are in, `monthOutlook()` gives:

```
likely month end (known)  =  income so far + income to come
                            - spending so far - spending to come
likely month end          =  known  -  typical rest  +  typical income rest
```

- *So far* is from the cash flow report, posted rows since the 1st.
- *To come* is pending cash rows this month and recurring rules still due (`classifyToCome`).
- **Typical rest (spending)** = (cash + irregular) × days left ÷ days in month. Cards are
  left out, because a card charge made in the days left is paid for next month or later.
- **Typical income rest** = everyday income × days left ÷ days in month + the dividends
  expected through the month end.

For 1 Oct: spending 7,312.03 ((7,110 + 445.76) × 30 ÷ 31) and income 4,717.15
(1,189.04 × 30 ÷ 31 + 3,566.47 of October dividends), giving **+17,094.16**.

Note the outlook prorates by days left ÷ days in month (30/31) while the chart spreads by
12/365 a day, so the rest of this month is slightly different on the two: spending 7,312.03
against 7,452.26, income 4,717.15 against 4,739.22. They are not reconciled.

The Forecast's first column in the month chart is built from these outlook figures so that
it reads as the whole month, not a month from tomorrow.

## 4. What it gets wrong, and on purpose

- **Cash round-trips are counted on both sides.** ATM withdrawals (43,100 over the year)
  are cash spending, and the cash put back into SAVING (12,707) is everyday income. They
  roughly cancel in the balance, but cash spending and everyday income are each overstated
  by about the same amount. Fix, if wanted: file the deposits under a *CASH IN* category
  and net them against cash spending.
- **A median cannot see a rare large cost.** That is what the irregular allowance is for,
  and it is an average spread evenly, not a prediction of when.
- **Double pay and bonus are expected from one sample.** One bonus and one double pay in the
  window are assumed to repeat. Flag them *one-off* if they will not.
- **A flagged one-off is only as good as the flag.** A large unflagged row sits in a month's
  figures, shifts the average (and so the irregular allowance), and is only kept out of the
  medians by being one value among twelve.
- **Foreign cash rows have no rate.** They are left out and the currency named, not counted
  one-for-one.
- **The 30/31 against 12/365 gap** above.

## Where it lives

| What | Where |
|---|---|
| The 12 complete months | `completeMonths()`, `lastMonths()` |
| One-offs taken out | `ordinary()`, `oneOff()` |
| Cash, irregular, income, recurring | `typicalSpending()` |
| Each card's typical month | `cardTypical()` |
| Rules' actual payments | `broughtByRules()` |
| Daily spreading, card due dates, lines | `projection()` |
| Dividends, bonus, double pay | `expectedDividends()`, `expectedBonuses()`, `expectedDoublePay()` |
| The month on Home | `monthOutlook()` |
| What-if line maths | `resources/js/components/ForecastChart.vue` |
