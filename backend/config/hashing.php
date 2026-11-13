<?php

/*
| Password hashing (ADR-011): Argon2id unless the environment says otherwise.
| The test suite switches to low-cost bcrypt for speed (tests/bootstrap.php).
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
