<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Do-not-contact list
    |--------------------------------------------------------------------------
    |
    | "Never contact me again", made enforceable. See GO-LIVE-LEGAL #2 — the
    | right to object is ABSOLUTE for direct marketing (Art. 21(2)): no
    | balancing, no legitimate-interest argument. Once someone objects, that is
    | the end of it.
    |
    */

    /*
    | Country code used to expand a national number to international form —
    | `0612345678` becomes `31612345678`, so it matches `+31 6 1234 5678`.
    |
    | This is a NL-only simplification and is honest about it. Dialling a second
    | country means swapping in giggsey/libphonenumber-for-php; guessing at each
    | country's trunk-prefix rules is how a suppression silently misses.
    */
    'default_country_code' => env('SUPPRESSION_DEFAULT_COUNTRY_CODE', '31'),

];
