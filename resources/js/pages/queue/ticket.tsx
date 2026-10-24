import LiveBadge from '@/components/live-badge';
import { Button } from '@/components/ui/button';
import { useReloadOnReconnect } from '@/hooks/use-reload-on-reconnect';
import PublicLayout from '@/layouts/public-layout';
import { waitText } from '@/lib/format';
import { type BranchRef, type Estimate, type TicketStatus } from '@/types/antre';
import { Head, router } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

interface Props {
    ticket: { public_id: string; code: string; status: TicketStatus; service: string; is_priority: boolean; counter: string | null };
    estimate: Estimate;
    branch: BranchRef;
}

const closed: Record<string, string> = {
    done: 'Selesai. Terima kasih sudah datang.',
    skipped: 'Nomor ini dilewati karena tidak hadir saat dipanggil. Ambil nomor baru jika masih perlu.',
    cancelled: 'Nomor ini dibatalkan.',
};

export default function TicketPage({ ticket, estimate, branch }: Props) {
    useReloadOnReconnect(['ticket', 'estimate']);
    // Someone else's ticket changing is a nudge to ask the server for this one's new position.
    useEchoPublic(`queue.${branch.id}`, '.queue.changed', () => router.reload({ only: ['ticket', 'estimate'] }), [branch.id]);

    const wasCalled = useRef(ticket.status === 'called');
    useEffect(() => {
        if (ticket.status === 'called' && !wasCalled.current) {
            navigator.vibrate?.([200, 100, 200]);
        }
        wasCalled.current = ticket.status === 'called';
        document.title = ticket.status === 'called' ? `Giliran Anda: ${ticket.code}` : `${ticket.code} - Antre`;
    }, [ticket.status, ticket.code]);

    return (
        <PublicLayout branch={branch.name}>
            <Head title={ticket.code} />

            <div className="bg-background rounded-2xl border p-6 text-center" data-testid="ticket-card" data-status={ticket.status}>
                <p className="text-muted-foreground text-sm">{ticket.service}</p>
                <p className="mt-1 text-7xl font-semibold tracking-tight tabular-nums" data-testid="ticket-code">
                    {ticket.code}
                </p>
                {ticket.is_priority && (
                    <p className="mt-2 inline-block rounded-full bg-amber-100 px-3 py-0.5 text-xs font-medium text-amber-900">Prioritas booking</p>
                )}

                {ticket.status === 'waiting' && (
                    <div className="mt-6 grid grid-cols-2 gap-3 text-left">
                        <div className="bg-muted rounded-lg p-3">
                            <p className="text-muted-foreground text-xs">Posisi</p>
                            <p className="text-2xl font-semibold" data-testid="ticket-position">
                                {estimate.position}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {estimate.ahead === 0 ? 'Berikutnya' : `${estimate.ahead} orang di depan`}
                            </p>
                        </div>
                        <div className="bg-muted rounded-lg p-3">
                            <p className="text-muted-foreground text-xs">Perkiraan tunggu</p>
                            <p className="text-2xl font-semibold" data-testid="ticket-wait">
                                {estimate.ahead === 0 ? '0' : estimate.minutes}
                                <span className="text-muted-foreground ml-1 text-sm font-normal">mnt</span>
                            </p>
                            <p className="text-muted-foreground text-xs">{waitText(estimate.minutes)}</p>
                        </div>
                    </div>
                )}

                {ticket.status === 'called' && (
                    <div
                        className="mt-6 rounded-lg bg-emerald-50 p-4 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
                        data-testid="ticket-called"
                    >
                        <p className="text-sm">Giliran Anda</p>
                        <p className="text-2xl font-semibold">Silakan ke {ticket.counter}</p>
                    </div>
                )}

                {closed[ticket.status] && <p className="text-muted-foreground mt-6 text-sm">{closed[ticket.status]}</p>}
            </div>

            <div className="mt-4 flex items-center justify-between">
                <LiveBadge />
                {ticket.status === 'waiting' && (
                    <Button variant="ghost" size="sm" onClick={() => confirm('Batalkan nomor ini?') && router.post(`/t/${ticket.public_id}/cancel`)}>
                        Batalkan nomor
                    </Button>
                )}
            </div>

            {ticket.status === 'waiting' && (
                <p className="text-muted-foreground mt-4 text-xs">
                    Perkiraan dihitung dari rata-rata {estimate.average_minutes.toLocaleString('id-ID')} menit per orang untuk layanan ini, dibagi
                    jumlah loket yang melayaninya. Hanya perkiraan.
                </p>
            )}
        </PublicLayout>
    );
}
