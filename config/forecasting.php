<?php

return [
    /** Trusted monthly declarations come from explicit preparation, never sales dates. */
    'operational_coverage' => [],

    'development_manifest' => 'forecasting/development-history-coverage.json',

    'cloud_demo_history_enabled' => env('FORECASTING_CLOUD_DEMO_HISTORY_ENABLED', false),
];
