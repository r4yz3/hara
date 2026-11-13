<?php

return [

    /*
    | Caps on administrator-supplied zone geometry (ADR-002).
    |
    | PROPOSED VALUES, NOT YET APPROVED (backlog C-13). They bound request
    | cost and catch obviously wrong input such as a country-sized polygon.
    | No coordinates, city or map centre belong in this file.
    */
    // Set to true only when a person has approved the two caps below (C-13).
    'caps_approved' => (bool) env('GEO_CAPS_APPROVED', false),

    'zone_max_vertices' => (int) env('GEO_ZONE_MAX_VERTICES', 5000),

    'zone_max_extent_degrees' => (float) env('GEO_ZONE_MAX_EXTENT_DEGREES', 2.0),

];
