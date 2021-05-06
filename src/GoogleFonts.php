<?php

namespace Spatie\GoogleFonts;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class GoogleFonts
{
    public function __construct(
        private Filesystem $filesystem,
        private string $path,
        private string $userAgent,
        private bool $fallback,
    ) {}

    public function load(string $url, bool $force = false): Fonts {
        try {
            if (! $force && $fonts = $this->loadLocal($url)) {
                return $fonts;
            }

            return $this->loadFresh($url);
        } catch (Exception $exception) {
            if (! $this->fallback) {
                throw $exception;
            }

            return new Fonts(googleFontsUrl: $url);
        }
    }

    private function loadLocal(string $url): ?Fonts
    {
        if (! $this->filesystem->exists($this->path($url, 'fonts.css'))) {
            return null;
        }

        $localizedCss = $this->filesystem->get($this->path($url, 'fonts.css'));

        return new Fonts(
            googleFontsUrl: $url,
            localizedUrl: $this->filesystem->url($this->path($url, 'fonts.css')),
            localizedCss: $localizedCss,
        );
    }

    private function loadFresh(string $url): Fonts
    {
        $css = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->get($url)
            ->body();

        $localizedCss = $css;

        foreach ($this->extractFontUrls($css) as $fontUrl) {
            $localizedFontUrl = $this->localizeFontUrl($fontUrl);

            $this->filesystem->put(
                $this->path($url, $localizedFontUrl),
                Http::get($fontUrl)->body()
            );

            $localizedCss = str_replace(
                $fontUrl,
                $this->filesystem->url($this->path($url, $localizedFontUrl)),
                $localizedCss
            );
        }

        $this->filesystem->put($this->path($url, 'fonts.css'), $localizedCss);

        return new Fonts(
            googleFontsUrl: $url,
            localizedUrl: $this->filesystem->url($this->path($url, 'fonts.css')),
            localizedCss: $localizedCss,
        );
    }

    private function extractFontUrls(string $css): array
    {
        $matches = [];
        preg_match_all('/url\((https:\/\/fonts.gstatic.com\/[^)]+)\)/', $css, $matches);

        return $matches[1] ?? [];
    }

    private function localizeFontUrl(string $path): string
    {
        [$path, $extension] = explode('.', str_replace('https://fonts.gstatic.com/', '', $path));

        return implode('.', [Str::slug($path), $extension]);
    }

    private function path(string $url, string $path = ''): string
    {
        return $this->path . '/' . substr(md5($url), 0, 10) . '/' . $path;
    }
}