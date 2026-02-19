<?php
declare(strict_types=1);

function parseSmartScore(string $input, float $maxScore): ?float
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $input)) {
        return null;
    }

    if (strpos($input, '.') !== false) {
        $value = (float) $input;
    } else {
        $integerValue = (float) $input;
        if ($integerValue <= $maxScore) {
            $value = $integerValue;
        } else {
            $len = strlen($input);
            if ($len === 1) {
                $value = (float) $input;
            } elseif ($len === 2) {
                $value = (float) ($input[0] . '.' . $input[1]);
            } else {
                $value = (float) ($input[0] . '.' . substr($input, 1));
            }
        }
    }

    if ($value < 0 || $value > $maxScore) {
        return null;
    }

    return $value;
}

function score_value_to_string(?float $score): string
{
    if ($score === null) {
        return '';
    }

    $formatted = rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}
