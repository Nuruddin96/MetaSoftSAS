<?php

namespace App\Support\Home;

/**
 * Real photos and documents for the Concept C homepage
 * (resources/views/central/home/*). Only files that exist are returned:
 * nothing is invented, and a section whose material hasn't been supplied
 * yet shows an honest fallback or stays hidden.
 *
 * public/images/home/programme/  — consultancy / programme photos (supplied 2026-10-18,
 *                                   web copies of public/images/Homepage/consultancy)
 * public/images/home/certificate.{jpg,png,webp} — company registration certificate (not supplied yet)
 * public/images/home/recipients/ — past award recipient portraits (not supplied yet)
 */
class HomeMedia
{
    private const ALT = 'Business consultancy session with entrepreneurs';

    /** @return list<array{src: string, alt: string, portrait: bool}> */
    public static function programme(): array
    {
        $photos = [];
        foreach (['consultancy-01', 'consultancy-03', 'consultancy-02', 'consultancy-06', 'consultancy-04', 'consultancy-07', 'consultancy-08', 'consultancy-09'] as $name) {
            $file = public_path("images/home/programme/{$name}.jpg");
            if (is_file($file)) {
                [$w, $h] = getimagesize($file) ?: [4, 3];
                $photos[] = ['src' => asset("images/home/programme/{$name}.jpg"), 'alt' => self::ALT, 'portrait' => $h > $w];
            }
        }

        return $photos;
    }

    /** Four photos for the strip that overlaps the hero. */
    public static function moments(): array
    {
        return array_slice(array_merge(array_slice(self::programme(), 3), self::programme()), 0, 4);
    }

    /** Consultancy & networking pairs: a wide main photo and a square detail. */
    public static function consultancy(): array
    {
        $by = collect(self::programme())->keyBy(fn ($p) => basename($p['src'], '.jpg'));

        return array_values(array_filter([
            isset($by['consultancy-01'], $by['consultancy-02']) ? [$by['consultancy-01'], $by['consultancy-02']] : null,
            isset($by['consultancy-03'], $by['consultancy-04']) ? [$by['consultancy-03'], $by['consultancy-04']] : null,
        ]));
    }

    /** The registration certificate scan, or null until it is supplied. */
    public static function certificate(): ?string
    {
        foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
            if (is_file(public_path("images/home/certificate.{$ext}"))) {
                return asset("images/home/certificate.{$ext}");
            }
        }

        return null;
    }

    /** Past recipient portraits (file name = caption), empty until supplied. */
    public static function recipients(): array
    {
        return collect(glob(public_path('images/home/recipients/*.{jpg,jpeg,png,webp}'), GLOB_BRACE) ?: [])
            ->map(fn ($f) => ['src' => asset('images/home/recipients/'.basename($f)), 'caption' => str_replace(['-', '_'], ' ', pathinfo($f, PATHINFO_FILENAME))])
            ->values()->all();
    }
}
