<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use JsonException;

final class LegacyDataFileDecoder
{
    /**
     * Decodes JSON content that is expected to be a list of objects
     * (e.g. dating.json, mail_api.json). Never throws on malformed
     * input; structural problems are reported as errors instead.
     *
     * @return array{errors: list<string>, rows: list<array<string, mixed>>}
     */
    public function decodeRowListFile(string $label, string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'errors' => [sprintf('%s: must be valid JSON.', $label)],
                'rows' => [],
            ];
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [
                'errors' => [sprintf('%s: must be a JSON array.', $label)],
                'rows' => [],
            ];
        }

        $errors = [];
        $rows = [];

        foreach ($decoded as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                $errors[] = sprintf(
                    '%s[%d]: must be a JSON object.',
                    $label,
                    $index,
                );

                continue;
            }

            $rows[] = $row;
        }

        return ['errors' => $errors, 'rows' => $rows];
    }

    /**
     * Decodes JSON content that is expected to be a single object
     * (e.g. migration-settings.json, channel_map.json). Never throws
     * on malformed input; structural problems are reported as errors
     * instead. An empty JSON object is indistinguishable from an
     * empty JSON array once decoded by PHP, so an empty result is
     * accepted here rather than rejected.
     *
     * @return array{errors: list<string>, data: array<string, mixed>}
     */
    public function decodeObjectFile(string $label, string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'errors' => [sprintf('%s: must be valid JSON.', $label)],
                'data' => [],
            ];
        }

        if (
            !is_array($decoded)
            || (array_is_list($decoded) && $decoded !== [])
        ) {
            return [
                'errors' => [sprintf('%s: must be a JSON object.', $label)],
                'data' => [],
            ];
        }

        return ['errors' => [], 'data' => $decoded];
    }
}
