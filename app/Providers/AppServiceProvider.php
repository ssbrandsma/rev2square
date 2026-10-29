<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Admin\Permissions;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\MetaAIProvider;
use App\Services\AI\MeteredAIProvider;
use App\Services\GitHub\ArchiveDownloader;
use App\Services\GitHub\GitHubClient;
use App\Services\Revival\ArchiveRepositoryImporter;
use App\Services\Revival\EvidenceManifestBuilder;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Tests\Support\MockAIProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GitHubClient::class, fn () => GitHubClient::fromConfig());
        $this->app->singleton(EvidenceManifestBuilder::class);
        $this->app->singleton(GitHubRepositoryImporter::class, fn ($app) => new GitHubRepositoryImporter(
            $app->make(GitHubClient::class),
            $app->make(EvidenceManifestBuilder::class),
        ));
        $this->app->singleton(ArchiveDownloader::class, fn () => ArchiveDownloader::fromConfig());
        $this->app->singleton(ArchiveRepositoryImporter::class, fn ($app) => new ArchiveRepositoryImporter(
            $app->make(GitHubClient::class),
            $app->make(ArchiveDownloader::class),
            $app->make(EvidenceManifestBuilder::class),
        ));
        $this->app->singleton(MetaAIProvider::class, fn () => MetaAIProvider::fromConfig());
        $this->app->bind(AIProviderInterface::class, function () {
            if (config('ai.default') === 'mock') {
                if (! class_exists(MockAIProvider::class)) {
                    throw new \RuntimeException('The mock AI provider is available for automated tests only.');
                }

                return new MeteredAIProvider(new MockAIProvider);
            }

            return new MeteredAIProvider(app(MetaAIProvider::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production is served over HTTPS (TLS terminates at the Caddy
        // proxy). Force generated URLs to https; the HTTP->HTTPS redirect
        // itself lives in the proxy, not in application middleware, so
        // unauthenticated healthchecks keep working over plain HTTP inside
        // the Compose network.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Global role gates. Suspended users fail every gate; project
        // powers stay separate (see ProjectPolicy).
        Gate::define('admin', fn (?User $user) => Permissions::allows($user, 'admin.access'));
        Gate::define('moderate', fn (?User $user) => Permissions::allows($user, 'moderate.reports'));
        Gate::define('view-my-usage', fn (?User $viewer, User $target) => $viewer !== null && ! $viewer->isSuspended() && $viewer->id === $target->id);

        // Database-managed settings override file config unless the
        // matching environment variable locks the value. Runs on every
        // boot (web request and each queue job), so admin changes
        // propagate to workers without restarts or root access.
        AppSettings::applyToConfig();
    }
}
