<?php

namespace App\Support;

use App\Services\TaxService;

/** Builds a guest-facing estimate of room tax using the property's current room-tax setup. */
class BookingConfirmationTax
{
    /**
     * @param iterable<object> $stays
     * @return array{subtotal: float, components: list<array{code: string, label: string, amount: float}>, tax_total: float, total: float}
     */
    public static function summarize(iterable $stays): array
    {
        $subtotal = 0.0;
        $componentAmounts = array_fill_keys(array_keys(TaxService::COMPONENTS), 0.0);
        $componentRatesSeen = array_fill_keys(array_keys(TaxService::COMPONENTS), []);

        foreach ($stays as $stay) {
            $nights = max(0, (int) $stay->nights);
            $rate = (float) $stay->rate;
            $extra = (float) $stay->extra_charge;
            $roomTax = TaxService::roomTax($rate);
            $subtotal += ($rate + $extra) * $nights;

            $configuredRates = TaxService::componentRates('room', $rate);
            $configuredRateTotal = array_sum($configuredRates);
            if ($configuredRateTotal > 0 && $roomTax['rate'] > 0) {
                $scale = $roomTax['rate'] / $configuredRateTotal;
                foreach ($configuredRates as $code => $componentRate) {
                    $effectiveRate = round((float) $componentRate * $scale, 4);
                    if (isset($componentRatesSeen[$code]) && $effectiveRate > 0) {
                        $componentRatesSeen[$code][number_format($effectiveRate, 4, '.', '')] = true;
                    }
                }
            }

            // Match folio posting: the room and extra-person charge are taxed as separate lines;
            // the extra-person charge uses the room tariff's applicable rate.
            $lineTaxes = [
                [$roomTax['amount'], $rate],
                [round($extra * $roomTax['rate'] / 100, 2), $rate],
            ];
            foreach ($lineTaxes as [$taxAmount, $taxBase]) {
                if ($taxAmount <= 0 || $nights === 0) {
                    continue;
                }
                $breakdown = TaxService::breakdown($taxAmount, 'room', $roomTax['rate'], $taxBase);
                foreach ($breakdown as $code => $amount) {
                    $componentAmounts[$code] = round($componentAmounts[$code] + ((float) $amount * $nights), 2);
                }
            }
        }

        $components = [];
        foreach (TaxService::COMPONENTS as $code => [$label]) {
            $amount = round((float) ($componentAmounts[$code] ?? 0), 2);
            if ($amount > 0) {
                $rates = array_keys($componentRatesSeen[$code] ?? []);
                $rateLabel = '';
                if (count($rates) === 1) {
                    $formattedRate = rtrim(rtrim(number_format((float) $rates[0], 4, '.', ''), '0'), '.');
                    $rateLabel = ' ('.$formattedRate.'%)';
                } elseif (count($rates) > 1) {
                    $rateLabel = ' (rate varies)';
                }
                $components[] = ['code' => $code, 'label' => $label.$rateLabel, 'amount' => $amount];
            }
        }
        $taxTotal = round(array_sum($componentAmounts), 2);
        $subtotal = round($subtotal, 2);

        return [
            'subtotal' => $subtotal,
            'components' => $components,
            'tax_total' => $taxTotal,
            'total' => round($subtotal + $taxTotal, 2),
        ];
    }
}
