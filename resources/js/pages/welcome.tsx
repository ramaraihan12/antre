import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, MonitorPlay, Ticket } from 'lucide-react';

interface Props {
    businesses: { name: string; branches: { name: string; slug: string; city: string | null }[] }[];
}

export default function Welcome({ businesses }: Props) {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Antre" />
            <div className="bg-muted/40 min-h-svh">
                <header className="bg-background border-b">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
                        <span className="flex items-center gap-2 font-semibold">
                            <span className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-4.5 fill-current" />
                            </span>
                            Antre
                        </span>
                        <Button asChild size="sm" variant="outline">
                            <Link href={auth.user ? '/console' : '/login'}>{auth.user ? 'Buka loket' : 'Masuk staf'}</Link>
                        </Button>
                    </div>
                </header>

                <main className="mx-auto max-w-3xl px-4 py-10">
                    <h1 className="text-3xl font-semibold tracking-tight">Antrean dan booking untuk klinik dan barbershop kecil</h1>
                    <p className="text-muted-foreground mt-3 max-w-xl">
                        Ambil nomor dari HP, pesan jam dengan deposit, dan lihat panggilan di layar TV. Semua yang di bawah ini data karangan untuk
                        demo.
                    </p>

                    <div className="mt-8 space-y-6">
                        {businesses.map((business) => (
                            <section key={business.name} className="bg-background rounded-xl border p-5">
                                <h2 className="text-lg font-semibold">{business.name}</h2>
                                <ul className="mt-3 divide-y">
                                    {business.branches.map((branch) => (
                                        <li key={branch.slug} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                            <div>
                                                <p className="font-medium">{branch.name}</p>
                                                {branch.city && <p className="text-muted-foreground text-sm">{branch.city}</p>}
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <Button asChild size="sm">
                                                    <Link href={`/q/${branch.slug}`}>
                                                        <Ticket /> Ambil nomor
                                                    </Link>
                                                </Button>
                                                <Button asChild size="sm" variant="secondary">
                                                    <Link href={`/book/${branch.slug}`}>
                                                        <CalendarClock /> Booking
                                                    </Link>
                                                </Button>
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={`/display/${branch.slug}`}>
                                                        <MonitorPlay /> Layar TV
                                                    </Link>
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                </main>
            </div>
        </>
    );
}
