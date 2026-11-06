const unitFormatter = new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatForecastUnits(value) {
    return unitFormatter.format(Number(value));
}

export function forecastUnitLabel(value) {
    return formatForecastUnits(value) === '1.00' ? 'unit' : 'units';
}
