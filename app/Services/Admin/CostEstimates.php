<?php

namespace App\Services\Admin;

/**
 * Cost estimates from operator-entered pricing. No prices configured
 * means costs are UNKNOWN (null) — never a misleading zero. Each
 * estimate snapshots the pricing version in force at recording time,
 * so later price changes never rewrite history.
 */
final class CostEstimates
{
    /** @return array{0: ?int, 1: ?string} micros + pricing version snapshot. */
    public static function for(?int $inputTokens, ?int $outputTokens): array
    {
        if ($inputTokens === null && $outputTokens === null) {
            return [null, null];
        }

        $inPrice = AppSettings::get('ai.price_input_per_1k_micros');
        $outPrice = AppSettings::get('ai.price_output_per_1k_micros');

        if (! is_int($inPrice) || ! is_int($outPrice)) {
            return [null, null];
        }

        $micros = (int) round(($inputTokens ?? 0) / 1000 * $inPrice)
            + (int) round(($outputTokens ?? 0) / 1000 * $outPrice);

        $version = AppSettings::get('ai.price_version');

        return [$micros, is_string($version) && $version !== '' ? substr($version, 0, 64) : 'operator-prices'];
    }

    public static function formatMicros(?int $micros): string
    {
        if ($micros === null) {
            return 'unknown';
        }

        return '$'.number_format($micros / 1000000, 4);
    }
}
