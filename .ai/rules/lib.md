---
paths:
    - 'resources/js/pages/Administration/Forecasting.vue,resources/js/lib/forecastUnits.js'
---

# Lib

## Round forecast units only at the presentation boundary

Show nonnegative forecast quantities as nearest whole units with half-up display rounding in generated summaries, monthly estimates, saved review and chart labels/tooltips. Preserve decimal response values, chart datasets, predicted_demand persistence, calculation and evaluation precision; never use formatted quantities for stock planning arithmetic. Explain that displayed monthly estimates may not sum to the displayed quarter.

## Display forecast quantities with two decimal places

The approved display refinement supersedes the earlier whole-unit formatting rule. Show forecast estimates and the 3-month total with exactly two decimal places; use singular unit when the displayed quantity is 1.00. Apply formatting only at display boundaries and explain that the total uses full-precision monthly forecasts before rounding, so minor decimal differences may remain. Never rebalance monthly quantities or change calculations, chart datasets, responses or saved values.
