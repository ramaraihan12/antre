import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicLayout from '@/layouts/public-layout';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Slot } from '@/types/antre';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Props {
    branch: { name: string; slug: string; city: string | null };
    services: { id: number; name: string; minutes: number; deposit: number }[];
    days: { date: string; weekday: string; day: string; month: string }[];
}

export default function BookCreate({ branch, services, days }: Props) {
    const form = useForm({ service_id: services[0]?.id ?? 0, date: days[0]?.date ?? '', time: '', name: '', phone: '' });
    const [slots, setSlots] = useState<Slot[] | null>(null);

    const { service_id, date } = form.data;
    const { setData } = form;

    useEffect(() => {
        if (!service_id || !date) return;
        const controller = new AbortController();
        setSlots(null);
        fetch(`/book/${branch.slug}/slots?service_id=${service_id}&date=${date}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => response.json())
            .then((body: { slots: Slot[] }) => {
                setSlots(body.slots);
                setData('time', '');
            })
            .catch(() => undefined);
        return () => controller.abort();
    }, [branch.slug, service_id, date, setData]);

    const service = services.find((s) => s.id === service_id);

    return (
        <PublicLayout branch={branch.name}>
            <Head title={`Booking - ${branch.name}`} />
            <h1 className="text-2xl font-semibold tracking-tight">Booking dengan deposit</h1>
            <p className="text-muted-foreground mt-1 text-sm">Pilih layanan dan jam. Slot ditahan 15 menit sampai deposit dibayar.</p>

            <form
                className="mt-6 space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/book/${branch.slug}`);
                }}
            >
                <fieldset>
                    <legend className="mb-2 text-sm font-medium">Layanan</legend>
                    <div className="space-y-2">
                        {services.map((s) => (
                            <label
                                key={s.id}
                                className={cn(
                                    'bg-background flex cursor-pointer items-center justify-between gap-3 rounded-xl border p-3',
                                    s.id === service_id && 'border-primary ring-primary/30 ring-2',
                                )}
                            >
                                <span className="flex items-center gap-3">
                                    <input
                                        type="radio"
                                        name="service"
                                        className="accent-primary"
                                        checked={s.id === service_id}
                                        onChange={() => form.setData('service_id', s.id)}
                                    />
                                    <span>
                                        <span className="block font-medium">{s.name}</span>
                                        <span className="text-muted-foreground block text-xs">sekitar {s.minutes} menit</span>
                                    </span>
                                </span>
                                <span className="text-sm font-medium">Deposit {rupiah(s.deposit)}</span>
                            </label>
                        ))}
                    </div>
                    <InputError className="mt-2" message={form.errors.service_id} />
                </fieldset>

                <fieldset>
                    <legend className="mb-2 text-sm font-medium">Tanggal</legend>
                    <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                        {days.map((d) => (
                            <button
                                type="button"
                                key={d.date}
                                onClick={() => form.setData('date', d.date)}
                                aria-pressed={d.date === date}
                                data-testid={`day-${d.date}`}
                                className={cn(
                                    'bg-background flex w-16 shrink-0 flex-col items-center rounded-xl border px-2 py-2',
                                    d.date === date && 'bg-primary text-primary-foreground border-primary',
                                )}
                            >
                                <span className="text-xs opacity-80">{d.weekday}</span>
                                <span className="text-lg font-semibold">{d.day}</span>
                                <span className="text-xs opacity-80">{d.month}</span>
                            </button>
                        ))}
                    </div>
                </fieldset>

                <fieldset>
                    <legend className="mb-2 text-sm font-medium">Jam</legend>
                    {slots === null && <p className="text-muted-foreground text-sm">Memuat jam...</p>}
                    {slots !== null && slots.length === 0 && <p className="text-muted-foreground text-sm">Tidak ada jam tersedia di tanggal ini.</p>}
                    {slots !== null && slots.length > 0 && (
                        <div className="grid grid-cols-4 gap-2 sm:grid-cols-5" data-testid="slots">
                            {slots.map((slot) => (
                                <button
                                    type="button"
                                    key={slot.time}
                                    disabled={!slot.available}
                                    onClick={() => form.setData('time', slot.time)}
                                    aria-pressed={form.data.time === slot.time}
                                    data-testid={`slot-${slot.time}`}
                                    title={slot.available ? `${slot.capacity - slot.taken} tempat tersisa` : 'Penuh'}
                                    className={cn(
                                        'bg-background rounded-lg border py-2 text-sm tabular-nums',
                                        !slot.available && 'text-muted-foreground bg-muted/60 line-through opacity-60',
                                        form.data.time === slot.time && 'bg-primary text-primary-foreground border-primary',
                                    )}
                                >
                                    {slot.time}
                                </button>
                            ))}
                        </div>
                    )}
                    <InputError className="mt-2" message={form.errors.time} />
                </fieldset>

                <div className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Nama</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="name" />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Nomor HP</Label>
                        <Input
                            id="phone"
                            inputMode="tel"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            autoComplete="tel"
                            placeholder="0812 3456 7890"
                        />
                        <InputError message={form.errors.phone} />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    disabled={form.processing || !form.data.time || !form.data.name || !form.data.phone}
                    data-testid="book-submit"
                >
                    Lanjut ke pembayaran{service ? `, ${rupiah(service.deposit)}` : ''}
                </Button>
            </form>
        </PublicLayout>
    );
}
