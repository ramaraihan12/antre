import LiveBadge from '@/components/live-badge';
import { announce, unlockAudio } from '@/lib/announce';
import { cn } from '@/lib/utils';
import { type Announcement, type BranchRef, type DisplaySnapshot } from '@/types/antre';
import { Head } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { Volume2, VolumeX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    branch: BranchRef;
    snapshot: DisplaySnapshot;
}

function Clock() {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(id);
    }, []);
    return <span className="tabular-nums">{now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span>;
}

export default function Display({ branch, snapshot: initial }: Props) {
    const [snapshot, setSnapshot] = useState(initial);
    const [soundOn, setSoundOn] = useState(false);
    const [highlight, setHighlight] = useState<string | null>(null);

    // Refs so the websocket callback always sees the latest values without resubscribing.
    const soundRef = useRef(false);
    const seen = useRef<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEchoPublic<{ snapshot: DisplaySnapshot; announcement: Announcement | null }>(
        `display.${branch.id}`,
        '.display.updated',
        (payload) => {
            setSnapshot(payload.snapshot);

            const call = payload.announcement;
            if (call && call.id !== seen.current) {
                seen.current = call.id;
                setHighlight(call.code);
                if (timer.current) clearTimeout(timer.current);
                timer.current = setTimeout(() => setHighlight(null), 8000);
                if (soundRef.current) announce(call.text);
            }
        },
        [branch.id],
    );

    const enableSound = () => {
        const ok = unlockAudio();
        soundRef.current = ok;
        setSoundOn(ok);
    };

    return (
        <>
            <Head title={`Layar antrean - ${branch.name}`} />
            <div className="flex min-h-svh flex-col bg-slate-950 p-6 text-white lg:p-10">
                <header className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-sm tracking-widest text-teal-300 uppercase">Nomor dipanggil</p>
                        <h1 className="text-2xl font-semibold lg:text-4xl">{branch.name}</h1>
                    </div>
                    <div className="flex items-center gap-5 text-right">
                        <button
                            type="button"
                            onClick={enableSound}
                            className={cn(
                                'flex items-center gap-2 rounded-full border px-4 py-2 text-sm transition',
                                soundOn
                                    ? 'border-teal-400/50 text-teal-200'
                                    : 'border-amber-400/60 bg-amber-400/10 text-amber-200 hover:bg-amber-400/20',
                            )}
                            data-testid="sound-toggle"
                        >
                            {soundOn ? <Volume2 className="size-4" /> : <VolumeX className="size-4" />}
                            {soundOn ? 'Suara aktif' : 'Aktifkan suara'}
                        </button>
                        <div className="text-3xl font-semibold lg:text-5xl">
                            <Clock />
                        </div>
                    </div>
                </header>

                <div className="mt-8 grid flex-1 gap-6 lg:grid-cols-[2fr_1fr]">
                    <section className="grid auto-rows-fr gap-4 sm:grid-cols-2" aria-label="Loket">
                        {snapshot.counters.map((counter) => {
                            const active = counter.code !== null;
                            const flashing = active && counter.code === highlight;

                            return (
                                <div
                                    key={counter.number}
                                    data-testid={`counter-${counter.number}`}
                                    className={cn(
                                        'flex flex-col justify-between rounded-2xl border p-6 transition-colors duration-500',
                                        active ? 'border-teal-400/40 bg-slate-900' : 'border-slate-800 bg-slate-900/40',
                                        flashing && 'animate-pulse border-teal-300 bg-teal-900/50',
                                    )}
                                >
                                    <p className="text-xl text-slate-300 lg:text-2xl">{counter.label}</p>
                                    <p
                                        data-testid={`counter-${counter.number}-code`}
                                        className={cn(
                                            'mt-4 text-7xl font-semibold tracking-tight tabular-nums lg:text-8xl',
                                            active ? 'text-white' : 'text-slate-700',
                                        )}
                                    >
                                        {counter.code ?? '---'}
                                    </p>
                                </div>
                            );
                        })}
                    </section>

                    <aside className="rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
                        <h2 className="text-sm tracking-widest text-slate-400 uppercase">Panggilan terakhir</h2>
                        <ol className="mt-4 space-y-3" data-testid="recent-calls">
                            {snapshot.recent.length === 0 && <li className="text-slate-500">Belum ada panggilan.</li>}
                            {snapshot.recent.map((call, index) => (
                                <li
                                    key={`${call.code}-${index}`}
                                    className={cn(
                                        'flex items-baseline justify-between gap-3 border-b border-slate-800 pb-3',
                                        index === 0 && 'text-teal-200',
                                    )}
                                >
                                    <span className="text-3xl font-semibold tabular-nums">{call.code}</span>
                                    <span className="text-slate-400">
                                        {call.counter}
                                        {call.kind === 'recall' && ' (ulang)'}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </aside>
                </div>

                <footer className="mt-6 flex items-center justify-between text-slate-500">
                    <span className="text-xs">Antre, demo dengan data karangan</span>
                    <LiveBadge className="text-slate-400" />
                </footer>
            </div>
        </>
    );
}
