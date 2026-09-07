<?php

/*
|--------------------------------------------------------------------------
| Romanian password reset messages
|--------------------------------------------------------------------------
|
| The password broker hands back a status constant ('passwords.sent' and the
| rest), which PasswordResetLinkController and NewPasswordController pass
| straight into __(). The key is therefore never a literal in our own source,
| so it cannot live in lang/ro.json and cannot be found by grepping for __('.
| All five the broker can return are listed, because which one comes back is
| decided inside the framework.
|
*/

return [

    'reset' => 'Parola ta a fost schimbată.',
    'sent' => 'Ți-am trimis pe email linkul de resetare a parolei.',
    'throttled' => 'Așteaptă puțin înainte de a încerca din nou.',
    'token' => 'Linkul de resetare a parolei nu mai este valid. Cere unul nou.',
    'user' => 'Nu găsim niciun cont cu această adresă de email.',

];
