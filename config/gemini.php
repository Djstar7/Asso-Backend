<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini API Key
    |--------------------------------------------------------------------------
    |
    | Your Google Gemini API key. Get one at:
    | https://aistudio.google.com/app/apikey
    |
    */
    'api_key' => env('GEMINI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default Gemini model to use for text generation.
    | Options: gemini-pro, gemini-pro-vision
    |
    */
    'model' => env('GEMINI_MODEL', 'gemini-pro'),

    /*
    |--------------------------------------------------------------------------
    | Vision Model
    |--------------------------------------------------------------------------
    |
    | The model to use for vision/image analysis tasks.
    |
    */
    'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-pro-vision'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) to wait for a Gemini API response.
    |
    */
    'timeout' => env('GEMINI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    |
    | Maximum number of tokens to generate in responses.
    |
    */
    'max_tokens' => env('GEMINI_MAX_TOKENS', 2048),

    /*
    |--------------------------------------------------------------------------
    | Temperature
    |--------------------------------------------------------------------------
    |
    | Controls randomness in responses (0.0 to 1.0).
    | Lower = more deterministic, Higher = more creative
    |
    */
    'temperature' => env('GEMINI_TEMPERATURE', 0.7),
];
