/** Shown instead of the account forms on the public demo, where everyone shares the logins. */
export default function DemoLocked() {
    return (
        <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            This is a shared demo account, so its name, email and password cannot be changed and it cannot be deleted.
        </p>
    );
}
