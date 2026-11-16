<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    // Off by default: local OpenAI-compatible URL (Ollama / LM Studio) until keys are set.
    'default' => env('AI_DEFAULT_PROVIDER', 'openai-compatible'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => env('AI_DEFAULT_FOR_EMBEDDINGS', 'openai-compatible'),
    'default_for_reranking' => 'cohere',
    'default_for_classification' => 'typesafe',

    /*
    |--------------------------------------------------------------------------
    | Assistant (#203)
    |--------------------------------------------------------------------------
    |
    | The in-app assistant (App\Ai\Agents\AppAssistant): it answers from the
    | brain inside the user's perimeter and drafts proposals, never writes.
    | Off until an admin sets AI_ASSISTANT_ENABLED=true and a provider. The
    | default provider is the local OpenAI-compatible endpoint (Ollama, LM
    | Studio), so nothing leaves the server; anthropic, openai and gemini work
    | with their keys. Copied from externa-core's `assistant` block.
    |
    */

    'assistant' => [
        'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),
        'provider' => env('AI_ASSISTANT_PROVIDER', 'openai-compatible'),
        // Tool calls in one answer: search, outline and a few sections, then the reply.
        'max_steps' => (int) env('AI_ASSISTANT_MAX_STEPS', 12),
        'timeout' => (int) env('AI_ASSISTANT_TIMEOUT', 300),
    ],

    /*
    | Per-user daily budget, counted from midnight in the app timezone. 0 = off.
    | Both cover the chat and proposal drafts (externa-core #275).
    */

    'daily_prompt_limit' => (int) env('AI_DAILY_PROMPT_LIMIT', 0),
    'daily_token_budget' => (int) env('AI_DAILY_TOKEN_BUDGET', 0),

    'drafts' => [
        // Caps one proposal draft response (#203).
        'max_tokens' => (int) env('AI_DRAFTS_MAX_TOKENS', 2000),
    ],

    'embeddings' => [
        'enabled' => (bool) env('AI_EMBEDDINGS_ENABLED', false),
        'model_name' => env('AI_EMBEDDINGS_MODEL_NAME', 'local-embedding'),
        'model_version' => env('AI_EMBEDDINGS_MODEL_VERSION', '1'),
        'dimensions' => (int) env('AI_EMBEDDINGS_DIMENSIONS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'context_window' => (int) env('ANTHROPIC_CONTEXT_WINDOW', 200000),
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-6-sol'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-2.5-flare'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'context_window' => (int) env('GEMINI_CONTEXT_WINDOW', 1048576),
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'context_window' => (int) env('OPENAI_CONTEXT_WINDOW', 400000),
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'context_window' => (int) env('LOCAL_AI_CONTEXT_WINDOW', 131072),
            // Default: local Ollama / LM Studio OpenAI-compatible endpoint.
            'url' => env('OPENAI_COMPATIBLE_URL', 'http://127.0.0.1:11434/v1'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY', ''),
            'models' => [
                'text' => [
                    'default' => env('AI_LOCAL_TEXT_MODEL', 'local-model'),
                ],
                'embeddings' => [
                    'default' => env('AI_EMBEDDINGS_PROVIDER_MODEL', 'nomic-embed-text'),
                    'dimensions' => (int) env('AI_EMBEDDINGS_DIMENSIONS', 8),
                ],
            ],
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
