import InputError from '@/components/input-error';
import LiveBadge from '@/components/live-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useReloadOnReconnect } from '@/hooks/use-reload-on-reconnect';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type BranchRef, type ConsoleTicket } from '@/types/antre';
import { Head, router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { Check, ExternalLink, PhoneCall, Star, UserX, Volume2 } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface Props {
    counter: { id: number; label: string };
    branch: BranchRef;
    services: { code: string; name: string }[];
    current: ConsoleTicket | null;
    waiting: ConsoleTicket[];
    waiting_count: number;
    served_today: number;
    bookings: { order_id: string; customer: string; time: string; service: string }[];
}

function Elapsed({ since }: { since: string | null }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);
    if (!since) return null;
    const seconds = Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000));
    return (
        <span className="tabular-nums">
            {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
        </span>
    );
}

export default function ConsoleShow({ counter, branch, services, current, waiting, waiting_count, served_today, bookings }: Props) {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [busy, setBusy] = useState(false);
    const [reference, setReference] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Loket', href: '/console' },
        { title: counter.label, href: `/console/counters/${counter.id}` },
    ];

    // Another counter or a customer changed the queue: fetch fresh lists.
    useEcho(
        `staff.branch.${branch.id}`,
        '.staff.queue.updated',
        () => {
            router.reload({ only: ['current', 'waiting', 'waiting_count', 'served_today', 'bookings'] });
        },
        [branch.id],
    );

    useReloadOnReconnect(['current', 'waiting', 'waiting_count', 'served_today', 'bookings']);

    const act = (url: string) => {
        setBusy(true);
        router.post(url, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const checkIn = (value: string, event?: FormEvent) => {
        event?.preventDefault();
        router.post('/console/check-in', { reference: value }, { preserveScroll: true, onSuccess: () => setReference('') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={counter.label} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{counter.label}</h1>
                        <p className="text-muted-foreground text-sm">
                            {branch.name}, melayani {services.map((s) => `${s.code} ${s.name}`).join(', ')}
                        </p>
                    </div>
                    <div className="flex items-center gap-4">
                        <LiveBadge />
                        <a
                            href={`/display/${branch.slug}`}
                            target="_blank"
                            rel="noreferrer"
                            className="text-primary inline-flex items-center gap-1 text-sm hover:underline"
                        >
                            Layar TV <ExternalLink className="size-3.5" />
                        </a>
                    </div>
                </div>

                {flash.status && (
                    <p className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                        {flash.status}
                    </p>
                )}
                {(errors.queue || errors.reference) && (
                    <p
                        className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
                        role="alert"
                    >
                        {errors.queue ?? errors.reference}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <section className="bg-card rounded-2xl border p-6" aria-label="Nomor saat ini">
                        <p className="text-muted-foreground text-sm">Sedang dilayani</p>

                        {current ? (
                            <div data-testid="current-ticket">
                                <p className="mt-1 text-7xl font-semibold tracking-tight tabular-nums" data-testid="current-code">
                                    {current.code}
                                </p>
                                <p className="text-muted-foreground mt-1 flex items-center gap-2 text-sm">
                                    {current.service}
                                    {current.is_priority && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                            <Star className="size-3" /> Booking
                                        </span>
                                    )}
                                    <span aria-hidden>·</span>
                                    dipanggil {current.call_count}x, <Elapsed since={current.called_at} />
                                </p>
                                <div className="mt-6 flex flex-wrap gap-3">
                                    <Button size="lg" disabled={busy} onClick={() => act(`/console/tickets/${current.id}/done`)} data-testid="done">
                                        <Check /> Selesai
                                    </Button>
                                    <Button
                                        size="lg"
                                        variant="secondary"
                                        disabled={busy}
                                        onClick={() => act(`/console/tickets/${current.id}/recall`)}
                                        data-testid="recall"
                                    >
                                        <Volume2 /> Panggil ulang
                                    </Button>
                                    <Button
                                        size="lg"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => act(`/console/tickets/${current.id}/skip`)}
                                        data-testid="skip"
                                    >
                                        <UserX /> Tidak hadir
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <p className="mt-2 text-4xl font-semibold text-slate-300 dark:text-slate-700" data-testid="no-current">
                                ---
                            </p>
                        )}

                        <div className={cn('mt-6 border-t pt-6', current && 'opacity-60')}>
                            <Button
                                size="lg"
                                className="w-full sm:w-auto"
                                disabled={busy || current !== null || waiting_count === 0}
                                onClick={() => act(`/console/counters/${counter.id}/call-next`)}
                                data-testid="call-next"
                            >
                                <PhoneCall /> Panggil berikutnya
                            </Button>
                            <p className="text-muted-foreground mt-2 text-xs">
                                {current
                                    ? 'Selesaikan atau tandai nomor ini dulu.'
                                    : waiting_count === 0
                                      ? 'Belum ada yang menunggu.'
                                      : `${waiting_count} menunggu untuk loket ini.`}
                            </p>
                        </div>
                    </section>

                    <section className="bg-card rounded-2xl border p-6" aria-label="Antrean">
                        <div className="flex items-baseline justify-between">
                            <h2 className="font-medium">Berikutnya</h2>
                            <p className="text-muted-foreground text-sm">Selesai hari ini: {served_today}</p>
                        </div>
                        <ol className="mt-3 divide-y" data-testid="waiting-list">
                            {waiting.length === 0 && <li className="text-muted-foreground py-3 text-sm">Antrean kosong.</li>}
                            {waiting.map((ticket) => (
                                <li key={ticket.id} className="flex items-center justify-between py-2.5">
                                    <span className="text-xl font-semibold tabular-nums">{ticket.code}</span>
                                    <span className="text-muted-foreground flex items-center gap-2 text-sm">
                                        {ticket.is_priority && <Star className="size-3.5 text-amber-500" aria-label="Booking" />}
                                        {ticket.service}
                                    </span>
                                </li>
                            ))}
                        </ol>
                        {waiting_count > waiting.length && (
                            <p className="text-muted-foreground mt-2 text-xs">dan {waiting_count - waiting.length} lainnya</p>
                        )}
                    </section>
                </div>

                <section className="bg-card rounded-2xl border p-6" aria-label="Check-in booking">
                    <h2 className="font-medium">Check-in booking</h2>
                    <p className="text-muted-foreground mt-1 text-sm">Booking yang sudah dibayar menjadi nomor prioritas di antrean.</p>

                    <form className="mt-4 flex gap-2" onSubmit={(e) => checkIn(reference, e)}>
                        <Input
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                            placeholder="Kode booking, misalnya ANT-AB12CD34EF"
                            className="max-w-xs"
                            aria-label="Kode booking"
                        />
                        <Button type="submit" variant="secondary" disabled={!reference.trim()}>
                            Check-in
                        </Button>
                    </form>
                    <InputError className="mt-2" message={errors.reference} />

                    {bookings.length > 0 && (
                        <ul className="mt-4 divide-y" data-testid="paid-bookings">
                            {bookings.map((booking) => (
                                <li key={booking.order_id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                    <span>
                                        <span className="font-medium tabular-nums">{booking.time}</span> {booking.customer}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {booking.service} · {booking.order_id}
                                        </span>
                                    </span>
                                    <Button size="sm" variant="outline" onClick={() => checkIn(booking.order_id)}>
                                        Check-in
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
