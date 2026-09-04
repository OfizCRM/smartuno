import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import { Badge, Card } from '@/Components/ui';
import {
    Building2, Users, CreditCard, Layers, SlidersHorizontal, Bell, UserCircle,
    MessageSquare, Share2, Smartphone, Mail, ShoppingBag, Package, Bot,
    Zap, FileText, Tag, MessageCircle, Code2, ScrollText, Download, ExternalLink,
    ChevronRight, KeyRound,
} from 'lucide-react';

function safeRoute(name, ...args) {
    try { return route(name, ...args); } catch { return null; }
}

/**
 * One row of the hub. Renders as a real anchor when `external`, since an
 * off-site URL routed through an Inertia <Link> is fetched as a page and fails.
 */
function Row({ icon: Icon, title, description, chip, href, external = false }) {
    if (!href) return null;

    const body = (
        <>
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300">
                <Icon className="h-4.5 w-4.5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-[14px] font-medium text-neutral-900 dark:text-white">{title}</span>
                {description && (
                    <span className="mt-0.5 block text-[13px] text-ink-muted dark:text-neutral-400">{description}</span>
                )}
            </span>
            {chip && <span className="ml-auto shrink-0 self-center">{chip}</span>}
            {external
                ? <ExternalLink className="ml-2 h-4 w-4 shrink-0 self-center text-neutral-300 dark:text-neutral-600" />
                : <ChevronRight className="ml-2 h-4 w-4 shrink-0 self-center text-neutral-300 dark:text-neutral-600" />}
        </>
    );

    const className = 'flex items-start gap-3 rounded-xl px-3 py-3 -mx-1 transition-colors duration-150 hover:bg-sidebar-hover dark:hover:bg-white/5';

    return external
        ? <a href={href} target="_blank" rel="noopener noreferrer" className={className}>{body}</a>
        : <Link href={href} className={className}>{body}</Link>;
}

function Section({ title, children }) {
    return (
        <Card>
            <Card.Header title={title} />
            <Card.Body className="divide-y divide-warm-border dark:divide-white/5">
                {children}
            </Card.Body>
        </Card>
    );
}

/**
 * The settings hub.
 *
 * A cards index rather than tabs, and the reason is in the code rather than in
 * taste: the Tabs primitive is uncontrolled with no `?tab=` convention anywhere
 * in this app, so every server redirect would land on the first tab; it has no
 * overflow container, so six Romanian labels scroll the page sideways on a
 * phone; and over half of what lives here (Team, Audit log, Billing) keeps its
 * state in the URL through paginators and filters. Rows linking to real routes
 * cost nothing, deep-link, and survive the back button.
 */
