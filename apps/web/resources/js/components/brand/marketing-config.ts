import type { MarketingConfig } from '@/components/marketing/types';
import { endpoints } from '@/lib/endpoints';

export const marketingConfig: MarketingConfig = {
    nav: [
        { label: 'marketing.nav.how', href: '/#how', anchor: true },
        { label: 'marketing.nav.cleaning', href: '/#pack', anchor: true },
        { label: 'marketing.nav.pricing', href: endpoints.pricing() },
        { label: 'marketing.nav.security', href: endpoints.security() },
    ],
    footer: [
        {
            title: 'marketing.footer.product',
            links: [
                { label: 'marketing.nav.how', href: '/#how', anchor: true },
                {
                    label: 'marketing.nav.cleaning',
                    href: '/#pack',
                    anchor: true,
                },
                { label: 'marketing.nav.pricing', href: endpoints.pricing() },
                { label: 'marketing.nav.faq', href: '/#faq', anchor: true },
            ],
        },
        {
            title: 'marketing.footer.trust',
            links: [
                {
                    label: 'marketing.footer.security',
                    href: endpoints.security(),
                },
                { label: 'ui.legal.docs.ai', href: endpoints.legal('ai') },
                {
                    label: 'ui.legal.docs.subprocessors',
                    href: endpoints.legal('subprocessors'),
                },
            ],
        },
        {
            title: 'marketing.footer.legal',
            links: [
                {
                    label: 'marketing.footer.terms',
                    href: endpoints.legal('terms'),
                },
                {
                    label: 'marketing.footer.privacy',
                    href: endpoints.legal('privacy'),
                },
                {
                    label: 'ui.legal.docs.cookies',
                    href: endpoints.legal('cookies'),
                },
                {
                    label: 'marketing.footer.dpa',
                    href: endpoints.legal('dpa'),
                },
                { label: 'ui.legal.docs.aup', href: endpoints.legal('aup') },
                {
                    label: 'marketing.footer.employee_notice',
                    href: endpoints.legal('employee-notice'),
                },
                {
                    label: 'marketing.footer.all_legal',
                    href: endpoints.legalPages.index(),
                },
            ],
        },
    ],
};
