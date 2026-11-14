<?php

return [

    /*
    | Caps on administrator-supplied zone geometry (ADR-002).
    |
    | They bound request cost and catch obviously wrong input. No
    | coordinates, city or map centre belong in this file.
    |
    | Vertex cap: APPROVED by the operator on 2026-11-14 (backlog C-13), at
    | 500 vertices per zone outline; self-intersection is rejected by PostGIS
    | (ST_IsValid). Extent cap: APPROVED by the operator on 2026-11-15 (C-13),
    | at 2 degrees on either axis.
    */
    // The operator's approval of both caps (C-13): vertices 2026-11-14, extent 2026-11-15.
    'caps_approved' => (bool) env('GEO_CAPS_APPROVED', true),

    'zone_max_vertices' => (int) env('GEO_ZONE_MAX_VERTICES', 500),

    // APPROVED 2026-11-15: 2 degrees on either axis.
    'zone_max_extent_degrees' => (float) env('GEO_ZONE_MAX_EXTENT_DEGREES', 2.0),

];
