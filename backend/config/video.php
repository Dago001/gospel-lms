<?php

return [
    'provider' => env('VIDEO_PROVIDER', 'cloudflare'),
    // Fastest legitimate playback speed the player offers. Credit can never exceed wall-clock time x this rate.
    'max_playback_rate' => (float) env('VIDEO_MAX_RATE', 2.0),
    'position_tolerance_seconds' => 2.0,
    'session_idle_minutes' => 15,
    'session_max_hours' => 8,
    'max_event_gap_seconds' => 1800,
    'max_events_per_batch' => 50,
];
