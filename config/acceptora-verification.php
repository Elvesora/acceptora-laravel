<?php

declare(strict_types=1);

return [
    'base_url' => env('ACCEPTORA_VERIFICATION_BASE_URL', 'https://www.acceptora.com'),
    'token' => env('ACCEPTORA_AGENT_TOKEN'),
    'timeout' => (float) env('ACCEPTORA_VERIFICATION_TIMEOUT', 15),
    'connect_timeout' => (float) env('ACCEPTORA_VERIFICATION_CONNECT_TIMEOUT', 5),
    'max_request_bytes' => (int) env('ACCEPTORA_VERIFICATION_MAX_REQUEST_BYTES', 2_000_000),
    'max_response_bytes' => (int) env('ACCEPTORA_VERIFICATION_MAX_RESPONSE_BYTES', 4_000_000),
    'max_tool_pages' => (int) env('ACCEPTORA_VERIFICATION_MAX_TOOL_PAGES', 20),
    'user_agent' => env('ACCEPTORA_VERIFICATION_USER_AGENT', 'Acceptora-Verification-Laravel/1.0.0'),
];
