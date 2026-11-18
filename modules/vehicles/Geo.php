<?php

declare(strict_types=1);

namespace Blue\Modules\Vehicles;

/**
 * Small geography helpers for the vehicle booking map (DEC-90) — no outside service:
 * - the straight-line (great-circle) distance between two points;
 * - reading a point typed as "latitude, longitude";
 * - reading the point out of a map link someone pasted (Google Maps, OpenStreetMap, Apple Maps). Short links
 *   (maps.app.goo.gl/…) carry no coordinates — the link is kept and the person sets the pin themselves.
 * The same rules run in the browser (ui/js/vehicles.js) to move the pin at once; the server's answer is the one kept.
 */
final class Geo
{
    /** mean Earth radius (km) */
    private const EARTH_KM = 6371.0088;

    /** hosts a pasted map link may come from */
    public const LINK_HOSTS = [
        'google.com', 'www.google.com', 'maps.google.com', 'google.co.th', 'www.google.co.th', 'maps.google.co.th',
        'maps.app.goo.gl', 'goo.gl', 'openstreetmap.org', 'www.openstreetmap.org', 'osm.org', 'maps.apple.com',
    ];

    public static function straightKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::EARTH_KM * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array{0: float, 1: float}|null */
    public static function point(string $text): ?array
    {
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d{1,8})?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d{1,8})?)\s*$/', $text, $m) !== 1) {
            return null;
        }
        return self::valid((float) $m[1], (float) $m[2]);
    }

    /** @return array{0: float, 1: float}|null */
    private static function valid(float $lat, float $lng): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }
        return [round($lat, 6), round($lng, 6)];
    }

    public static function linkAllowed(string $url): bool
    {
        if (strlen($url) > 500 || preg_match('#^https://([^/?\#:@]+)(/|\?|\#|$)#i', $url, $m) !== 1) {
            return false;
        }
        return in_array(strtolower($m[1]), self::LINK_HOSTS, true);
    }

    /**
     * The point a map link shows, when it carries one (the place marker first, then the query, then the view centre).
     *
     * @return array{0: float, 1: float}|null
     */
    public static function fromLink(string $url): ?array
    {
        if (!self::linkAllowed($url)) {
            return null;
        }
        $u = rawurldecode($url);
        $num = '(-?\d{1,3}\.\d{2,8})';
        $patterns = [
            '/!3d' . $num . '!4d' . $num . '/',                                       // Google place marker
            '/[?&](?:q|query|ll|destination|daddr|saddr|center|sll)=(?:loc:)?' . $num . '\s*,\s*' . $num . '/', // query point
            '/[?&]mlat=' . $num . '&mlon=' . $num . '/',                              // OpenStreetMap marker
            '/#map=\d{1,2}\/' . $num . '\/' . $num . '/',                              // OpenStreetMap view
            '/@' . $num . ',' . $num . '/',                                           // Google view centre
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $u, $m) === 1) {
                $pt = self::valid((float) $m[1], (float) $m[2]);
                if ($pt !== null) {
                    return $pt;
                }
            }
        }
        return null;
    }
}
