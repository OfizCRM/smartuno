<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        self::sync();
    }

    /**
     * The 19 transactional email templates, in Romanian.
     *
     * `content` is PROSE ONLY — an <h1> and one to three <p>. Everything structural (the
     * green header label, the inbox preheader, the button, the closing note) lives in
     * `meta` and is rendered by the shared email layout, so an admin editing the body in
     * /admin/email-system cannot break the layout.
     *
     * Every {{placeholder}} used in `subject`, `content`, `meta.preheader`, `meta.cta_url`
     * and `meta.note` must appear in `meta.placeholders`, which lists exactly what the
     * call site actually passes. A placeholder that is never passed renders as literal
     * "{{...}}" in the recipient's inbox.
     *
     * Each entry also carries `legacy`: the English factory text this row shipped with.
     * It is what tells `sync()` whether a stored row is still untouched, and what the
     * accompanying migration's down() restores. A literal is used rather than a checksum
     * for two reasons: it shows up in a diff and in grep, and down() has to reproduce the
     * old text verbatim — a hash can recognise the factory text but never reconstruct it.
     *
     * @return array<int, array{name: string, slug: string, subject: string, type: string, content: string, enabled: bool, meta: array<string, mixed>, legacy: array{name: string, subject: string, content: string, meta: array<string, mixed>}}>
     */
    public static function templates(): array
    {
        return [
            // ── Auth ────────────────────────────────────────────────────────
            [
                'name' => 'Bun venit',
                'slug' => 'welcome',
                'subject' => 'Bun venit în {{app_name}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Bun venit în {{app_name}}</h1><p>Salut, {{user_name}},</p><p>Contul tău este gata. De aici înainte poți conecta canalele pe care îți scriu clienții și le poți răspunde tuturor dintr-un singur loc.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'CONT NOU',
                    'preheader' => 'Contul tău e gata — hai să conectăm primul canal.',
                    'cta_label' => 'Intră în cont',
                    'cta_url' => '{{login_url}}',
                    'fallback' => false,
                    'note' => null,
                    'description' => 'Trimis imediat după ce cineva își face cont, inclusiv când acceptă o invitație în echipă.',
                    'placeholders' => ['app_name', 'user_name', 'login_url'],
                ],
                'legacy' => [
                    'name' => 'Welcome',
                    'subject' => 'Welcome to {{app_name}}!',
                    'content' => "<p>Hi {{user_name}},</p><p>Welcome to {{app_name}}! We're excited to have you on board.</p><p><a href=\"{{login_url}}\">Log in to your account</a></p><p>— The {{app_name}} Team</p>",
                    'meta' => [
                        'description' => 'Sent when a new user registers.',
                        'placeholders' => ['app_name', 'user_name', 'login_url'],
                    ],
                ],
            ],
            [
                'name' => 'Resetare parolă',
                'slug' => 'reset_password',
                'subject' => 'Resetează-ți parola',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Resetează-ți parola</h1><p>Am primit o cerere de resetare a parolei pentru contul tău {{app_name}}. Apasă butonul de mai jos ca să îți alegi una nouă.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'SECURITATE CONT',
                    'preheader' => 'Linkul din acest email e valabil 60 de minute.',
                    'cta_label' => 'Setează o parolă nouă',
                    'cta_url' => '{{reset_url}}',
                    'fallback' => true,
                    'note' => 'Linkul e valabil 60 de minute. Dacă nu tu ai cerut resetarea, ignoră acest email — parola rămâne neschimbată.',
                    'description' => 'Trimis când cineva cere resetarea parolei.',
                    'placeholders' => ['app_name', 'reset_url'],
                ],
                'legacy' => [
                    'name' => 'Password Reset',
                    'subject' => 'Reset your {{app_name}} password',
                    'content' => '<p>Hello,</p><p>You requested a password reset. Click the link below to reset your password:</p><p><a href="{{reset_url}}">Reset Password</a></p><p>This link expires in 60 minutes. If you did not request this, please ignore this email.</p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a user requests a password reset.',
                        'placeholders' => ['app_name', 'reset_url'],
                    ],
                ],
            ],
            [
                'name' => 'Confirmare adresă de email',
                'slug' => 'email_verification',
                'subject' => 'Confirmă-ți adresa de email',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Confirmă-ți adresa de email</h1><p>Mai e un pas până contul tău {{app_name}} e gata. Apasă butonul de mai jos ca să confirmi că adresa aceasta îți aparține.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'CONFIRMARE CONT',
                    'preheader' => 'Un singur clic și contul tău e gata de folosit.',
                    'cta_label' => 'Confirmă adresa',
                    'cta_url' => '{{verification_url}}',
                    'fallback' => true,
                    'note' => 'Linkul e valabil 60 de minute. Dacă nu ți-ai făcut cont pe {{app_name}}, poți ignora liniștit acest email — nu se întâmplă nimic.',
                    'description' => 'Trimis ca să confirme adresa de email a unui utilizator. Nu primește numele destinatarului, deci nu are formulă de salut.',
                    'placeholders' => ['app_name', 'verification_url'],
                ],
                'legacy' => [
                    'name' => 'Email Verification',
                    'subject' => 'Verify your {{app_name}} account',
                    'content' => '<p>Hello,</p><p>Please verify your email address by clicking the link below:</p><p><a href="{{verification_url}}">Verify Email</a></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent to verify a user\'s email address.',
                        'placeholders' => ['app_name', 'verification_url'],
                    ],
                ],
            ],
            [
                'name' => 'Link de conectare',
                'slug' => 'magic_link',
                'subject' => 'Linkul tău de conectare',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Intră în cont fără parolă</h1><p>Ai cerut un link de conectare pentru {{app_name}}. Apasă butonul de mai jos și te conectăm direct, fără să îți mai ceară parola.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'CONECTARE RAPIDĂ',
                    'preheader' => 'Funcționează o singură dată, apoi expiră.',
                    'cta_label' => 'Intră în cont',
                    'cta_url' => '{{magic_link_url}}',
                    'fallback' => true,
                    'note' => 'Linkul expiră în {{expires_minutes}} minute și poate fi folosit o singură dată. Dacă nu tu l-ai cerut, ignoră acest email.',
                    'description' => 'Trimis când cineva cere un link de conectare fără parolă. Funcția este dezactivată implicit.',
                    'placeholders' => ['app_name', 'magic_link_url', 'expires_minutes'],
                ],
                'legacy' => [
                    'name' => 'Magic Link Login',
                    'subject' => 'Your magic login link for {{app_name}}',
                    'content' => '<p>Hello,</p><p>Click the link below to log in to {{app_name}} (expires in {{expires_minutes}} minutes):</p><p><a href="{{magic_link_url}}">Log in to {{app_name}}</a></p><p>If you did not request this, ignore this email.</p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a user requests a magic login link.',
                        'placeholders' => ['app_name', 'magic_link_url', 'expires_minutes'],
                    ],
                ],
            ],
            [
                'name' => 'Invitație în echipă',
                'slug' => 'team_invitation',
                'subject' => '{{inviter_name}} te invită în {{organization_name}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Ai primit o invitație</h1><p>{{inviter_name}} te invită să te alături echipei {{organization_name}}.</p><p>{{app_name}} este aplicația în care o firmă răspunde clienților de pe WhatsApp, Facebook sau email dintr-un singur loc. Apasă butonul de mai jos ca să îți faci contul și să intri în echipă.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'INVITAȚIE ECHIPĂ',
                    'preheader' => 'Îți faci contul în mai puțin de un minut.',
                    'cta_label' => 'Acceptă invitația',
                    'cta_url' => '{{invitation_url}}',
                    'fallback' => true,
                    'note' => 'Invitația e valabilă {{expires_days}} zile. Dacă nu știi despre ce este vorba, poți ignora acest email.',
                    'description' => 'Trimis persoanei invitate într-un cont de echipă. Destinatarul nu are încă niciun cont și poate să nu fi auzit de noi, deci textul spune și ce face aplicația.',
                    'placeholders' => ['app_name', 'inviter_name', 'organization_name', 'invitation_url', 'expires_days'],
                ],
                'legacy' => [
                    'name' => 'Team Invitation',
                    'subject' => 'You\'ve been invited to join {{organization_name}}',
                    'content' => '<p>Hello,</p><p>{{inviter_name}} has invited you to join {{organization_name}} on {{app_name}}.</p><p><a href="{{invitation_url}}">Accept Invitation</a></p><p>This invitation expires in {{expires_days}} days.</p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a user is invited to join an organization.',
                        'placeholders' => ['app_name', 'inviter_name', 'organization_name', 'invitation_url', 'expires_days'],
                    ],
                ],
            ],

            // ── Facturare ───────────────────────────────────────────────────
            [
                'name' => 'Plată eșuată',
                'slug' => 'payment_failed',
                'subject' => 'Plata nu a trecut — actualizează metoda de plată',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Plata nu a putut fi procesată</h1><p>Salut, {{user_name}},</p><p>Plata de {{amount}} {{currency}} pentru abonamentul tău {{app_name}} a fost refuzată. De obicei e un card expirat sau o blocare de la bancă. Scrie-ne și schimbăm împreună metoda de plată, ca să nu se întrerupă serviciul.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'FACTURARE',
                    'preheader' => 'Scrie-ne și rezolvăm în câteva minute.',
                    'cta_label' => 'Vezi facturile',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'note' => 'Până se face plata, abonamentul rămâne marcat ca neachitat. Dacă ai nevoie de ajutor, scrie-ne.',
                    'description' => 'Trimis titularului abonamentului când o plată recurentă eșuează. Doar pe Stripe. Suma vine ca număr simplu, fără simbol, iar moneda ca un cod separat — se scriu întotdeauna împreună. Butonul duce în Facturare, care e o listă de facturi: aplicația nu are unde să se schimbe cardul unui abonament activ, de asta textul cere să ne scrie.',
                    'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                ],
                'legacy' => [
                    'name' => 'Payment Failed',
                    'subject' => 'Payment Failed - Action Required',
                    'content' => '<p>Hello {{user_name}},</p><p>Your payment of <strong>{{amount}} {{currency}}</strong> could not be processed.</p><p>Please update your payment method to avoid service interruption.</p><p><a href="{{billing_url}}">Update Payment Method</a></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a subscription payment fails.',
                        'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                    ],
                ],
            ],
            [
                'name' => 'Plată confirmată',
                'slug' => 'payment_success',
                'subject' => 'Plata a fost confirmată',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Plata a fost confirmată</h1><p>Îți confirmăm că plata pentru abonament a fost înregistrată. Facturile și detaliile abonamentului le găsești în secțiunea Facturare din cont.</p>',
                'enabled' => false,
                'meta' => [
                    'label' => 'FACTURARE',
                    'preheader' => 'Abonamentul tău este la zi.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'fallback' => false,
                    'note' => null,
                    'description' => 'Șablon inactiv: nimic din aplicație nu îl trimite, deci nu primește nicio variabilă și textul nu poate folosi niciuna. Dacă va fi conectat vreodată, locul de apel trebuie să trimită app_name, user_name, amount, currency și billing_url.',
                    'placeholders' => [],
                ],
                'legacy' => [
                    'name' => 'Payment Success',
                    'subject' => 'Payment Successful - {{app_name}}',
                    'content' => '<p>Hello {{user_name}},</p><p>Your payment of <strong>{{amount}} {{currency}}</strong> was successful. Thank you for your subscription.</p><p><a href="{{billing_url}}">View Billing</a></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a subscription payment succeeds.',
                        'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                    ],
                ],
            ],
            [
                'name' => 'Abonament activat',
                'slug' => 'subscription_started',
                'subject' => 'Abonamentul {{plan_name}} este activ',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău este activ</h1><p>Salut, {{user_name}},</p><p>Planul {{plan_name}} este activ pe contul tău {{app_name}} începând din {{starts_at}}. Ai acces la tot ce include planul. Nu mai ai nimic de făcut.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Ai acces complet începând de acum.',
                    'cta_label' => 'Vezi abonamentul',
                    'cta_url' => '{{subscription_url}}',
                    'fallback' => false,
                    'note' => 'Dacă ceva nu ți se pare corect, scrie-ne și verificăm împreună.',
                    'description' => 'Trimis când un abonament nou devine activ. Butonul duce în pagina Abonament — planul, starea, schimbarea de plan — nu în lista de facturi. Data e scrisă în română, în fusul orar al destinatarului, ca să fie aceeași zi cu cea de pe ecran. Ciclul de facturare ajunge ca „month” / „year”, deci textul nu îl folosește.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at', 'subscription_url'],
                ],
                'legacy' => [
                    'name' => 'Subscription Started',
                    'subject' => 'Your {{plan_name}} subscription is now active',
                    'content' => '<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription ({{billing_cycle}}ly billing) is now active as of {{starts_at}}.</p><p>Thank you for subscribing to {{app_name}}!</p><p>— The {{app_name}} Team</p>',
                    'meta' => [
                        'description' => 'Sent when a new subscription becomes active.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at'],
                    ],
                ],
            ],
            [
                'name' => 'Confirmare abonament',
                'slug' => 'subscription_confirmation',
                'subject' => 'Abonamentul tău este activ',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău este activ</h1><p>Îți confirmăm că abonamentul a fost activat. Detaliile complete le găsești în secțiunea Facturare din cont.</p>',
                'enabled' => false,
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Detaliile sunt în secțiunea Facturare.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'fallback' => false,
                    'note' => null,
                    'description' => 'Șablon inactiv: nimic din aplicație nu îl trimite și dublează „Abonament activat”. Nu primește nicio variabilă. Dacă va fi conectat vreodată, locul de apel trebuie să trimită app_name, user_name și plan_name.',
                    'placeholders' => [],
                ],
                'legacy' => [
                    'name' => 'Subscription Confirmation',
                    'subject' => 'Subscription Confirmed - {{plan_name}}',
                    'content' => '<p>Hello {{user_name}},</p><p>Your subscription to <strong>{{plan_name}}</strong> has been confirmed.</p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent to confirm a subscription (checkout completed).',
                        'placeholders' => ['app_name', 'user_name', 'plan_name'],
                    ],
                ],
            ],
            [
                'name' => 'Abonament anulat',
                'slug' => 'subscription_cancelled',
                'subject' => 'Abonamentul {{plan_name}} a fost anulat',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul a fost anulat</h1><p>Salut, {{user_name}},</p><p>Am anulat abonamentul {{plan_name}} — de acum înainte nu îți mai luăm niciun ban.</p><p>Accesul se încheie: {{ends_at}}.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Datele din cont rămân la locul lor.',
                    'cta_label' => 'Vezi abonamentul',
                    'cta_url' => '{{subscription_url}}',
                    'fallback' => false,
                    'note' => 'Conversațiile și contactele rămân salvate. Dacă te răzgândești, poți alege oricând un plan nou din pagina Abonament.',
                    'description' => 'Trimis când un abonament este anulat. Data de final ajunge scrisă în română, în fusul orar al destinatarului, sau ca „imediat” când anularea are efect pe loc — de asta stă pe rândul ei, într-o propoziție care funcționează în ambele situații. Butonul duce în pagina Abonament, nu direct la plată: până la data de final abonamentul poate fi încă activ, iar un checkout nou ar deschide un al doilea abonament.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at', 'subscription_url'],
                ],
                'legacy' => [
                    'name' => 'Subscription Cancelled',
                    'subject' => 'Your {{plan_name}} subscription has been cancelled',
                    'content' => '<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription has been cancelled.</p><p>You will continue to have access until <strong>{{ends_at}}</strong>.</p><p>We hope to see you again. If you have any questions, feel free to reach out.</p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a subscription is cancelled.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at'],
                    ],
                ],
            ],
            [
                'name' => 'Abonament reînnoit',
                'slug' => 'subscription_renewed',
                'subject' => 'Abonamentul {{plan_name}} s-a reînnoit',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul s-a reînnoit</h1><p>Salut, {{user_name}},</p><p>Am încasat {{amount}} {{currency}} pentru abonamentul {{plan_name}}. Totul rămâne activ, nu trebuie să faci nimic.</p><p>Următoarea reînnoire: {{next_renewal}}</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'FACTURARE',
                    'preheader' => 'Plata a trecut, nu ai nimic de făcut.',
                    'cta_label' => 'Vezi facturile',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'note' => 'Dacă suma nu ți se pare corectă, scrie-ne și o verificăm.',
                    'description' => 'Trimis la fiecare reînnoire plătită. Data e scrisă în română, în fusul orar al destinatarului; când lipsește ajunge ca „nu este stabilită încă”, de aceea stă pe un rând al ei, fără punct la final.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal', 'billing_url'],
                ],
                'legacy' => [
                    'name' => 'Subscription Renewed',
                    'subject' => 'Your {{plan_name}} subscription has been renewed',
                    'content' => '<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription has been successfully renewed.</p><p>Amount charged: <strong>{{amount}} {{currency}}</strong></p><p>Next renewal: <strong>{{next_renewal}}</strong></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a subscription renews (invoice paid).',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal'],
                    ],
                ],
            ],
            [
                'name' => 'Abonament expirat',
                'slug' => 'subscription_expired',
                'subject' => 'Abonamentul {{plan_name}} s-a încheiat',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău s-a încheiat</h1><p>Salut, {{user_name}},</p><p>Perioada ta pe planul {{plan_name}} s-a încheiat și abonamentul nu mai este activ. Reactivează-l ca să continui pe {{app_name}} fără întreruperi.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Reactivezi în câteva minute, direct din cont.',
                    'cta_label' => 'Reactivează abonamentul',
                    'cta_url' => '{{pricing_url}}',
                    'fallback' => false,
                    'note' => 'Conversațiile, contactele și setările rămân salvate. Nu se șterge nimic.',
                    'description' => 'Trimis și când expiră un abonament plătit, și când se termină o perioadă de probă fără conversie. Textul trebuie să funcționeze în ambele situații. Butonul duce în pagina Planuri, singurul loc din aplicație de unde se ajunge la plată — abonamentul e deja încheiat, deci nu se poate dubla nimic.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'pricing_url'],
                ],
                'legacy' => [
                    'name' => 'Subscription Expired',
                    'subject' => 'Your {{plan_name}} subscription has expired',
                    'content' => '<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription on {{app_name}} has expired.</p><p>Renew your subscription to restore full access.</p><p><a href="{{billing_url}}">Renew Subscription</a></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a subscription expires or becomes past due.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_url'],
                    ],
                ],
            ],
            [
                'name' => 'Schimbare de plan',
                'slug' => 'plan_changed',
                'subject' => 'Planul tău a fost schimbat',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Planul tău a fost schimbat</h1><p>Salut, {{user_name}},</p><p>Abonamentul tău {{app_name}} a trecut de la {{old_plan}} la {{new_plan}}. Schimbarea este deja activă pe cont.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Nu ai nimic de făcut — doar de știut.',
                    'cta_label' => 'Vezi detaliile',
                    'cta_url' => '{{subscription_url}}',
                    'fallback' => false,
                    'note' => 'Dacă schimbarea nu ți se pare corectă, scrie-ne și o verificăm.',
                    'description' => 'Trimis când planul unui abonament este schimbat. Textul rămâne neutru: nu știm dacă e trecere în sus sau în jos și nu primim nicio sumă. Butonul duce în pagina Abonament, unde se vede planul curent.',
                    'placeholders' => ['app_name', 'user_name', 'old_plan', 'new_plan', 'subscription_url'],
                ],
                'legacy' => [
                    'name' => 'Plan Changed',
                    'subject' => 'Your {{app_name}} plan has been updated',
                    'content' => '<p>Hi {{user_name}},</p><p>Your subscription plan on {{app_name}} has been updated from <strong>{{old_plan}}</strong> to <strong>{{new_plan}}</strong>.</p><p><a href="{{billing_url}}">View Billing</a></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a user\'s plan is changed.',
                        'placeholders' => ['app_name', 'user_name', 'old_plan', 'new_plan', 'billing_url'],
                    ],
                ],
            ],
            [
                'name' => 'Perioada de probă se încheie',
                'slug' => 'trial_ending',
                'subject' => 'Perioada de probă {{plan_name}} se apropie de final',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Perioada de probă se apropie de final</h1><p>Salut, {{user_name}},</p><p>Perioada de probă pentru planul {{plan_name}} se încheie pe {{trial_ends_at}}.</p><p>Alege un plan și adaugă cardul ca să continui fără întrerupere.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Alege un plan ca să nu se întrerupă nimic.',
                    'cta_label' => 'Alege un plan',
                    'cta_url' => '{{pricing_url}}',
                    'fallback' => false,
                    'note' => 'Dacă nu alegi un plan, abonamentul nu pornește la finalul perioadei de probă. Datele rămân salvate.',
                    'description' => 'Trimis o singură dată, înainte ca perioada de probă să se termine. Textul spune data, nu numărul de zile: days_remaining poate fi 1 și „în 1 zile” ar fi greșit. Butonul duce în pagina Planuri, singurul drum din aplicație către checkout — acolo se introduce cardul.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'days_remaining', 'trial_ends_at', 'pricing_url'],
                ],
                'legacy' => [
                    'name' => 'Trial Ending',
                    'subject' => 'Your {{plan_name}} trial ends in {{days_remaining}} day(s)',
                    'content' => '<p>Hi {{user_name}},</p><p>Your free trial for the <strong>{{plan_name}}</strong> plan on {{app_name}} ends in <strong>{{days_remaining}} day(s)</strong> (on {{trial_ends_at}}).</p><p>Add a payment method now to keep uninterrupted access.</p><p><a href="{{billing_url}}">Add Payment Method</a></p><p>— {{app_name}}</p>',
                    'meta' => [
                        'description' => 'Sent when a user\'s trial is about to expire.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'days_remaining', 'trial_ends_at', 'billing_url'],
                    ],
                ],
            ],

            // ── Suport / Tichete ────────────────────────────────────────────
            [
                'name' => 'Tichet înregistrat',
                'slug' => 'support_ticket_created',
                'subject' => 'Am primit tichetul #{{ticket_id}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Am primit tichetul tău</h1><p>Salut, {{user_name}},</p><p>Ți-am înregistrat tichetul „{{ticket_subject}}” cu numărul #{{ticket_id}}. Îl preia un coleg și primești răspunsul pe email.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'SUPORT',
                    'preheader' => 'Îți răspundem cât putem de repede.',
                    'cta_label' => 'Vezi tichetul',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => 'Dacă vrei să adaugi ceva, răspunde direct în tichet.',
                    'description' => 'Trimis persoanei care a deschis tichetul. Poate ajunge și la o adresă fără cont, dacă tichetul a fost deschis de un administrator. Prioritatea ajunge tradusă („ridicată”, „urgentă”), dar textul nu o folosește: nu îi spune nimic celui care tocmai a scris.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                ],
                'legacy' => [
                    'name' => 'Support Ticket Created',
                    'subject' => 'Your support ticket #{{ticket_id}} has been received',
                    'content' => '<p>Hi {{user_name}},</p><p>Thank you for reaching out! We have received your support ticket and our team will get back to you as soon as possible.</p><p><strong>Ticket Details</strong><br>Ticket ID: <strong>#{{ticket_id}}</strong><br>Subject: <strong>{{ticket_subject}}</strong><br>Priority: <strong>{{ticket_priority}}</strong><br>Status: <strong>Open</strong></p><p><a href="{{ticket_url}}">View Your Ticket</a></p><p>— The {{app_name}} Support Team</p>',
                    'meta' => [
                        'description' => 'Sent to the user when they submit a new support ticket.',
                        'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                    ],
                ],
            ],
            [
                'name' => 'Tichet nou (administrator)',
                'slug' => 'support_ticket_admin_new',
                'subject' => 'Tichet nou #{{ticket_id}} de la {{user_name}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Tichet nou #{{ticket_id}}</h1><p>{{user_name}} ({{user_email}}) · prioritate {{ticket_priority}} · subiect: {{ticket_subject}}</p><p><strong>Mesaj:</strong></p><blockquote>{{ticket_message}}</blockquote>',
                'enabled' => true,
                'meta' => [
                    'label' => 'SUPORT INTERN',
                    'preheader' => 'Prioritate {{ticket_priority}}, încă fără răspuns.',
                    'cta_label' => 'Deschide tichetul',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => null,
                    'description' => 'Trimis tuturor administratorilor activi ai platformei când un client deschide un tichet. Este un email intern, scris scurt și operațional. Atenție: aici NU se trimite app_name — dacă îl folosești în text, apare ca atare în inbox.',
                    'placeholders' => ['ticket_id', 'user_name', 'user_email', 'ticket_subject', 'ticket_priority', 'ticket_message', 'ticket_url'],
                ],
                'legacy' => [
                    'name' => 'New Support Ticket (Admin)',
                    'subject' => 'New support ticket #{{ticket_id}} from {{user_name}}',
                    'content' => '<p>A new support ticket has been submitted.</p><p><strong>Ticket Details</strong><br>Ticket ID: <strong>#{{ticket_id}}</strong><br>From: <strong>{{user_name}}</strong> ({{user_email}})<br>Subject: <strong>{{ticket_subject}}</strong><br>Priority: <strong>{{ticket_priority}}</strong></p><p><strong>Message:</strong><br>{{ticket_message}}</p><p><a href="{{ticket_url}}">View &amp; Respond</a></p>',
                    'meta' => [
                        'description' => 'Sent to admin users when a new support ticket is submitted.',
                        'placeholders' => ['ticket_id', 'user_name', 'user_email', 'ticket_subject', 'ticket_priority', 'ticket_message', 'ticket_url'],
                    ],
                ],
            ],
            [
                'name' => 'Răspuns la tichet (client)',
                'slug' => 'support_ticket_reply_client',
                'subject' => 'Răspuns la tichetul #{{ticket_id}}: {{ticket_subject}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Ai un răspuns</h1><p>Salut, {{user_name}},</p><p>{{staff_name}} a răspuns la tichetul #{{ticket_id}} — „{{ticket_subject}}”:</p><p>{{reply_message}}</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'SUPORT',
                    'preheader' => '{{staff_name}} ți-a răspuns la tichet.',
                    'cta_label' => 'Vezi și răspunde',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => null,
                    'description' => 'Trimis clientului când un coleg din suport răspunde pe tichetul lui.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'staff_name', 'reply_message', 'ticket_url'],
                ],
                'legacy' => [
                    'name' => 'Support Ticket Reply (to Client)',
                    'subject' => 'New reply on your ticket #{{ticket_id}}: {{ticket_subject}}',
                    'content' => '<p>Hi {{user_name}},</p><p>Our support team has replied to your ticket <strong>#{{ticket_id}}</strong>.</p><p><strong>Reply from {{staff_name}}:</strong><br>{{reply_message}}</p><p><a href="{{ticket_url}}">View &amp; Reply</a></p><p>— The {{app_name}} Support Team</p>',
                    'meta' => [
                        'description' => 'Sent to the client when an admin posts a reply on their ticket.',
                        'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'staff_name', 'reply_message', 'ticket_url'],
                    ],
                ],
            ],
            [
                'name' => 'Răspuns client (administrator)',
                'slug' => 'support_ticket_reply_admin',
                'subject' => 'Răspuns client pe tichetul #{{ticket_id}}: {{ticket_subject}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Răspuns nou pe #{{ticket_id}}</h1><p>{{user_name}} ({{user_email}}) · subiect: {{ticket_subject}}</p><p><strong>Mesaj:</strong></p><blockquote>{{reply_message}}</blockquote>',
                'enabled' => true,
                'meta' => [
                    'label' => 'SUPORT INTERN',
                    'preheader' => '{{user_name}} a adăugat un mesaj nou.',
                    'cta_label' => 'Deschide tichetul',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => null,
                    'description' => 'Trimis tuturor administratorilor activi când un client răspunde pe un tichet. Email intern. Atenție: aici NU se trimit app_name și ticket_priority.',
                    'placeholders' => ['ticket_id', 'user_name', 'user_email', 'ticket_subject', 'reply_message', 'ticket_url'],
                ],
                'legacy' => [
                    'name' => 'Support Ticket Reply (to Admin)',
                    'subject' => 'Client replied on ticket #{{ticket_id}}: {{ticket_subject}}',
                    'content' => '<p>A client has replied to support ticket <strong>#{{ticket_id}}</strong>.</p><p><strong>From:</strong> {{user_name}} ({{user_email}})<br><strong>Subject:</strong> {{ticket_subject}}</p><p><strong>Reply:</strong><br>{{reply_message}}</p><p><a href="{{ticket_url}}">View &amp; Respond</a></p>',
                    'meta' => [
                        'description' => 'Sent to admin users when a client posts a reply on a ticket.',
                        'placeholders' => ['ticket_id', 'user_name', 'user_email', 'ticket_subject', 'reply_message', 'ticket_url'],
                    ],
                ],
            ],
            [
                'name' => 'Stare tichet schimbată',
                'slug' => 'support_ticket_status_changed',
                'subject' => 'Am actualizat tichetul #{{ticket_id}}',
                'type' => 'email',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Starea tichetului s-a schimbat</h1><p>Salut, {{user_name}},</p><p>Tichetul #{{ticket_id}} — „{{ticket_subject}}” — are acum starea {{new_status}}.</p>',
                'enabled' => true,
                'meta' => [
                    'label' => 'SUPORT',
                    'preheader' => 'Tichetul „{{ticket_subject}}” a fost actualizat.',
                    'cta_label' => 'Vezi tichetul',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => 'Dacă problema nu e rezolvată, răspunde în tichet și îl redeschidem.',
                    'description' => 'Trimis clientului când un administrator schimbă starea tichetului. new_status ajunge tradus în română (Deschis / În lucru / Închis); valoarea păstrată în baza de date rămâne cea în engleză.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'new_status', 'ticket_url'],
                ],
                'legacy' => [
                    'name' => 'Support Ticket Status Changed',
                    'subject' => 'Your ticket #{{ticket_id}} status updated to {{new_status}}',
                    'content' => '<p>Hi {{user_name}},</p><p>The status of your support ticket has been updated.</p><p><strong>Ticket:</strong> #{{ticket_id}} — {{ticket_subject}}<br><strong>New Status:</strong> <strong>{{new_status}}</strong></p><p><a href="{{ticket_url}}">View Your Ticket</a></p><p>— The {{app_name}} Support Team</p>',
                    'meta' => [
                        'description' => 'Sent to the client when an admin changes the status of their ticket.',
                        'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'new_status', 'ticket_url'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Every Romanian subject/content/meta this project has shipped for a row BEFORE the
     * one templates() holds today, oldest first.
     *
     * It exists for one job: telling 2026_09_07_000003's down() that a row is still ours to
     * roll back to English. That migration used to ask holdsText() about the CURRENT
     * definition, which stops matching the moment a later migration rewrites the copy — so
     * a full rollback left those rows in Romanian while every other row went back to
     * English, a state neither migration describes.
     *
     * A migration that rewrites a row appends the text it replaced here. Nothing is ever
     * removed: an install can be rolled back from any version we have shipped.
     *
     * @return array<string, list<array{subject: string, content: string, meta: array<string, mixed>}>>
     */
    public static function shippedRevisions(): array
    {
        return [
            'subscription_started' => [
                [
                    'subject' => 'Abonamentul {{plan_name}} este activ',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău este activ</h1><p>Salut, {{user_name}},</p><p>Planul {{plan_name}} este activ pe contul tău {{app_name}}. Ai acces la tot ce include, fără alți pași din partea ta.</p>',
                    'meta' => [
                        'label' => 'ABONAMENT',
                        'preheader' => 'Ai acces complet începând de acum.',
                        'cta_label' => null,
                        'cta_url' => null,
                        'fallback' => false,
                        'note' => 'Factura și detaliile abonamentului le găsești oricând în secțiunea Facturare din cont.',
                        'description' => 'Trimis când un abonament nou devine activ. Nu are buton: locul de apel nu trimite niciun URL. Ciclul de facturare ajunge ca „month” / „year”, iar data în format englezesc, deci textul nu le folosește.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at'],
                    ],
                ],
                [
                    'subject' => 'Abonamentul {{plan_name}} este activ',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău este activ</h1><p>Salut, {{user_name}},</p><p>Planul {{plan_name}} este activ pe contul tău {{app_name}} începând din {{starts_at}}. Ai acces la tot ce include, fără alți pași din partea ta.</p>',
                    'meta' => [
                        'note' => 'Dacă ceva nu ți se pare corect, scrie-ne și verificăm împreună.',
                        'label' => 'ABONAMENT',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Vezi abonamentul',
                        'preheader' => 'Ai acces complet începând de acum.',
                        'description' => 'Trimis când un abonament nou devine activ. Butonul duce în secțiunea Facturare, unde stau facturile și detaliile abonamentului. Ciclul de facturare ajunge ca „month” / „year”, deci textul nu îl folosește.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at', 'billing_url'],
                    ],
                ],
            ],
            'subscription_cancelled' => [
                [
                    'subject' => 'Abonamentul {{plan_name}} a fost anulat',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul a fost anulat</h1><p>Salut, {{user_name}},</p><p>Am anulat abonamentul {{plan_name}} și nu îți mai emitem nicio plată nouă. Dacă vrei să știi exact până când mai ai acces, scrie-ne și îți spunem.</p>',
                    'meta' => [
                        'label' => 'ABONAMENT',
                        'preheader' => 'Datele din cont rămân la locul lor.',
                        'cta_label' => null,
                        'cta_url' => null,
                        'fallback' => false,
                        'note' => 'Conversațiile și contactele rămân salvate. Dacă vrei să reactivezi abonamentul, scrie-ne și te ajutăm.',
                        'description' => 'Trimis când un abonament este anulat. Nu are buton: locul de apel nu trimite niciun URL. Data de final ajunge fie ca dată în engleză, fie ca „immediately”, deci textul nu o folosește.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at'],
                    ],
                ],
                [
                    'subject' => 'Abonamentul {{plan_name}} a fost anulat',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul a fost anulat</h1><p>Salut, {{user_name}},</p><p>Am anulat abonamentul {{plan_name}} și nu îți mai emitem nicio plată nouă.</p><p>Accesul se încheie: {{ends_at}}.</p>',
                    'meta' => [
                        'note' => 'Conversațiile și contactele rămân salvate. Dacă te răzgândești, poți reactiva abonamentul de aici.',
                        'label' => 'ABONAMENT',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Reactivează abonamentul',
                        'preheader' => 'Datele din cont rămân la locul lor.',
                        'description' => 'Trimis când un abonament este anulat. Data de final ajunge scrisă în română, sau ca „imediat” când anularea are efect pe loc — de asta stă pe rândul ei, într-o propoziție care funcționează în ambele situații.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at', 'billing_url'],
                    ],
                ],
            ],
            'subscription_renewed' => [
                [
                    'subject' => 'Abonamentul {{plan_name}} s-a reînnoit',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul s-a reînnoit</h1><p>Salut, {{user_name}},</p><p>Am încasat {{amount}} {{currency}} pentru abonamentul {{plan_name}}. Totul rămâne activ, nu trebuie să faci nimic.</p>',
                    'meta' => [
                        'label' => 'FACTURARE',
                        'preheader' => 'Plata a trecut, nu ai nimic de făcut.',
                        'cta_label' => null,
                        'cta_url' => null,
                        'fallback' => false,
                        'note' => 'Factura o găsești în secțiunea Facturare din cont.',
                        'description' => 'Trimis la fiecare reînnoire plătită. Nu are buton: locul de apel nu trimite niciun URL. Data următoarei reînnoiri poate ajunge ca „—”, deci textul nu o folosește.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal'],
                    ],
                ],
                [
                    'subject' => 'Abonamentul {{plan_name}} s-a reînnoit',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul s-a reînnoit</h1><p>Salut, {{user_name}},</p><p>Am încasat {{amount}} {{currency}} pentru abonamentul {{plan_name}}. Totul rămâne activ, nu trebuie să faci nimic.</p><p>Următoarea reînnoire: {{next_renewal}}</p>',
                    'meta' => [
                        'note' => 'Dacă suma nu ți se pare corectă, scrie-ne și o verificăm.',
                        'label' => 'FACTURARE',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Vezi facturile',
                        'preheader' => 'Plata a trecut, nu ai nimic de făcut.',
                        'description' => 'Trimis la fiecare reînnoire plătită. Data următoarei reînnoiri poate lipsi și atunci ajunge ca „—”, de aceea stă pe un rând al ei, fără punct la final.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal', 'billing_url'],
                    ],
                ],
            ],
            'support_ticket_created' => [
                [
                    'subject' => 'Am primit tichetul #{{ticket_id}}',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Am primit mesajul tău</h1><p>Salut, {{user_name}},</p><p>Ți-am înregistrat solicitarea „{{ticket_subject}}” cu numărul #{{ticket_id}}. O preia un coleg și primești răspunsul pe email.</p>',
                    'meta' => [
                        'label' => 'SUPORT',
                        'preheader' => 'Îți răspundem cât putem de repede.',
                        'cta_label' => 'Vezi tichetul',
                        'cta_url' => '{{ticket_url}}',
                        'fallback' => false,
                        'note' => 'Dacă vrei să adaugi ceva, răspunde direct în tichet.',
                        'description' => 'Trimis persoanei care a deschis tichetul. Poate ajunge și la o adresă fără cont, dacă tichetul a fost deschis de un administrator. Prioritatea ajunge în engleză, deci textul nu o folosește.',
                        'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                    ],
                ],
                [
                    'subject' => 'Am primit tichetul #{{ticket_id}}',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Am primit mesajul tău</h1><p>Salut, {{user_name}},</p><p>Ți-am înregistrat solicitarea „{{ticket_subject}}” cu numărul #{{ticket_id}}. O preia un coleg și primești răspunsul pe email.</p>',
                    'meta' => [
                        'note' => 'Dacă vrei să adaugi ceva, răspunde direct în tichet.',
                        'label' => 'SUPORT',
                        'cta_url' => '{{ticket_url}}',
                        'fallback' => false,
                        'cta_label' => 'Vezi tichetul',
                        'preheader' => 'Îți răspundem cât putem de repede.',
                        'description' => 'Trimis persoanei care a deschis tichetul. Poate ajunge și la o adresă fără cont, dacă tichetul a fost deschis de un administrator. Prioritatea ajunge tradusă („ridicată”, „urgentă”), dar textul nu o folosește: nu îi spune nimic celui care tocmai a scris.',
                        'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                    ],
                ],
            ],
            'support_ticket_status_changed' => [
                [
                    'subject' => 'Am actualizat tichetul #{{ticket_id}}',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Starea tichetului s-a schimbat</h1><p>Salut, {{user_name}},</p><p>Tichetul #{{ticket_id}} — „{{ticket_subject}}” — are acum starea {{new_status}}.</p>',
                    'meta' => [
                        'label' => 'SUPORT',
                        'preheader' => 'Tichetul „{{ticket_subject}}” a fost actualizat.',
                        'cta_label' => 'Vezi tichetul',
                        'cta_url' => '{{ticket_url}}',
                        'fallback' => false,
                        'note' => 'Dacă problema nu e rezolvată, răspunde în tichet și îl redeschidem.',
                        'description' => 'Trimis clientului când un administrator schimbă starea tichetului. Atenție: new_status ajunge în engleză (Open / In Progress / Closed), pentru că locul de apel nu îl traduce.',
                        'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'new_status', 'ticket_url'],
                    ],
                ],
            ],
            'payment_failed' => [
                [
                    'subject' => 'Plata nu a trecut — actualizează metoda de plată',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Plata nu a putut fi procesată</h1><p>Salut, {{user_name}},</p><p>Plata de {{amount}} {{currency}} pentru abonamentul tău {{app_name}} a fost refuzată. De obicei e un card expirat sau o blocare de la bancă. Actualizează metoda de plată ca să nu se întrerupă serviciul.</p>',
                    'meta' => [
                        'note' => 'Până se face plata, abonamentul rămâne marcat ca neachitat. Dacă ai nevoie de ajutor, scrie-ne.',
                        'label' => 'FACTURARE',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Actualizează metoda de plată',
                        'preheader' => 'Durează un minut să schimbi cardul.',
                        'description' => 'Trimis titularului abonamentului când o plată recurentă eșuează. Doar pe Stripe. Suma vine ca număr simplu, fără simbol, iar moneda ca un cod separat — se scriu întotdeauna împreună.',
                        'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                    ],
                ],
            ],
            'subscription_expired' => [
                [
                    'subject' => 'Abonamentul {{plan_name}} s-a încheiat',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău s-a încheiat</h1><p>Salut, {{user_name}},</p><p>Perioada ta pe planul {{plan_name}} s-a încheiat și abonamentul nu mai este activ. Reactivează-l ca să continui pe {{app_name}} fără întreruperi.</p>',
                    'meta' => [
                        'note' => 'Conversațiile, contactele și setările rămân salvate. Nu se șterge nimic.',
                        'label' => 'ABONAMENT',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Reactivează abonamentul',
                        'preheader' => 'Reactivezi în câteva minute, direct din cont.',
                        'description' => 'Trimis și când expiră un abonament plătit, și când se termină o perioadă de probă fără conversie. Textul trebuie să funcționeze în ambele situații.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_url'],
                    ],
                ],
            ],
            'plan_changed' => [
                [
                    'subject' => 'Planul tău a fost schimbat',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Planul tău a fost schimbat</h1><p>Salut, {{user_name}},</p><p>Abonamentul tău {{app_name}} a trecut de la {{old_plan}} la {{new_plan}}. Schimbarea este deja activă pe cont.</p>',
                    'meta' => [
                        'note' => 'Dacă schimbarea nu ți se pare corectă, scrie-ne și o verificăm.',
                        'label' => 'ABONAMENT',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Vezi detaliile',
                        'preheader' => 'Nu ai nimic de făcut — doar de știut.',
                        'description' => 'Trimis când planul unui abonament este schimbat. Textul rămâne neutru: nu știm dacă e trecere în sus sau în jos și nu primim nicio sumă.',
                        'placeholders' => ['app_name', 'user_name', 'old_plan', 'new_plan', 'billing_url'],
                    ],
                ],
            ],
            'trial_ending' => [
                [
                    'subject' => 'Perioada de probă {{plan_name}} se apropie de final',
                    'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Perioada de probă se apropie de final</h1><p>Salut, {{user_name}},</p><p>Perioada de probă pentru planul {{plan_name}} se apropie de final. Zile rămase: {{days_remaining}}.</p><p>Adaugă o metodă de plată ca să continui fără întrerupere.</p>',
                    'meta' => [
                        'note' => 'Dacă nu adaugi o metodă de plată, abonamentul nu pornește la finalul perioadei de probă. Datele rămân salvate.',
                        'label' => 'ABONAMENT',
                        'cta_url' => '{{billing_url}}',
                        'fallback' => false,
                        'cta_label' => 'Adaugă metoda de plată',
                        'preheader' => 'Adaugă o metodă de plată ca să nu se întrerupă nimic.',
                        'description' => 'Trimis o singură dată, înainte ca perioada de probă să se termine. Numărul de zile este scris ca „zile rămase: N”, pentru că valoarea poate fi 1 și acordul obișnuit ar fi greșit.',
                        'placeholders' => ['app_name', 'user_name', 'plan_name', 'days_remaining', 'trial_ends_at', 'billing_url'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Write every template row, without ever stepping on an admin's words.
     *
     * A missing row is created. An existing row is rewritten in full only while its
     * `subject` and `content` still hold the exact English factory text. The moment an
     * admin has edited either one, the row's copy — and the chrome that renders around
     * it — is left alone.
     *
     * The seeder and the data migration both call this, so a fresh install and an existing
     * one can never end up with different templates.
     */
    public static function sync(): void
    {
        foreach (self::templates() as $definition) {
            $existing = Template::where('slug', $definition['slug'])->where('type', 'email')->first();

            if (! $existing) {
                Template::create(self::persistable($definition));

                continue;
            }

            if (self::holdsText($existing, $definition['legacy'])) {
                $attributes = [
                    'name' => $definition['name'],
                    'subject' => $definition['subject'],
                    'content' => $definition['content'],
                    'meta' => $definition['meta'],
                ];
            } else {
                $attributes = ['meta' => self::documentationMeta($existing, $definition['meta'])];
            }

            // A template nothing sends is machine-owned the way `meta` is. The factory
            // seeder created every row enabled, so without this the two slugs that have
            // no call site stay switched on while their own description says they are
            // inactive. It can only ever switch one off, never on.
            if ($definition['enabled'] === false) {
                $attributes['enabled'] = false;
            }

            $existing->update($attributes);
        }
    }

    /**
     * The meta to store on a row whose copy belongs to the admin.
     *
     * `description` and `placeholders` are documentation for /admin/email-system and are
     * always ours to refresh. The structural keys are not: `label`, `preheader`,
     * `cta_label`, `cta_url`, `fallback` and `note` all render around the body, and the
     * body here is not ours. Bolting them onto somebody else's English copy produces a
     * Romanian button under their own inline link and a Romanian note over English prose,
     * so whatever chrome the row already carries is left exactly as it is.
     *
     * Public because the data migrations apply the same rule outside sync().
     *
     * @param  array<string, mixed>  $definitionMeta
     * @return array<string, mixed>
     */
    public static function documentationMeta(Template $existing, array $definitionMeta): array
    {
        $stored = $existing->meta;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        $stored = is_array($stored) ? $stored : [];

        $stored['description'] = $definitionMeta['description'] ?? null;
        $stored['placeholders'] = $definitionMeta['placeholders'] ?? [];

        return $stored;
    }

    /**
     * Does this row still hold exactly the given subject and content, character for character?
     *
     * `sync()` asks it about the English factory text, to decide whether the row is still
     * untouched; the data migration's down() asks it about the Romanian text, to decide
     * whether the row is still ours to roll back.
     *
     * Only `subject` and `content` are read: those are the two columns an admin edits, and
     * a reference that carries more (a whole definition, say) is accepted unchanged.
     *
     * @param  array{subject: string, content: string, ...}  $reference
     */
    public static function holdsText(Template $template, array $reference): bool
    {
        return trim((string) $template->subject) === trim((string) $reference['subject'])
            && trim((string) $template->content) === trim((string) $reference['content']);
    }

    /**
     * Strip the bookkeeping keys that are not columns on `templates`.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function persistable(array $definition): array
    {
        unset($definition['legacy']);

        return $definition;
    }
}
