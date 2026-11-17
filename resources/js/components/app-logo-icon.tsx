import type { SVGAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Instituta mark "E1 · Codice" (#269): a standing codex, slightly open, in Salvia, lit from
 * above. Copied from the knowledge brand kit (`projects/qiick/brand/instituta/instituta-mark-light.svg`
 * and `-dark.svg`); the light and dark artwork swap with the theme. Brand fills live in the SVG
 * (solid brand colours, never theme tokens). Size via className.
 */
export default function AppLogoIcon({
    className,
    ...props
}: SVGAttributes<SVGElement>) {
    return (
        <span className={cn('inline-flex', className)}>
            <svg
                {...props}
                viewBox="0 0 512 512"
                xmlns="http://www.w3.org/2000/svg"
                className="size-full dark:hidden"
                aria-hidden="true"
            >
                <g transform="translate(6.51 6.51) scale(0.9746)">
                    <g transform="translate(256 256) scale(0.9752 0.9752) translate(-338.88 -215.50)">
                        <path
                            fill="#5C7653"
                            stroke="#5C7653"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M176.12 303.57L178.55 310.61L183.33 317.32L190.32 323.5L199.3 328.97L199.3 51.22L190.32 45.75L183.33 39.57L178.55 32.86L176.12 25.82Z"
                        />
                        <path
                            fill="#3F5438"
                            stroke="#3F5438"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M199.3 328.97L312.7 271.03L312.7 -6.72L199.3 51.22Z"
                        />
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M312.7 -6.72L301.99 -11.31L289.89 -14.89L276.75 -17.33L262.99 -18.57L249.01 -18.57L235.25 -17.33L222.11 -14.89L210.01 -11.31L199.3 -6.72L190.32 -1.25L183.33 4.93L178.55 11.64L176.12 18.68L176.12 25.82L178.55 32.86L183.33 39.57L190.32 45.75L199.3 51.22Z"
                        />
                        <path
                            fill="#3F5438"
                            stroke="#3F5438"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M501.7 367.61L489.1 374.04L489.1 96.29L501.7 89.86Z"
                        />
                        <path
                            fill="#5C7653"
                            stroke="#5C7653"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M489.1 374.04L300.1 277.46L300.1 -0.29L489.1 96.29Z"
                        />
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M312.7 -6.72L501.7 89.86L489.1 96.29L300.1 -0.29Z"
                        />
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M481.9 362.28L393.7 407.35L393.7 145.76L481.9 100.69Z"
                        />
                        <path
                            fill="#B0C8A9"
                            stroke="#B0C8A9"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M393.7 407.35L211.9 314.46L211.9 52.87L393.7 145.76Z"
                        />
                        <path
                            fill="#B0C8A9"
                            stroke="#B0C8A9"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M300.1 7.79L481.9 100.69L393.7 145.76L211.9 52.87Z"
                        />
                        <path
                            fill="none"
                            stroke="#5C7653"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M459.85 353.35L459.85 120.04"
                        />
                        <path
                            fill="none"
                            stroke="#5C7653"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M297.85 29.18L452.65 108.28"
                        />
                        <path
                            fill="none"
                            stroke="#5C7653"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M437.8 364.62L437.8 131.31"
                        />
                        <path
                            fill="none"
                            stroke="#5C7653"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M275.8 40.45L430.6 119.55"
                        />
                        <path
                            fill="none"
                            stroke="#5C7653"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M415.75 375.89L415.75 142.58"
                        />
                        <path
                            fill="none"
                            stroke="#5C7653"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M253.75 51.72L408.55 130.82"
                        />
                        <path
                            fill="#3F5438"
                            stroke="#3F5438"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M340.52 445.29L324.78 449.57L324.78 171.82L340.52 167.54Z"
                        />
                        <path
                            fill="#5C7653"
                            stroke="#5C7653"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M324.78 449.57L194.52 324.38L194.52 46.63L324.78 171.82Z"
                        />
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M210.25 42.35L340.52 167.54L324.78 171.82L194.52 46.63Z"
                        />
                    </g>
                </g>
            </svg>
            <svg
                {...props}
                viewBox="0 0 512 512"
                xmlns="http://www.w3.org/2000/svg"
                className="hidden size-full dark:block"
                aria-hidden="true"
            >
                <g transform="translate(6.51 6.51) scale(0.9746)">
                    <g transform="translate(256 256) scale(0.9752 0.9752) translate(-338.88 -215.50)">
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M176.12 303.57L178.55 310.61L183.33 317.32L190.32 323.5L199.3 328.97L199.3 51.22L190.32 45.75L183.33 39.57L178.55 32.86L176.12 25.82Z"
                        />
                        <path
                            fill="#5C7653"
                            stroke="#5C7653"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M199.3 328.97L312.7 271.03L312.7 -6.72L199.3 51.22Z"
                        />
                        <path
                            fill="#B0C8A9"
                            stroke="#B0C8A9"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M312.7 -6.72L301.99 -11.31L289.89 -14.89L276.75 -17.33L262.99 -18.57L249.01 -18.57L235.25 -17.33L222.11 -14.89L210.01 -11.31L199.3 -6.72L190.32 -1.25L183.33 4.93L178.55 11.64L176.12 18.68L176.12 25.82L178.55 32.86L183.33 39.57L190.32 45.75L199.3 51.22Z"
                        />
                        <path
                            fill="#5C7653"
                            stroke="#5C7653"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M501.7 367.61L489.1 374.04L489.1 96.29L501.7 89.86Z"
                        />
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M489.1 374.04L300.1 277.46L300.1 -0.29L489.1 96.29Z"
                        />
                        <path
                            fill="#B0C8A9"
                            stroke="#B0C8A9"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M312.7 -6.72L501.7 89.86L489.1 96.29L300.1 -0.29Z"
                        />
                        <path
                            fill="#B0C8A9"
                            stroke="#B0C8A9"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M481.9 362.28L393.7 407.35L393.7 145.76L481.9 100.69Z"
                        />
                        <path
                            fill="#CDE2C6"
                            stroke="#CDE2C6"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M393.7 407.35L211.9 314.46L211.9 52.87L393.7 145.76Z"
                        />
                        <path
                            fill="#CDE2C6"
                            stroke="#CDE2C6"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M300.1 7.79L481.9 100.69L393.7 145.76L211.9 52.87Z"
                        />
                        <path
                            fill="none"
                            stroke="#8BA782"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M459.85 353.35L459.85 120.04"
                        />
                        <path
                            fill="none"
                            stroke="#8BA782"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M297.85 29.18L452.65 108.28"
                        />
                        <path
                            fill="none"
                            stroke="#8BA782"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M437.8 364.62L437.8 131.31"
                        />
                        <path
                            fill="none"
                            stroke="#8BA782"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M275.8 40.45L430.6 119.55"
                        />
                        <path
                            fill="none"
                            stroke="#8BA782"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M415.75 375.89L415.75 142.58"
                        />
                        <path
                            fill="none"
                            stroke="#8BA782"
                            strokeWidth="6"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M253.75 51.72L408.55 130.82"
                        />
                        <path
                            fill="#5C7653"
                            stroke="#5C7653"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M340.52 445.29L324.78 449.57L324.78 171.82L340.52 167.54Z"
                        />
                        <path
                            fill="#8BA782"
                            stroke="#8BA782"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M324.78 449.57L194.52 324.38L194.52 46.63L324.78 171.82Z"
                        />
                        <path
                            fill="#B0C8A9"
                            stroke="#B0C8A9"
                            strokeWidth="16"
                            strokeLinejoin="round"
                            d="M210.25 42.35L340.52 167.54L324.78 171.82L194.52 46.63Z"
                        />
                    </g>
                </g>
            </svg>
        </span>
    );
}
