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
        $this->normalizeControllerActionExports($root.'/resources/js/actions');

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

    private function normalizeControllerActionExports(string $actionsPath): void
    {
        if (! $this->files->isDirectory($actionsPath)) {
            return;
        }

        foreach ($this->files->allFiles($actionsPath) as $file) {
            if ($file->getExtension() !== 'ts') {
                continue;
            }

            $contents = $this->files->get($file->getPathname());
            $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";

            if (preg_match('/^export default (?<controller>[A-Za-z_$][A-Za-z0-9_$]*)(?=\r?$)/m', $contents, $export) !== 1) {
                continue;
            }

            $controller = $export['controller'];
            preg_match_all(
                '/^'.preg_quote($controller, '/').'\.(?<property>[A-Za-z_$][A-Za-z0-9_$]*) = (?<value>[A-Za-z_$][A-Za-z0-9_$]*)(?=\r?$)/m',
                $contents,
                $assignments,
                PREG_SET_ORDER,
            );

            $methods = [];

            foreach ($assignments as $assignment) {
                if ($assignment['property'] !== $assignment['value']) {
                    continue;
                }

                $methods[] = $assignment['property'];
                $contents = str_replace($assignment[0].$lineEnding, '', $contents);
            }

            if ($methods === []) {
                continue;
            }

            $methodProperties = implode($lineEnding, array_map(
                static fn (string $method): string => '    '.$method.',',
                array_values(array_unique($methods)),
            ));
            $normalizedExport = "export default Object.assign({$controller}, {".$lineEnding
                .$methodProperties.$lineEnding
                .'})';
            $normalizedContents = str_replace($export[0], $normalizedExport, $contents);

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
