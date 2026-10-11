<?php

namespace App\Services\Migration;

use Illuminate\Support\Carbon;

/**
 * Shared CSV value parsing for legacy imports (v7.35).
 */
abstract class BaseImporter implements DatasetImporter
{
    /** Accept the date shapes legacy PMS exports tend to use. */
    protected function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd M Y', 'M d, Y', 'Y-m-d H:i:s'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $v)->startOfDay()->toDateString();
            } catch (\Throwable) {
                // try the next format
            }
        }
        try {
            return Carbon::parse($v)->startOfDay()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function bool(?string $v, ?bool $default = false): ?bool
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return $default;
        }

        return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /** Money/number: strips thousands separators and currency symbols. */
    protected function num(?string $v, float $default = 0): float
    {
        $v = str_replace([',', ' ', 'GH₵', 'GHS', '₵', '¢', '$', '£', '€'], '', (string) $v);
        if ($v === '' || ! is_numeric($v)) {
            return $default;
        }

        return (float) $v;
    }

    protected function str(?string $v, int $max = 255): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        return mb_substr($v, 0, $max);
    }

    /** External ids must be short, non-empty tokens. */
    protected function externalId(?string $v): ?string
    {
        return $this->str($v, 100);
    }

    /**
     * Build the canonical row from a CSV row using the importer's column
     * aliases (header names are matched case-insensitively).
     */
    public function canonicalize(array $header, array $csvRow): array
    {
        $alias = [];
        foreach ($this->columns() as $name => $spec) {
            $alias[strtolower($name)] = $name;
            foreach (($spec['aliases'] ?? []) as $a) {
                $alias[strtolower($a)] = $name;
            }
        }
        $out = [];
        foreach ($header as $i => $h) {
            $key = $alias[strtolower(trim((string) $h))] ?? null;
            if ($key !== null) {
                $out[$key] = trim((string) ($csvRow[$i] ?? ''));
            }
        }

        return $out;
    }

    /**
     * Fields whose value genuinely differs from the model (dates normalised —
     * cast attributes come back as Carbon and would otherwise always differ).
     *
     * @return array<string, mixed> field => new value
     */
    protected function dirty($model, array $fields): array
    {
        $dirty = [];
        foreach ($fields as $k => $v) {
            $cur = $model->getAttribute($k);
            if ($cur instanceof \DateTimeInterface) {
                $cur = $cur->format('Y-m-d');
            } elseif (is_object($cur) && method_exists($cur, '__toString')) {
                $cur = (string) $cur;
            }
            if ($cur != $v) {
                $dirty[$k] = $v;
            }
        }

        return $dirty;
    }
}
