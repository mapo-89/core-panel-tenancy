<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Wayfinder;

use Illuminate\Filesystem\Filesystem;

final readonly class WayfinderRouteUrlNormalizer
{
    public function __construct(private Filesystem $files) {}

    public function normalize(?string $basePath = null): void
    {
        $root = $basePath ?? base_path();
        $routesPath = $root.'/resources/js/routes';

        if (! $this->files->isDirectory($routesPath)) {
            return;
        }

        $hosts = $this->hosts();

        if ($hosts === []) {
            return;
        }

        $search = [];

        foreach ($hosts as $host) {
            $search[] = 'https://'.$host;
            $search[] = 'http://'.$host;
            $search[] = '//'.$host;
        }

        foreach ($this->files->allFiles($routesPath) as $file) {
            if ($file->getExtension() !== 'ts') {
                continue;
            }

            $contents = $this->files->get($file->getPathname());
            $normalizedContents = str_replace($search, '', $contents);

            if ($normalizedContents === $contents) {
                continue;
            }

            $this->files->put($file->getPathname(), $normalizedContents);
        }
    }

    /**
     * @return list<string>
     */
    private function hosts(): array
    {
        $hosts = [];
        $appUrl = (string) config('app.url', '');

        if ($appUrl !== '') {
            $parsedAppHost = parse_url($appUrl, PHP_URL_HOST);
            $parsedAppPort = parse_url($appUrl, PHP_URL_PORT);

            if (is_string($parsedAppHost) && $parsedAppHost !== '') {
                $hosts[] = $parsedAppPort !== null
                    ? $parsedAppHost.':'.$parsedAppPort
                    : $parsedAppHost;
            }
        }

        foreach ((array) config('tenancy.central_domains', []) as $domain) {
            if (is_string($domain) && $domain !== '') {
                $hosts[] = $domain;
            }
        }

        $hosts = array_values(array_unique($hosts));
        sort($hosts);

        return $hosts;
    }
}
