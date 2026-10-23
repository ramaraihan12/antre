import { SVGAttributes } from 'react';

/** A queue ticket with a perforated edge. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2.5 2.5 0 0 0 0 6v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2.5 2.5 0 0 0 0-6V6Zm8.25 1.5h1.5v2h-1.5v-2Zm0 4h1.5v2h-1.5v-2Zm0 4h1.5v2h-1.5v-2Z"
            />
        </svg>
    );
}
