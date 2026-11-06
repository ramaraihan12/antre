import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { type Demo, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, MonitorPlay, Ticket } from 'lucide-react';

interface Props {
    businesses: { name: string; branches: { name: string; slug: string; city: string | null }[] }[];
}

const guideLink = 'font-medium underline underline-offset-2';

/** What a stranger should click. In English because that is who opens the public demo. */
function DemoGuide({ demo }: { demo: Demo }) {
    return (
        <section
            className="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
            data-testid="demo-guide"
        >
            <h2 className="text-lg font-semibold">Try it in three tabs</h2>
            <p className="mt-1 text-sm">
                This is a live demo. Everything in it is made up and no real money moves: the deposit is a simulated payment. Other visitors share the
                same data and it resets every night. The app's screens are in Indonesian.
            </p>

            <ol className="mt-4 list-decimal space-y-2 pl-5 text-sm">
                <li>
                    Open the{' '}
                    <a className={guideLink} href={`/display/${demo.branch}`} target="_blank" rel="noreferrer">
                        waiting room TV
                    </a>{' '}
                    and keep it visible.
                </li>
                <li>
                    <a className={guideLink} href={`/q/${demo.branch}`} target="_blank" rel="noreferrer">
                        Take a number
                    </a>{' '}
                    as a walk-in customer.
                </li>
                <li>
                    <a className={guideLink} href="/login" target="_blank" rel="noreferrer">
                        Log in as staff
                    </a>
                    , open a counter and press "Panggil berikutnya". The TV changes without a reload.
                </li>
                <li>
                    <a className={guideLink} href={`/book/${demo.branch}`} target="_blank" rel="noreferrer">
                        Book a slot
                    </a>{' '}
                    for today, pay the simulated deposit, then enter the booking code under "Check-in booking" at the counter.
                </li>
            </ol>

            <div className="mt-4 text-sm" data-testid="demo-logins">
                <p className="font-medium">
                    Staff logins, password <code>{demo.password}</code>
                </p>
                <ul className="mt-1 space-y-1">
                    {demo.logins.map((login) => (
                        <li key={login.email}>
                            <code>{login.email}</code>: {login.role}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

export default function Welcome({ businesses }: Props) {
    const { auth, demo } = usePage<SharedData>().props;

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

                    {demo && <DemoGuide demo={demo} />}

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
