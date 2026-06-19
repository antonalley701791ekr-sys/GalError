<?php
function isSensitiveNoiseHitPayloadLocal(array $payload): bool {
    $matchedWord = trim(mb_strtolower((string)($payload['matched_word'] ?? ''), 'UTF-8'));
    if ($matchedWord === '') {
        return true;
    }
    if (preg_match('/^\d+$/', $matchedWord) === 1) {
        return true;
    }

    static $noiseWords = [
        'www' => true,
        'http' => true,
        'https' => true,
        'html' => true,
        'htm' => true,
        'php' => true,
        'asp' => true,
        'aspx' => true,
        'jsp' => true,
        'com' => true,
        'cn' => true,
        'net' => true,
        'org' => true,
    ];

    return isset($noiseWords[$matchedWord]);
}
