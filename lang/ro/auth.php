<?php

/*
|--------------------------------------------------------------------------
| Romanian authentication messages
|--------------------------------------------------------------------------
|
| Laravel resolves auth.failed / auth.password / auth.throttle through this
| file, never through lang/ro.json — a dotted key is a group lookup. The three
| lines below are the only ones the app asks for; see LoginRequest,
| AdminLoginRequest and ConfirmablePasswordController.
|
| 'throttle' abbreviates the unit to "sec." on purpose. Romanian numerals take
| the bare noun up to 19 and "de + noun" from 20 up, so a single sentence
| cannot hold both "15 secunde" and "60 de secunde"; the lockout is whatever
| RateLimiter::availableIn returns, so both ranges occur. The abbreviation is
| grammatical for every value. Same reason the :minutes replacement the call
| site also passes goes unused — "1 minute" would be wrong.
|
*/

return [

    'failed' => 'Datele introduse nu se potrivesc. Verifică adresa de email și parola.',
    'password' => 'Parola introdusă nu este corectă.',
    'throttle' => 'Prea multe încercări de autentificare. Încearcă din nou peste :seconds sec.',

];
