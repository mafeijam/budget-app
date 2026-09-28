<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Daily closing prices from Yahoo Finance's chart endpoint.
 *
 * Undocumented and unofficial: there is no public Yahoo Finance API, and this is the
 * endpoint its own pages and the yfinance library read. It answers a plain request
 * with a browser User-Agent and no cookie or crumb; without the User-Agent it is
 * readier to refuse with a 429. It can change or stop without notice, which is why
 * prices land in a table a user can also type into, and why a failure throws rather
 * than returning a zero that would read as a price.
 */
class YahooFinance
{
    private const ENDPOINT = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    /**
     * Each trading day's close between two days, inclusive, and the currency they are in.
     *
     * Days are the exchange's own, from the timestamp read in the exchange's timezone:
     * a Hong Kong close and a New York close on the same UTC instant are different
     * calendar days, and the day is what the price is filed under.
     *
     * The close arrives as a JSON float -- 436.6000061035156 for 436.60 -- so it is read
     * into a BigDecimal at once and held to four places, the scale every price here uses.
     *
     * @return array{currency: string, closes: array<string, string>}
     *
     * @throws RuntimeException when Yahoo does not answer with a chart
     */
    public function closes(string $symbol, string $from, string $to): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->acceptJson()
                ->timeout(15)
                // Twice more on a refusal or a timeout, a second apart: a 429 is usually
                // gone a moment later.
                ->retry(2, 1000, throw: false)
                ->get(self::ENDPOINT.rawurlencode($symbol), [
                    // A day either side, so a range that starts or ends on a closed day
                    // still includes the trading days at its edges.
                    'period1' => CarbonImmutable::parse($from)->subDay()->startOfDay()->timestamp,
                    'period2' => CarbonImmutable::parse($to)->addDays(2)->startOfDay()->timestamp,
                    'interval' => '1d',
                ])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException("Yahoo did not return prices for {$symbol}: {$e->getMessage()}", 0, $e);
        }

        $result = $response->json('chart.result.0');

        if (! is_array($result) || ! isset($result['meta']['currency'], $result['timestamp'])) {
            $error = $response->json('chart.error.description') ?? 'no chart in the response';

            throw new RuntimeException("Yahoo did not return prices for {$symbol}: {$error}");
        }

        $timezone = $result['meta']['exchangeTimezoneName'] ?? 'UTC';
        $closes = [];

        foreach ($result['timestamp'] as $i => $timestamp) {
            $close = $result['indicators']['quote'][0]['close'][$i] ?? null;

            // Yahoo leaves a null for a day it has no close for, a halt or a gap in its
            // data; skipped rather than stored as zero.
            if ($close === null) {
                continue;
            }

            $day = CarbonImmutable::createFromTimestamp($timestamp, $timezone)->toDateString();

            if ($day < $from || $day > $to) {
                continue;
            }

            $closes[$day] = (string) BigDecimal::of((string) $close)->toScale(4, RoundingMode::HalfUp);
        }

        return ['currency' => strtoupper($result['meta']['currency']), 'closes' => $closes];
    }
}
