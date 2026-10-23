/**
 * Chime and spoken announcement for the TV display.
 *
 * Browsers only allow sound after a click, so the display page calls `unlockAudio()` from a
 * button first. After that `announce()` plays a two-note chime and then reads the sentence
 * the server built, using an Indonesian voice when the machine has one.
 */

let context: AudioContext | null = null;
let chain: Promise<void> = Promise.resolve();

export function unlockAudio(): boolean {
    try {
        const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
        if (!Ctor) return false;
        context ??= new Ctor();
        void context.resume();
        // Calling speak() once inside the click also unlocks speech on some browsers.
        window.speechSynthesis?.cancel();
        return true;
    } catch {
        return false;
    }
}

function chime(): Promise<void> {
    return new Promise((resolve) => {
        if (!context) return resolve();
        const start = context.currentTime;
        [
            [880, 0],
            [660, 0.28],
        ].forEach(([freq, offset]) => {
            const osc = context!.createOscillator();
            const gain = context!.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, start + offset);
            gain.gain.exponentialRampToValueAtTime(0.35, start + offset + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.6);
            osc.connect(gain).connect(context!.destination);
            osc.start(start + offset);
            osc.stop(start + offset + 0.65);
        });
        setTimeout(resolve, 900);
    });
}

function speak(text: string): Promise<void> {
    return new Promise((resolve) => {
        const synth = window.speechSynthesis;
        if (!synth) return resolve();

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'id-ID';
        utterance.rate = 0.9;
        const voice = synth.getVoices().find((v) => v.lang.toLowerCase().startsWith('id'));
        if (voice) utterance.voice = voice;
        utterance.onend = () => resolve();
        utterance.onerror = () => resolve();
        synth.speak(utterance);
    });
}

/** Announcements are queued so two quick calls are read one after the other. */
export function announce(text: string): void {
    chain = chain.then(async () => {
        await chime();
        await speak(text);
    });
}