export default function SettingsHub({ isAdmin = false, status = {} }) {
    const { t } = useTranslation();
    const { branding = {} } = usePage().props;
    const docsUrl = branding?.docs_url;

    const count = (n, key) => (n > 0
        ? <Badge variant="success" size="sm">{t(key, { count: n })}</Badge>
        : <Badge variant="default" size="sm">{t('settings_hub.chip_not_set')}</Badge>);

    const yesNo = (on) => (on
        ? <Badge variant="success" size="sm">{t('settings_hub.chip_configured')}</Badge>
        : <Badge variant="default" size="sm">{t('settings_hub.chip_not_set')}</Badge>);

    return (
        <ClientLayout title={t('settings_hub.title')}>
            <Head title={t('settings_hub.title')} />

            <div className="mx-auto max-w-3xl space-y-5">
                <div>
                    <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">{t('settings_hub.title')}</h1>
                    <p className="mt-1 text-sm text-ink-muted dark:text-neutral-400">{t('settings_hub.subtitle')}</p>
                </div>

                {isAdmin && (
                    <Section title={t('settings_hub.company')}>
                        <Row icon={Building2} href={safeRoute('client.settings.company')}
                            title={t('settings_hub.company_details')} description={t('settings_hub.company_details_desc')} />
                        <Row icon={Users} href={safeRoute('client.team.index')}
                            title={t('settings_hub.team')} description={t('settings_hub.team_desc')}
                            chip={count(status.team, 'settings_hub.chip_members')} />
                        <Row icon={CreditCard} href={safeRoute('client.subscription.show')}
                            title={t('settings_hub.subscription')} description={t('settings_hub.subscription_desc')} />
                        {/* A firm with one workspace never needs this screen, and the
                            switcher in the top bar covers the case where it has more. */}
                        {status.workspaces > 1 && (
                            <Row icon={Layers} href={safeRoute('client.workspaces.index')}
                                title={t('settings_hub.workspaces')} description={t('settings_hub.workspaces_desc')} />
                        )}
                    </Section>
                )}

                <Section title={t('settings_hub.preferences')}>
                    <Row icon={SlidersHorizontal} href={safeRoute('client.settings.preferences')}
                        title={t('settings_hub.my_preferences')} description={t('settings_hub.my_preferences_desc')} />
                    <Row icon={Bell} href={safeRoute('client.settings.notifications')}
                        title={t('settings_hub.notifications')} description={t('settings_hub.notifications_desc')} />
                    <Row icon={UserCircle} href={safeRoute('client.profile.edit')}
                        title={t('settings_hub.my_account')} description={t('settings_hub.my_account_desc')} />
                </Section>

                <Section title={t('settings_hub.channels')}>
                    <Row icon={MessageSquare} href={safeRoute('client.inbox.setup')}
                        title={t('settings_hub.messaging')} description={t('settings_hub.messaging_desc')}
                        chip={count(status.channels, 'settings_hub.chip_connected')} />
                    <Row icon={Share2} href={safeRoute('client.social.accounts.index')}
                        title={t('settings_hub.social_accounts')} description={t('settings_hub.social_accounts_desc')}
                        chip={count(status.social, 'settings_hub.chip_connected')} />
                    <Row icon={Smartphone} href={safeRoute('client.sms-gateways.index')}
                        title={t('settings_hub.sms')} description={t('settings_hub.sms_desc')} chip={yesNo(status.sms)} />
                    <Row icon={Mail} href={safeRoute('client.email-server.index')}
                        title={t('settings_hub.email')} description={t('settings_hub.email_desc')} chip={yesNo(status.email)} />
                </Section>

                <Section title={t('settings_hub.shop')}>
                    <Row icon={ShoppingBag} href={safeRoute('client.ecommerce.stores.index')}
                        title={t('settings_hub.stores')} description={t('settings_hub.stores_desc')}
                        chip={count(status.stores, 'settings_hub.chip_connected')} />
                    {status.stores > 0 && (
                        <Row icon={Package} href={safeRoute('client.ecommerce.orders.index')}
                            title={t('settings_hub.orders')} description={t('settings_hub.orders_desc')} />
                    )}
                </Section>

                <Section title={t('settings_hub.inbox')}>
                    <Row icon={Bot} href={safeRoute('client.ai.chatbots.index')}
                        title={t('settings_hub.chatbots')} description={t('settings_hub.chatbots_desc')} />
                    <Row icon={Zap} href={safeRoute('client.whatsapp.auto-replies.index')}
                        title={t('settings_hub.auto_replies')} description={t('settings_hub.auto_replies_desc')} />
                    <Row icon={FileText} href={safeRoute('client.whatsapp.templates.index')}
                        title={t('settings_hub.templates')} description={t('settings_hub.templates_desc')} />
                    <Row icon={Tag} href={safeRoute('client.inbox.labels.index')}
                        title={t('settings_hub.labels')} description={t('settings_hub.labels_desc')} />
                    <Row icon={MessageCircle} href={safeRoute('client.inbox.canned-replies.index')}
                        title={t('settings_hub.canned_replies')} description={t('settings_hub.canned_replies_desc')} />
                    <Row icon={Code2} href={safeRoute('client.whatsapp.widget.index')}
                        title={t('settings_hub.chat_widget')} description={t('settings_hub.chat_widget_desc')} />
                </Section>

                {/* Collapsed by design: a three-person firm should be able to reach the
                    end of this page without reading four rows it will never open.
                    A native <details> needs no state and no primitive. */}
                <details className="group rounded-2xl border border-warm-border bg-warm-card shadow-card dark:border-white/8 dark:bg-neutral-800/50">
                    <summary className="flex cursor-pointer list-none items-center justify-between px-5 py-4 text-[15px] font-semibold text-neutral-900 dark:text-white">
                        {t('settings_hub.advanced')}
                        <ChevronRight className="h-4 w-4 text-neutral-400 transition-transform duration-200 group-open:rotate-90" />
                    </summary>
                    <div className="divide-y divide-warm-border px-5 pb-4 dark:divide-white/5">
                        <Row icon={KeyRound} href={safeRoute('client.ai.providers.index')}
                            title={t('settings_hub.ai_keys')} description={t('settings_hub.ai_keys_desc')} />
                        <Row icon={Code2} href={safeRoute('client.api-tokens.index')}
                            title={t('settings_hub.developers')} description={t('settings_hub.developers_desc')} />
                        {isAdmin && (
                            <Row icon={ScrollText} href={safeRoute('client.audit-log.index')}
                                title={t('settings_hub.audit_log')} description={t('settings_hub.audit_log_desc')} />
                        )}
                        {isAdmin && (
                            <Row icon={Download} href={safeRoute('client.settings.data-export')}
                                title={t('settings_hub.data_export')} description={t('settings_hub.data_export_desc')} />
                        )}
                    </div>
                </details>

                {docsUrl && (
                    <Section title={t('settings_hub.help')}>
                        <Row icon={ExternalLink} href={docsUrl} external
                            title={t('settings_hub.docs')} description={t('settings_hub.docs_desc')} />
                    </Section>
                )}
            </div>
        </ClientLayout>
    );
}
