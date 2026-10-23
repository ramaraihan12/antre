import AppLogoIcon from '@/components/app-logo-icon';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface PublicLayoutProps {
    children: ReactNode;
    branch?: string;
}

/** A narrow single column for customers on their phone. */
export default function PublicLayout({ children, branch }: PublicLayoutProps) {
    const { flash } = usePage<SharedData>().props;

    return (
        <div className="bg-muted/40 min-h-svh">
            <header className="bg-background border-b">
                <div className="mx-auto flex max-w-xl items-center justify-between px-4 py-3">
                    <Link href="/" className="flex items-center gap-2 font-semibold">
                        <span className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-4.5 fill-current" />
                        </span>
                        Antre
                    </Link>
                    {branch && <span className="text-muted-foreground max-w-[55%] truncate text-sm">{branch}</span>}
                </div>
            </header>
            <main className="mx-auto max-w-xl px-4 py-6">
                {flash.status && (
                    <p className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                        {flash.status}
                    </p>
                )}
                {children}
            </main>
            <footer className="text-muted-foreground mx-auto max-w-xl px-4 pb-8 text-center text-xs">
                Demo dengan data karangan. Tidak ada pelanggan sungguhan.
            </footer>
        </div>
    );
}
