import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        // Our own tests only: the default glob also finds example specs shipped in vendor/ (#313).
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/css/shadcn-tailwind.css',
            'resources/js/components/admin/*',
            'resources/js/components/realtime/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            'docs/**',
            '.github/copilot-instructions.md',
            'CLAUDE.md',
            'AGENTS.md',
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/css/shadcn-tailwind.css',
            'resources/js/components/admin/*',
            'resources/js/components/realtime/*',
            'resources/views/mail/*',
            // @qiick registry chat / assistant items (#203): kept byte for byte for the drift check.
            'resources/js/components/ai-elements/*',
            'resources/js/components/ai/ai-answer-parts.tsx',
            'resources/js/components/ai/assistant-panel.tsx',
            'resources/js/components/layout/chat-layout.tsx',
            'resources/js/lib/ai-message-parts.ts',
            'resources/js/lib/composer-chrome.ts',
            'resources/js/hooks/use-autosize-textarea.ts',
            // Synthetic brain corpora: oxfmt must not rewrite fixture bodies
            // (e.g. PEM fences that look like markdown frontmatter).
            'tests/Unit/Brain/Fixtures/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
