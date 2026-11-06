import { Realtime, type ConnectionState, type InboundMessage, type RealtimeChannel } from 'ably';
import { Channel, Connector, EventFormatter, type ConnectionStatus } from 'laravel-echo';

type Listener = (message: InboundMessage) => void;

const statuses: Record<ConnectionState, ConnectionStatus> = {
    initialized: 'connecting',
    connecting: 'connecting',
    connected: 'connected',
    disconnected: 'reconnecting',
    suspended: 'reconnecting',
    closing: 'disconnected',
    closed: 'disconnected',
    failed: 'failed',
};

/** Echo's "foo" and "private-foo", as the channels Laravel's Ably broadcaster publishes to. */
const ablyName = (name: string) => (name.startsWith('private-') ? `private:${name.slice('private-'.length)}` : `public:${name}`);

class AblyChannel extends Channel {
    private formatter: EventFormatter;
    private listeners: { name: string; callback: CallableFunction; listener: Listener }[] = [];

    constructor(
        private readonly channel: RealtimeChannel,
        private readonly ready: Promise<unknown>,
        namespace: string | false,
    ) {
        super();
        this.formatter = new EventFormatter(namespace);
    }

    listen(event: string, callback: CallableFunction): this {
        const name = this.formatter.format(event);
        const listener: Listener = (message) => callback(message.data);

        this.listeners.push({ name, callback, listener });
        this.whenReady(() => this.channel.subscribe(name, listener));

        return this;
    }

    stopListening(event: string, callback?: CallableFunction): this {
        const name = this.formatter.format(event);

        this.listeners = this.listeners.filter((entry) => {
            const match = entry.name === name && (!callback || entry.callback === callback);
            if (match) this.whenReady(() => this.channel.unsubscribe(name, entry.listener));

            return !match;
        });

        return this;
    }

    subscribed(callback: CallableFunction): this {
        this.channel.on('attached', () => callback());

        return this;
    }

    error(callback: CallableFunction): this {
        this.channel.on('failed', (change) => callback(change.reason));

        return this;
    }

    /** Waiting on one promise keeps subscribe and unsubscribe in the order they were asked for. */
    private whenReady(step: () => unknown): void {
        this.ready.then(step).catch((error: unknown) => console.warn('Ably channel', this.channel.name, error));
    }
}

/**
 * Echo on top of ably-js. Echo's own Ably option goes through Ably's Pusher adapter, which needs
 * part of the key in the bundle. This one holds no key at all: it asks the server for a token
 * that can only listen, and the page hooks work the same as with Reverb.
 */
export class AblyConnector extends Connector<'null', AblyChannel, AblyChannel, never> {
    // The parent constructor calls connect(), so a plain field would be reset right after it.
    declare private client: Realtime;
    declare private channels: Map<string, AblyChannel>;

    connect(): void {
        this.channels = new Map();
        this.client = new Realtime({ authUrl: '/broadcasting/ably-token', authMethod: 'GET', echoMessages: false });
    }

    channel(name: string): AblyChannel {
        return this.open(ablyName(name), Promise.resolve());
    }

    privateChannel(name: string): AblyChannel {
        // Logging in does not reload the page, so the open token may be a visitor's one.
        return this.open(ablyName(`private-${name}`), this.client.auth.authorize());
    }

    presenceChannel(): never {
        throw new Error('Presence channels are not used here.');
    }

    leave(name: string): void {
        this.leaveChannel(name);
        this.leaveChannel(`private-${name}`);
    }

    leaveChannel(name: string): void {
        const key = ablyName(name);

        if (this.channels.delete(key)) {
            this.client.channels
                .get(key)
                .detach()
                .catch(() => {});
        }
    }

    socketId(): string | undefined {
        return this.client.connection.id;
    }

    connectionStatus(): ConnectionStatus {
        return statuses[this.client.connection.state];
    }

    onConnectionChange(callback: (status: ConnectionStatus) => void): () => void {
        const listener = () => callback(this.connectionStatus());

        this.client.connection.on(listener);

        return () => this.client.connection.off(listener);
    }

    disconnect(): void {
        this.client.close();
    }

    private open(key: string, ready: Promise<unknown>): AblyChannel {
        const channel = this.channels.get(key) ?? new AblyChannel(this.client.channels.get(key), ready, this.options.namespace);

        this.channels.set(key, channel);

        return channel;
    }
}
