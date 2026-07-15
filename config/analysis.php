<?php

return [

    /*
    | Company analysis providers (Phase 4). Both default to offline fakes so the
    | pipeline builds and tests without hitting real sites or the LLM.
    */
    'drivers' => [
        'website' => env('WEBSITE_ANALYZER_DRIVER', 'fake'), // fake | http
        'llm' => env('ANALYSIS_LLM_DRIVER', 'fake'),         // fake | claude
    ],

    // Google PageSpeed Insights key for the http website analyzer (optional).
    'pagespeed_key' => env('PAGESPEED_API_KEY'),
];
