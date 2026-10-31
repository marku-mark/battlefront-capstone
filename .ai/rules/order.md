---
paths:
    - 'app/Services/Order/**'
---

# Order

## Delivery quotes use package measurements

Delivery estimates use the product's packed parcel weight and length, width, and height in centimeters. Volumetric divisor, half-kilogram rounding, included weight, and island-group surcharges are Battlefront configuration assumptions; do not describe them as an LBC tariff or public LBC formula. Keep order quote metadata immutable so historical totals retain the calculation inputs.
