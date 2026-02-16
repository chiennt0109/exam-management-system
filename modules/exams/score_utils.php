<?php
declare(strict_types=1);

function parseSmartScore($input, float $maxScore): ?float
{
    $raw = trim((string) $input);
    if ($raw == '') {
        return null;
    }

    if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $raw)) {
        throw new InvalidArgumentException('Điểm không hợp lệ.');
    }

    if (strpos($raw, '.') !== false) {
        $parsed = (float) $raw;
    } else {
        $len = strlen($raw);
        if ($len === 1) {
            $parsed = (float) $raw;
        } elseif ($len === 2) {
            $parsed = (float) ($raw[0] . '.' . $raw[1]);
        } elseif ($len === 3) {
            $parsed = (float) ($raw[0] . '.' . $raw[1] . $raw[2]);
        } else {
            throw new InvalidArgumentException('Điểm không hợp lệ.');
        }
    }

    if ($parsed < 0 || $parsed > $maxScore) {
        throw new InvalidArgumentException('Điểm vượt quá mức tối đa cho phép.');
    }

    return $parsed;
}

function score_value_to_string(?float $score): string
{
    if ($score === null) {
        return '';
    }

    $formatted = rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}
