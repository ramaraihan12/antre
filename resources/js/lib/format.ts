export function rupiah(amount: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
}

/** "kurang dari 1 menit", "12 menit", "1 jam 5 menit" */
export function waitText(minutes: number | null): string {
    if (minutes === null) return '';
    if (minutes < 1) return 'kurang dari 1 menit';
    if (minutes < 60) return `${minutes} menit`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m === 0 ? `${h} jam` : `${h} jam ${m} menit`;
}

export function percent(rate: number | null): string {
    return rate === null ? '-' : `${Math.round(rate * 100)}%`;
}

export function minutesText(value: number | null): string {
    return value === null ? '-' : `${value.toLocaleString('id-ID', { maximumFractionDigits: 1 })} mnt`;
}
