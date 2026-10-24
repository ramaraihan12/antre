export interface BranchRef {
    id: number;
    name: string;
    slug: string;
}

export type TicketStatus = 'waiting' | 'called' | 'done' | 'skipped' | 'cancelled';

export interface DisplayCounter {
    number: number;
    label: string;
    code: string | null;
}

export interface DisplayCall {
    code: string;
    counter: string;
    kind: string;
}

export interface DisplaySnapshot {
    counters: DisplayCounter[];
    recent: DisplayCall[];
    /** Milliseconds when the server built it. Older snapshots that arrive late are ignored. */
    version: number;
}

export interface Announcement {
    id: string;
    code: string;
    text: string;
}

export interface Estimate {
    position: number;
    ahead: number;
    minutes: number | null;
    average_minutes: number;
}

export interface ConsoleTicket {
    id: number;
    code: string;
    service: string;
    is_priority: boolean;
    call_count: number;
    called_at: string | null;
}

export interface Slot {
    time: string;
    starts_at: string;
    capacity: number;
    taken: number;
    available: boolean;
}

export interface DayStats {
    date: string;
    issued: number;
    served: number;
    waiting: number;
    no_shows: number;
    avg_wait_minutes: number | null;
    avg_service_minutes: number | null;
    no_show_rate: number | null;
    per_hour: { hour: number; label: string; tickets: number }[];
}
