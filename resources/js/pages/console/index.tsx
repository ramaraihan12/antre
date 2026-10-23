import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Loket', href: '/console' }];

interface Props {
    branches: { id: number; name: string; slug: string; counters: { id: number; label: string; services: string[] }[] }[];
}

export default function ConsoleIndex({ branches }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pilih loket" />
            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Pilih loket</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Anda akan memanggil nomor untuk loket yang dipilih.</p>
                </div>

                {branches.map((branch) => (
                    <section key={branch.id}>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-medium">{branch.name}</h2>
                            <a href={`/display/${branch.slug}`} target="_blank" rel="noreferrer" className="text-primary text-sm hover:underline">
                                Buka layar TV
                            </a>
                        </div>
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {branch.counters.map((counter) => (
                                <li key={counter.id}>
                                    <Link
                                        href={`/console/counters/${counter.id}`}
                                        data-testid={`pick-counter-${counter.id}`}
                                        className="bg-card hover:border-primary flex items-center justify-between rounded-xl border p-4 transition"
                                    >
                                        <span className="text-lg font-medium">{counter.label}</span>
                                        <span className="text-muted-foreground text-sm">Layanan {counter.services.join(', ')}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
