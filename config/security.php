<?php

declare(strict_types=1);

/**
 * Browser defense headers for the Inertia/web stack.
 *
 * HSTS belongs on the reverse proxy. CSP is pragmatic for Vite + Echo/Reverb;
 * override the full policy with SECURITY_CSP when needed.
 * Copied from externa-core `config/security.php` (microphone self for parity;
 * no AI voice in Instituta yet).
 */
return [

    'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

    'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),

    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    /*
    | Restrict powerful browser features. Microphone stays allowed for self
    | to match externa-core defaults; set `microphone=()` via
    | SECURITY_PERMISSIONS_POLICY to turn it off.
    */
    'permissions_policy' => env(
        'SECURITY_PERMISSIONS_POLICY',
        'camera=(), microphone=(self), geolocation=(), payment=(), usb=()',
    ),

    /*
    | Full Content-Security-Policy. Null = built from Vite/Reverb-friendly defaults.
    */
    'csp' => env('SECURITY_CSP'),

];
