<?php
// Thin OpenRouter client shared by the explain button and the generator.

const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

/**
 * Send a chat completion. Returns ['content' => string] or ['error' => string].
 * Never throws, since every caller renders the error straight into the UI.
 */
function openrouterChat(string $apiKey, string $model, array $messages, int $timeout = 120): array {
    if ($apiKey === '') {
        return ['error' => 'No API key configured. Add OPENROUTER_API_KEY to .env'];
    }

    // A full lesson or deck routinely takes longer than the default 30s
    // max_execution_time, and PHP kills the request mid-flight when it does.
    // Give the script room for the whole curl timeout plus overhead.
    if (function_exists('set_time_limit')) {
        @set_time_limit($timeout + 30);
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
    ]);

    $ch = curl_init(OPENROUTER_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['error' => "Network error: $err"];

    $data = json_decode($response, true);

    if ($status >= 400) {
        $message = $data['error']['message'] ?? "HTTP $status";
        return ['error' => "OpenRouter: $message"];
    }
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['error' => 'OpenRouter returned no content.'];
    }

    return ['content' => $data['choices'][0]['message']['content']];
}
