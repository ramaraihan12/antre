import AppLayout from '@/layouts/app-layout';
import { minutesText, percent } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type DayStats } from '@/types/antre';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ringkasan hari ini', href: '/dashboard' }];

interface Props {
    branches: { id: number; name: string }[];
    branch: { id: number; name: string } | null;
    stats: DayStats | null;
}

function Stat({ label, value, hint, testId }: { label: string; value: string; hint?: string; testId: string }) {
    return (
        <div className="bg-card rounded-xl border p-4" data-testid={testId}>
            <p className="text-muted-foreground text-sm">{label}</p>
            <p className="mt-1 text-3xl font-semibold tracking-tight tabular-nums">{value}</p>
            {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
        </div>
    );
}

/** Tickets issued per hour. Plain divs so it stays crisp and readable at any width. */
function HourlyChart({ data }: { data: DayStats['per_hour'] }) {
    const max = Math.max(1, ...data.map((d) => d.tickets));
    const ticks = [max, Math.round(max / 2), 0];

    return (
        <figure>
            <div className="flex gap-3">
                <div className="text-muted-foreground flex h-48 flex-col justify-between pb-6 text-right text-xs tabular-nums" aria-hidden>
                    {ticks.map((t, i) => (
                        <span key={i}>{t}</span>
                    ))}
                </div>
                <div className="relative flex-1">
                    <div className="absolute inset-x-0 top-0 bottom-6 flex flex-col justify-between" aria-hidden>
                        {ticks.map((_, i) => (
                            <div key={i} className="border-t border-dashed" />
                        ))}
                    </div>
                    <div
                        className="relative flex h-48 items-end gap-1.5"
                        role="img"
                        aria-label={`Tiket per jam: ${data.map((d) => `${d.label}:00 ${d.tickets}`).join(', ')}`}
                    >
                        {data.map((d) => (
                            <div key={d.hour} className="flex h-full flex-1 flex-col justify-end">
                                <div className="flex flex-1 flex-col justify-end">
                                    <span className={cn('mb-1 text-center text-xs tabular-nums', d.tickets === 0 && 'opacity-0')}>{d.tickets}</span>
                                    <div className="bg-primary min-h-px rounded-t-sm" style={{ height: `${(d.tickets / max) * 100}%` }} />
                                </div>
                                <span className="text-muted-foreground mt-1 h-5 text-center text-xs tabular-nums">{d.label}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <figcaption className="text-muted-foreground mt-2 text-xs">Nomor yang diambil tiap jam (jam setempat cabang).</figcaption>
        </figure>
    );
}

export default function Dashboard({ branches, branch, stats }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ringkasan hari ini" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Ringkasan hari ini</h1>
                        <p className="text-muted-foreground text-sm">
                            {stats ? new Date(stats.date).toLocaleDateString('id-ID', { dateStyle: 'full' }) : ''}
                        </p>
                    </div>
                    {branches.length > 1 && (
                        <nav className="flex gap-1 rounded-lg border p-1" aria-label="Cabang">
                            {branches.map((b) => (
                                <Link
                                    key={b.id}
                                    href={`/dashboard?branch=${b.id}`}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm',
                                        b.id === branch?.id ? 'bg-primary text-primary-foreground' : 'hover:bg-accent',
                                    )}
                                >
                                    {b.name}
                                </Link>
                            ))}
                        </nav>
                    )}
                </div>

                {stats && branch ? (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <Stat
                                testId="stat-served"
                                label="Dilayani"
                                value={String(stats.served)}
                                hint={`${stats.issued} nomor diambil, ${stats.waiting} masih menunggu`}
                            />
                            <Stat
                                testId="stat-wait"
                                label="Rata-rata tunggu"
                                value={minutesText(stats.avg_wait_minutes)}
                                hint="Dari ambil nomor sampai dipanggil"
                            />
                            <Stat
                                testId="stat-service"
                                label="Rata-rata layanan"
                                value={minutesText(stats.avg_service_minutes)}
                                hint="Dari dipanggil sampai selesai"
                            />
                            <Stat
                                testId="stat-noshow"
                                label="Tidak hadir"
                                value={percent(stats.no_show_rate)}
                                hint={`${stats.no_shows} dari ${stats.served + stats.no_shows} yang dipanggil`}
                            />
                        </div>

                        <section className="bg-card rounded-2xl border p-6">
                            <h2 className="mb-4 font-medium">Nomor per jam</h2>
                            <HourlyChart data={stats.per_hour} />
                        </section>
                    </>
                ) : (
                    <p className="text-muted-foreground">Belum ada cabang.</p>
                )}
            </div>
        </AppLayout>
    );
}
