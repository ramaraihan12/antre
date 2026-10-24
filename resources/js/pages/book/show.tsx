import InputError from '@/components/input-error';
import LiveBadge from '@/components/live-badge';
import { Button } from '@/components/ui/button';
import { useReloadOnReconnect } from '@/hooks/use-reload-on-reconnect';
import PublicLayout from '@/layouts/public-layout';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

interface Props {
    booking: {
        public_id: string;
        order_id: string;
        status: 'pending_payment' | 'paid' | 'checked_in' | 'expired' | 'cancelled';
        status_label: string;
        service: string;
        branch: string;
        customer: string;
        deposit: number;
        date: string;
        time: string;
        expires_at: string;
        ticket_public_id: string | null;
    };
    payment: { driver: 'midtrans' | 'fake'; client_key: string | null; snap_url: string; fake_available: boolean };
}

declare global {
    interface Window {
        snap?: { pay: (token: string, options?: Record<string, unknown>) => void };
    }
}

function loadSnap(url: string, clientKey: string | null): Promise<void> {
    return new Promise((resolve, reject) => {
        if (window.snap) return resolve();
        const script = document.createElement('script');
        script.src = url;
        if (clientKey) script.setAttribute('data-client-key', clientKey);
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Snap tidak bisa dimuat'));
        document.head.appendChild(script);
    });
}

function Countdown({ until, onEnd }: { until: string; onEnd?: () => void }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);
    const left = Math.max(0, Math.floor((new Date(until).getTime() - now) / 1000));
    useEffect(() => {
        if (left === 0) onEnd?.();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [left === 0]);
    return (
        <span className="tabular-nums" data-testid="countdown">
            {Math.floor(left / 60)}:{String(left % 60).padStart(2, '0')}
        </span>
    );
}

const tone: Record<Props['booking']['status'], string> = {
    pending_payment: 'bg-amber-100 text-amber-900',
    paid: 'bg-emerald-100 text-emerald-900',
    checked_in: 'bg-sky-100 text-sky-900',
    expired: 'bg-slate-200 text-slate-700',
    cancelled: 'bg-slate-200 text-slate-700',
};

export default function BookShow({ booking, payment }: Props) {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [busy, setBusy] = useState(false);

    useReloadOnReconnect(['booking']);
    useEchoPublic(`booking.${booking.public_id}`, '.booking.changed', () => router.reload(), [booking.public_id]);

    // The server answers "pay" with a Snap token in the flash data; open the Snap popup with it.
    useEffect(() => {
        if (!flash.snap_token || payment.driver !== 'midtrans') return;
        loadSnap(payment.snap_url, payment.client_key)
            .then(() =>
                window.snap?.pay(flash.snap_token!, {
                    onSuccess: () => router.reload(),
                    onPending: () => router.reload(),
                    onClose: () => setBusy(false),
                }),
            )
            .catch(() => setBusy(false));
    }, [flash.snap_token, payment.driver, payment.snap_url, payment.client_key]);

    const pending = booking.status === 'pending_payment';

    return (
        <PublicLayout branch={booking.branch}>
            <Head title={`Booking ${booking.order_id}`} />

            <div className="bg-background rounded-2xl border p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-sm">{booking.service}</p>
                        <p className="mt-1 text-xl font-semibold">{booking.date}</p>
                        <p className="text-4xl font-semibold tracking-tight tabular-nums">{booking.time}</p>
                    </div>
                    <span
                        className={cn('rounded-full px-3 py-1 text-xs font-medium', tone[booking.status])}
                        data-testid="booking-status"
                        data-status={booking.status}
                    >
                        {booking.status_label}
                    </span>
                </div>

                <dl className="mt-5 grid grid-cols-2 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Atas nama</dt>
                    <dd className="text-right">{booking.customer}</dd>
                    <dt className="text-muted-foreground">Deposit</dt>
                    <dd className="text-right">{rupiah(booking.deposit)}</dd>
                    <dt className="text-muted-foreground">Kode booking</dt>
                    <dd className="text-right font-mono" data-testid="order-id">
                        {booking.order_id}
                    </dd>
                </dl>

                {pending && (
                    <div className="mt-6 space-y-3">
                        <p className="text-sm">
                            Slot ditahan sampai <Countdown until={booking.expires_at} onEnd={() => window.setTimeout(() => router.reload(), 1500)} />.
                            Setelah itu dilepas dan Anda perlu memesan ulang.
                        </p>

                        {payment.driver === 'midtrans' && (
                            <Button
                                size="lg"
                                className="w-full"
                                disabled={busy}
                                onClick={() => {
                                    setBusy(true);
                                    router.post(`/b/${booking.public_id}/pay`, {}, { preserveScroll: true, onError: () => setBusy(false) });
                                }}
                            >
                                Bayar {rupiah(booking.deposit)}
                            </Button>
                        )}

                        {payment.driver === 'fake' && payment.fake_available && (
                            <div className="rounded-lg border border-dashed border-amber-400 bg-amber-50 p-3 dark:bg-amber-950/40">
                                <p className="text-xs font-medium text-amber-900 dark:text-amber-200">Pembayaran simulasi, hanya untuk lokal</p>
                                <p className="mt-1 text-xs text-amber-900/80 dark:text-amber-200/80">
                                    Tidak ada kunci Midtrans di server ini. Tombol ini mengirim notifikasi bertanda tangan ke kode webhook yang sama
                                    dengan Midtrans.
                                </p>
                                <Button
                                    size="lg"
                                    className="mt-3 w-full"
                                    disabled={busy}
                                    data-testid="fake-pay"
                                    onClick={() => {
                                        setBusy(true);
                                        router.post(`/b/${booking.public_id}/fake-pay`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
                                    }}
                                >
                                    Bayar {rupiah(booking.deposit)} (simulasi)
                                </Button>
                            </div>
                        )}

                        <InputError message={errors.payment} />
                    </div>
                )}

                {booking.status === 'paid' && (
                    <p className="mt-6 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                        Deposit diterima. Tunjukkan kode <span className="font-mono font-medium">{booking.order_id}</span> ke petugas saat datang
                        untuk check-in. Anda akan dipanggil lebih dulu dari antrean biasa.
                    </p>
                )}

                {booking.status === 'checked_in' && booking.ticket_public_id && (
                    <Button asChild className="mt-6 w-full" size="lg">
                        <Link href={`/t/${booking.ticket_public_id}`}>Lihat nomor antrean</Link>
                    </Button>
                )}

                {(booking.status === 'expired' || booking.status === 'cancelled') && (
                    <p className="text-muted-foreground mt-6 text-sm">Booking ini tidak berlaku lagi. Silakan pesan jam baru.</p>
                )}
            </div>

            <div className="mt-4">
                <LiveBadge />
            </div>
        </PublicLayout>
    );
}
