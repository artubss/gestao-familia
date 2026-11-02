import { Head, Link, router, useForm } from '@inertiajs/react';
import { Landmark, Plus, RefreshCw } from 'lucide-react';
import { FormEvent } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

type Member = { id: number; name: string };
type Fixed = {
    id: number;
    name: string;
    amount: number;
    currency: string;
    day: number;
    method: string;
    payer: string | null;
    posted: boolean;
    active: boolean;
};
type Connection = {
    id: number;
    label: string;
    connector: string | null;
    owner: string | null;
    status: string | null;
    lastSynced: string | null;
};

declare global {
    interface Window {
        PluggyConnect?: new (options: {
            connectToken: string;
            includeSandbox?: boolean;
            onSuccess: (data: { item: { id: string } }) => void;
        }) => { init: () => void };
    }
}

function csrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Household({
    members,
    currencies,
    defaultCurrency,
    methods,
    pluggyConfigured,
    pluggySandbox,
    fixed,
    connections,
}: {
    members: Member[];
    currencies: string[];
    defaultCurrency: string;
    methods: string[];
    pluggyConfigured: boolean;
    pluggySandbox: boolean;
    fixed: Fixed[];
    connections: Connection[];
}) {
    const t = useT();
    const first = members[0]?.id ?? '';
    const bill = useForm({ name: '', amount: '', currency: defaultCurrency, day_of_month: '5', payment_method: 'pix', payer_id: String(first) });
    const manual = useForm({
        name: '',
        amount: '',
        currency: defaultCurrency,
        payment_method: 'pix',
        payer_id: String(first),
        spent_at: new Date().toISOString().slice(0, 10),
    });
    const link = useForm({ label: '', user_id: String(first), item_id: '' });

    const connect = async (e: FormEvent) => {
        e.preventDefault();
        const response = await fetch(route('spending.household.banks.token'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
            body: JSON.stringify({ user_id: link.data.user_id }),
        });
        if (!response.ok) return;
        const body = (await response.json()) as { token: string; sandbox: boolean };
        if (!window.PluggyConnect) {
            await new Promise<void>((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://cdn.pluggy.ai/pluggy-connect/latest/pluggy-connect.js';
                script.onload = () => resolve();
                script.onerror = () => reject();
                document.body.appendChild(script);
            });
        }
        const widget = new window.PluggyConnect!({
            connectToken: body.token,
            includeSandbox: body.sandbox,
            onSuccess: (data) => {
                const item = (data as { item?: { id: string }; id?: string }).item?.id ?? (data as { id?: string }).id;
                if (!item) return;
                link.transform((form) => ({ ...form, item_id: item }));
                link.post(route('spending.household.banks.store'), { preserveScroll: true });
            },
        });
        widget.init();
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.spending'), href: route('spending.index') }, { title: t('spending.household_title'), href: route('spending.household') }]}>
            <Head title={t('spending.household_title')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('spending.household_title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('spending.household_sub')}</p>
                </div>

                <section className="flex flex-col gap-3">
                    <h2 className="font-heading text-lg">{t('spending.fixed_title')}</h2>
                    {fixed.length === 0 && <p className="text-muted-foreground text-sm">{t('spending.fixed_empty')}</p>}
                    <ul className="flex flex-col gap-2">
                        {fixed.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                                <div className="min-w-0">
                                    <p className="truncate font-medium">{row.name}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {money(row.amount, row.currency)} · {t(`spending.method_${row.method}`)} · {row.payer} · {t('spending.fixed_day')} {row.day}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={row.posted}
                                        onClick={() => router.post(route('spending.household.fixed.post', row.id), {}, { preserveScroll: true })}
                                    >
                                        {row.posted ? t('spending.fixed_posted') : t('spending.fixed_post')}
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => router.delete(route('spending.household.fixed.destroy', row.id), { preserveScroll: true })}>
                                        {t('spending.fixed_remove')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="grid gap-3 sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            bill.post(route('spending.household.fixed.store'), { preserveScroll: true, onSuccess: () => bill.reset('name', 'amount') });
                        }}
                    >
                        <div className="sm:col-span-2">
                            <Label htmlFor="fixed-name">{t('spending.fixed_name')}</Label>
                            <Input id="fixed-name" value={bill.data.name} onChange={(e) => bill.setData('name', e.target.value)} required />
                            <InputError message={bill.errors.name} />
                        </div>
                        <div>
                            <Label htmlFor="fixed-amount">{t('spending.fixed_amount')}</Label>
                            <Input id="fixed-amount" inputMode="decimal" value={bill.data.amount} onChange={(e) => bill.setData('amount', e.target.value)} required />
                        </div>
                        <div>
                            <Label htmlFor="fixed-currency">{t('spending.default_currency')}</Label>
                            <select id="fixed-currency" className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm" value={bill.data.currency} onChange={(e) => bill.setData('currency', e.target.value)}>
                                {currencies.map((code) => (
                                    <option key={code}>{code}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="fixed-day">{t('spending.fixed_day')}</Label>
                            <Input id="fixed-day" inputMode="numeric" value={bill.data.day_of_month} onChange={(e) => bill.setData('day_of_month', e.target.value)} required />
                        </div>
                        <div>
                            <Label htmlFor="fixed-method">{t('spending.fixed_method')}</Label>
                            <select id="fixed-method" className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm" value={bill.data.payment_method} onChange={(e) => bill.setData('payment_method', e.target.value)}>
                                {methods.map((method) => (
                                    <option key={method} value={method}>
                                        {t(`spending.method_${method}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="sm:col-span-2">
                            <Label htmlFor="fixed-payer">{t('spending.fixed_payer')}</Label>
                            <select id="fixed-payer" className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm" value={bill.data.payer_id} onChange={(e) => bill.setData('payer_id', e.target.value)}>
                                {members.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit" className="sm:col-span-2" disabled={bill.processing}>
                            <Plus className="size-4" /> {t('spending.fixed_add')}
                        </Button>
                    </form>
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="font-heading text-lg">{t('spending.pix_title')}</h2>
                    <p className="text-muted-foreground text-sm">{t('spending.pix_sub')}</p>
                    <form
                        className="grid gap-3 sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            manual.post(route('spending.household.manual'), { preserveScroll: true, onSuccess: () => manual.reset('name', 'amount') });
                        }}
                    >
                        <div className="sm:col-span-2">
                            <Label htmlFor="manual-name">{t('spending.fixed_name')}</Label>
                            <Input id="manual-name" value={manual.data.name} onChange={(e) => manual.setData('name', e.target.value)} required />
                        </div>
                        <div>
                            <Label htmlFor="manual-amount">{t('spending.fixed_amount')}</Label>
                            <Input id="manual-amount" inputMode="decimal" value={manual.data.amount} onChange={(e) => manual.setData('amount', e.target.value)} required />
                        </div>
                        <div>
                            <Label htmlFor="manual-method">{t('spending.fixed_method')}</Label>
                            <select id="manual-method" className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm" value={manual.data.payment_method} onChange={(e) => manual.setData('payment_method', e.target.value)}>
                                {methods.map((method) => (
                                    <option key={method} value={method}>
                                        {t(`spending.method_${method}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="manual-date">{t('spending.date')}</Label>
                            <Input id="manual-date" type="date" value={manual.data.spent_at} onChange={(e) => manual.setData('spent_at', e.target.value)} required />
                        </div>
                        <div>
                            <Label htmlFor="manual-payer">{t('spending.fixed_payer')}</Label>
                            <select id="manual-payer" className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm" value={manual.data.payer_id} onChange={(e) => manual.setData('payer_id', e.target.value)}>
                                {members.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit" className="sm:col-span-2" disabled={manual.processing}>
                            {t('spending.pix_add')}
                        </Button>
                    </form>
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="font-heading flex items-center gap-2 text-lg">
                        <Landmark className="size-5" /> {t('spending.banks_title')}
                    </h2>
                    <p className="text-muted-foreground text-sm">{t('spending.banks_sub')}</p>
                    <p className="text-muted-foreground text-sm">{t('spending.banks_free')}</p>
                    {!pluggyConfigured && <p className="text-sm">{t('spending.banks_missing')}</p>}
                    {pluggySandbox && <p className="text-sm">{t('spending.banks_sandbox')}</p>}
                    <ul className="flex flex-col gap-2">
                        {connections.length === 0 && <li className="text-muted-foreground text-sm">{t('spending.banks_empty')}</li>}
                        {connections.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                                <div className="min-w-0">
                                    <p className="truncate font-medium">{row.label}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {row.connector ?? '—'} · {row.owner} · {row.status ?? '—'}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button type="button" size="sm" variant="outline" onClick={() => router.post(route('spending.household.banks.sync', row.id), {}, { preserveScroll: true })}>
                                        <RefreshCw className="size-4" /> {t('spending.banks_sync')}
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => router.delete(route('spending.household.banks.destroy', row.id), { preserveScroll: true })}>
                                        {t('spending.fixed_remove')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {pluggyConfigured && (
                        <form className="grid gap-3 sm:grid-cols-2" onSubmit={connect}>
                            <div className="sm:col-span-2">
                                <Label htmlFor="bank-label">{t('spending.banks_label')}</Label>
                                <Input id="bank-label" value={link.data.label} onChange={(e) => link.setData('label', e.target.value)} placeholder={t('spending.banks_label_ph')} required />
                            </div>
                            <div className="sm:col-span-2">
                                <Label htmlFor="bank-owner">{t('spending.banks_owner')}</Label>
                                <select id="bank-owner" className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm" value={link.data.user_id} onChange={(e) => link.setData('user_id', e.target.value)}>
                                    {members.map((member) => (
                                        <option key={member.id} value={member.id}>
                                            {member.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <Button type="submit" className="sm:col-span-2" disabled={link.processing || link.data.label.trim() === ''}>
                                {t('spending.banks_connect')}
                            </Button>
                        </form>
                    )}
                    <Link href={route('spending.index')} className="text-sm underline">
                        {t('nav.spending')}
                    </Link>
                </section>
            </div>
        </AppLayout>
    );
}
