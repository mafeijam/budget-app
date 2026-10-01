# How the Forecast's spare cash is calculated

The card at the top of the Forecast answers one question: **how much cash could be moved out
today** -- invested, paid down, put somewhere else -- **and the balance still never fall
below a reserve** of months of spending. It is the projection turned into a decision.

It is worked out in the browser, in `resources/js/pages/forecast.vue` (`spares`), from
figures the server already sends for the page. Nothing new is computed on the server. The
figures quoted below are the development data as at **2 October 2026**, on the 3-month
horizon, and are there to show the arithmetic; they will drift.

## The shape of it

```
spare cash = the lowest the balance gets ahead
             - the reserve (months of spending)
```

Take the spare out today and every day ahead is that much lower. The lowest day is then
exactly the reserve, and no day goes below it. The card lays this out as the sum itself --
*Lowest ahead − Reserve = Spare*, each with what it is under it. If the reserve is more than
the lowest point, the card is titled *Short of the reserve* and its last figure, in red,
reads *Short by* with how much is missing.

## 1. The lowest the balance gets ahead

This is the runway's **Lowest ahead** figure, the same number to the cent. The server
walks every day from today to the horizon on two lines (`Forecast::projection()`):

- the **known** line: today's balances plus everything on file -- rows dated ahead and
  pending, recurring rules, card statements paid from their bank on the due date;
- the **typical** line: the known line, less typical spending a day at a time and each
  card's typical charges on its due dates, plus typical income and the expected dividends,
  bonus and double pay on their dates. How each of those is estimated is in
  [forecast-typical.md](forecast-typical.md).

The lowest day **after today** on each line is `lowest_ahead.known` and
`lowest_ahead.typical`. The card uses the typical one while *Typical spending* is on, and
the known one while it is off, as every runway figure does.

It is the total of **every cash account in the view**, in its currency: *All* is every
account converted at today's rate, a currency picked is that currency's accounts alone.

### Worked example

| | |
|---|---|
| Today, every cash account | 643,223.87 |
| + salary, 1 Nov (a recurring rule) | +42,946.19 |
| + the other known money in to 27 Nov | +1,040.00 |
| − card statements: SC ASIA MILES 6 Oct and 4 Nov, PREMIER 12 Oct and 13 Nov, SIGNATURE | −63,298.66 |
| **Known balance, 27 Nov** | **623,911.40** |
| − typical card and cash spending built up by then | −17,667.69 |
| − irregular spending built up by then | −1,140.89 |
| + typical income and expected dividends by then | +6,905.91 |
| **Typical balance, 27 Nov -- the lowest ahead** | **612,008.73** |

27 November is the low because the 4 November statement (−35,966) and three weeks of
typical spending have gone out and the 1 December salary has not come in.

## 2. The reserve

A month of spending is everything that goes out in an ordinary month -- the same figure as
*With the rules* in the page's Breakdown box (`spendingInAll()`):

| | |
|---|---|
| Card spending, typical | 28,369.36 |
| Cash spending, typical | 6,590.50 |
| Irregular spending | 619.68 |
| Recurring rules, a monthly share | 4,459.77 |
| **A month** | **40,039.31** |

The reserve is that times the months on the slider: three months is **120,117.93**.

The slider runs from 0 to 12 months in half months, and is remembered per browser
(`forecast.reserveMonths` in localStorage), as the page's other controls are. A half month
is multiplied as money -- the month in ten-thousandths, times twice the months, halved --
not as a float, so 1.5 months is 60,058.97 to the cent.

## 3. The result

```
612,008.73 − 120,117.93 = 491,890.80 spare
```

| Reserve | Reserve figure | Spare |
|---|---|---|
| 1.5 months | 60,058.97 | 551,949.77 |
| 3 months | 120,117.93 | 491,890.80 |
| 6 months | 240,235.86 | 371,772.87 |
| 12 months | 480,471.72 | 131,537.01 |

## 4. With the what-if

The runway's what-if -- typical spending up or down, *No income*, *Irregular spending* off --
redraws the chart's line in the browser, with no server figure behind it. Spare cash
follows it, so the card and the chart under it always agree:

- **The low** is found on the line the chart draws, by the chart's arithmetic
  (`ForecastChart.vue`, `numbers`), on every day after today:

  ```
  known   = point.known − (No income ? point.recurring_in : 0)
  typical = known − (point.allowance + (Irregular on ? point.irregular : 0)) × factor
                  + (No income ? 0 : point.earned)
  ```

  where `factor` is 1 plus the slider's percentage. Its date can move: at +20% the low is
  606,085 on 30 December rather than 27 November.
- **A month of reserve** is scaled as that line's spending is: card and cash spending times
  the factor, irregular spending only while it is on, and the rules as they are, since
  they are known payments and not an estimate. At +20%: (35,579.54 × 1.2 + 4,459.77) × 3 =
  141,466.
- **The figure is an estimate of an estimate,** so it is whole units and marked `≈`, as the
  runway's *What if* figure is: ≈ 464,619 at +20%, ≈ 367,839 at +20% with *No income*.

Reset puts the what-if back, and the card goes back to the exact figure.

## 5. What it does not tell you

- **It pools every account.** The figure is what the total can give, not what any one
  account can. The card statements here are paid from SAVING, whose own low is 252,468.92
  on 12 October; taking the whole spare out of SAVING would take it below zero while the
  total stayed well above the reserve. Take it from where the money is.
- **It is checked to the horizon and no further.** On a balance that rises after its low,
  as this one does, a longer horizon finds the same low. On a falling one, the 12-month
  view is the one to read.
- **Typical is a median, not a cap.** The reserve is the margin for spending above it; a
  large unplanned bill comes straight out of the reserve.
- **Estimates can carry the low.** Expected dividends, a bonus or double pay are on the
  typical line on their dates. Here the low falls before the January double pay and the
  February bonus, so none of it depends on them; on another horizon it might. Switching
  *Typical spending* off shows the known line, which counts no estimates at all.
- **Nothing to say without a typical month.** With no spending history the card is not
  shown.

## Where it lives

| What | Where |
|---|---|
| The two lines and their lowest days | `Forecast::projection()`, `lowest_ahead` |
| A month of spending | `spendingInAll()` in `forecast.vue` |
| Spare cash, the reserve and the what-if low | `spares` in `forecast.vue` |
| The slider | `reserveMonths`, `forecast.reserveMonths` in localStorage |
| What-if line maths, shared with the chart | `resources/js/components/ForecastChart.vue` |
