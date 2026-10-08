<?php
declare(strict_types=1);

function lipa_normalize_reference(string $reference): string
{
    return strtoupper(trim($reference, " \t\n\r\0\x0B.,;:-"));
}

function lipa_provider_from_sender(string $sender): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sender) ?? '');
    return match (true) {
        str_contains($s, 'MPESA') => 'mpesa',
        str_contains($s, 'MIXX'), str_contains($s, 'TIGO'), str_contains($s, 'YAS') => 'mixx',
        str_contains($s, 'AIRTEL') => 'airtel',
        str_contains($s, 'HALO') => 'halopesa',
        default => 'unknown',
    };
}

function lipa_parse_sms(string $sender, string $body): array
{
    $text = preg_replace('/\s+/u', ' ', trim($body)) ?? '';
    $incoming = '/\b(umepokea|umelipwa|imepokelewa|yamepokelewa|you\s+have\s+received|received\s+(?:tsh|tzs)|payment\s+(?:of\s+.{1,30}\s+)?received|umepata\s+malipo)\b/iu';
    $money = '(?:tsh|tzs|tshs)\.?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)';

    $reference = null;
    $label = '(?:namba\s+ya\s+muamala|kumbukumbu(?:\s+namba)?|muamala(?:\s+namba)?|utambulisho\s+wa\s+muamala|trans(?:action)?\s*id|txn\s*id|tid|reference|receipt|ref)';
    if (preg_match_all('/\b' . $label . '\s*(?:no\.?|number|namba|#)?\s*[:.\-]?\s*([A-Z0-9][A-Z0-9.\-]{5,30})/iu', $text, $m)) {
        foreach ($m[1] as $candidate) {
            $ref = lipa_normalize_reference($candidate);
            if (strlen($ref) >= 6 && preg_match('/\d/', $ref) === 1 && preg_match('/^(?:\+?255|0)[67]\d{8}$/', $ref) !== 1 && preg_match('/^\d{1,7}$|,|^\d+\.\d+$/', $ref) !== 1) {
                $reference = $ref;
                break;
            }
        }
    }
    if ($reference === null && preg_match('/^([A-Z0-9]{8,12})\s*[.,]?\s*(?:imethibitishwa|confirmed|imekamilika|completed)/iu', $text, $m)) {
        $reference = lipa_normalize_reference($m[1]);
    }
    if ($reference === null && preg_match('/\b([A-Z]{1,3}\d{6}\.\d{4}\.[A-Z0-9]{4,10})\b/iu', $text, $m)) {
        $reference = lipa_normalize_reference($m[1]);
    }

    $amount = null;
    foreach ([
        '/(?:umepokea|umelipwa|received|umepata\s+malipo\s+ya)\s+(?:kiasi\s+cha\s+|malipo\s+ya\s+)?' . $money . '/iu',
        '/(?:umepokea|umelipwa|received)\s+([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:tsh|tzs|tshs)\b/iu',
        '/' . $money . '/iu',
    ] as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $value = (float) str_replace(',', '', $m[1]);
            if ($value > 0) {
                $amount = $value;
                break;
            }
        }
    }

    $payerPhone = null;
    if (preg_match('/(?<!\d)(?:\+?255|0)([67]\d{8})(?!\d)/', $text, $m)) {
        $payerPhone = '255' . $m[1];
    }

    $payerName = null;
    if (preg_match('/(?:\+?255|0)[67]\d{8}\s*[-,]?\s*(?:\(\s*)?([A-Za-z][A-Za-z\'\.\- ]{2,60}?)(?=\s*(?:\)|\.|,|;|$|\s+(?:tarehe|on|saa|at|salio|new|balance|kwa|muamala|ref|trans|txn|kumbukumbu)\b))/u', $text, $m)) {
        $payerName = strtoupper(trim($m[1], " .-'"));
    }

    return [
        'is_payment' => preg_match($incoming, $text) === 1,
        'provider' => lipa_provider_from_sender($sender),
        'reference' => $reference,
        'amount' => $amount,
        'payer_phone' => $payerPhone,
        'payer_name' => $payerName,
    ];
}
