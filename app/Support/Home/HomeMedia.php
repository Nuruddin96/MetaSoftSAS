<?php

namespace App\Support\Home;

/**
 * Real photos and documents for the Concept C homepage
 * (resources/views/central/home/*). Only readable image files are returned:
 * nothing is invented, and a section whose material hasn't been supplied
 * yet shows an honest fallback or stays hidden. Empty folders, non-image
 * files and unreadable images are skipped, so no broken image is ever shown.
 *
 * public/images/home/programme/      — web copies of the supplied consultancy-* and programme-* photos
 * public/images/Homepage/programme/  — further programme photos as uploaded (optional, may be empty)
 * public/images/home/awards/         — award presentation photos (supplied 2026-10-19), shown as "Award moments"
 * public/images/home/certificate.{jpg,jpeg,png,webp} — the DBID certificate (personal address and email masked)
 * public/images/home/recipients/     — past award recipient portraits; file name = caption
 */
class HomeMedia
{
    private const ALT = 'Business consultancy session with entrepreneurs';

    private const PROGRAMME_ALT = 'Entrepreneur programme';

    private const IMAGE = '/\.(jpe?g|png|webp)$/i';

    /** @return list<array{src: string, alt: string, portrait: bool, name: string}> */
    public static function programme(): array
    {
        $photos = [];
        foreach (['images/home/programme', 'images/Homepage/programme'] as $dir) {
            foreach (self::images($dir) as $rel => [$w, $h]) {
                $name = strtolower(preg_replace('/(\.(jpe?g|png|webp))+$/i', '', basename($rel)));
                $photos[$name] ??= ['src' => asset($rel), 'alt' => str_starts_with($name, 'consultancy') ? self::ALT : self::PROGRAMME_ALT, 'portrait' => $h > $w, 'name' => $name];
            }
        }

        // Programme/event photos lead, then the consultancy sessions.
        uasort($photos, fn ($a, $b) => [str_starts_with($a['name'], 'consultancy'), $a['name']] <=> [str_starts_with($b['name'], 'consultancy'), $b['name']]);

        return array_values($photos);
    }

    /** Four photos for the strip that overlaps the hero: the event photos first, else the consultancy ones. */
    public static function moments(): array
    {
        $all = self::programme();
        $events = array_values(array_filter($all, fn ($p) => ! str_starts_with($p['name'], 'consultancy')));

        return count($events) >= 4 ? array_slice($events, 0, 4) : array_slice(array_merge(array_slice($all, 4), $all), 0, min(4, count($all)));
    }

    /** Award presentation photos — not labelled as MetaSoft BD award recipients, since no names or awards were supplied. */
    public static function awards(): array
    {
        return collect(self::images('images/home/awards'))
            ->map(fn ($size, $rel) => ['src' => asset($rel), 'alt' => 'Award presentation', 'portrait' => $size[1] > $size[0]])
            ->values()->all();
    }

    /** Consultancy & networking pairs: a wide main photo and a square-ish detail, when the supplied set allows. */
    public static function consultancy(): array
    {
        $by = collect(self::programme())->keyBy('name');

        return array_values(array_filter([
            isset($by['consultancy-01'], $by['consultancy-02']) ? [$by['consultancy-01'], $by['consultancy-02']] : null,
            isset($by['consultancy-03'], $by['consultancy-04']) ? [$by['consultancy-03'], $by['consultancy-04']] : null,
        ]));
    }

    /** The registration certificate scan, or null until a readable one is supplied. */
    public static function certificate(): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $rel = "images/home/certificate.{$ext}";
            if (self::readable(public_path($rel))) {
                return asset($rel);
            }
        }

        return null;
    }

    /** Past recipient portraits (file name = caption), empty until supplied. */
    public static function recipients(): array
    {
        return collect(self::images('images/home/recipients'))
            ->map(fn ($size, $rel) => ['src' => asset($rel), 'caption' => trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', pathinfo($rel, PATHINFO_FILENAME))))])
            ->values()->all();
    }

    /** @return array<string, array{0: int, 1: int}> public-relative path => [width, height], readable images only, by name */
    private static function images(string $dir): array
    {
        $found = [];
        foreach (is_dir(public_path($dir)) ? (scandir(public_path($dir)) ?: []) : [] as $file) {
            $path = public_path("{$dir}/{$file}");
            if ($file[0] !== '.' && preg_match(self::IMAGE, $file) && ($size = self::readable($path))) {
                $found["{$dir}/{$file}"] = $size;
            }
        }
        ksort($found, SORT_NATURAL | SORT_FLAG_CASE);

        return $found;
    }

    /** @return array{0: int, 1: int}|null */
    private static function readable(string $path): ?array
    {
        $info = is_file($path) ? @getimagesize($path) : false;

        return $info && $info[0] > 0 ? [$info[0], $info[1]] : null;
    }
}
