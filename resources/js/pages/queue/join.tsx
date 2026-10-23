import InputError from '@/components/input-error';
import PublicLayout from '@/layouts/public-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    branch: { name: string; slug: string; city: string | null };
    services: { id: number; code: string; name: string; waiting: number; average_minutes: number }[];
}

export default function Join({ branch, services }: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [busy, setBusy] = useState<number | null>(null);

    const take = (serviceId: number) => {
        setBusy(serviceId);
        router.post(`/q/${branch.slug}/tickets`, { service_id: serviceId }, { onFinish: () => setBusy(null) });
    };

    return (
        <PublicLayout branch={branch.name}>
            <Head title={`Ambil nomor - ${branch.name}`} />
            <h1 className="text-2xl font-semibold tracking-tight">Ambil nomor antrean</h1>
            <p className="text-muted-foreground mt-1 text-sm">Pilih layanan. Nomor Anda langsung muncul dan posisinya diperbarui otomatis.</p>

            <ul className="mt-6 space-y-3">
                {services.map((service) => (
                    <li key={service.id}>
                        <button
                            type="button"
                            disabled={busy !== null}
                            onClick={() => take(service.id)}
                            data-testid={`take-${service.code}`}
                            className="bg-background hover:border-primary focus-visible:ring-ring flex w-full items-center gap-4 rounded-xl border p-4 text-left transition focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-60"
                        >
                            <span className="bg-primary text-primary-foreground flex size-14 shrink-0 items-center justify-center rounded-lg text-2xl font-semibold">
                                {service.code}
                            </span>
                            <span className="flex-1">
                                <span className="block font-medium">{service.name}</span>
                                <span className="text-muted-foreground block text-sm">
                                    {service.waiting === 0 ? 'Tidak ada yang menunggu' : `${service.waiting} orang menunggu`}, rata-rata{' '}
                                    {Math.max(1, Math.round(service.average_minutes))} menit per orang
                                </span>
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
            <InputError className="mt-3" message={errors.service_id} />
        </PublicLayout>
    );
}
