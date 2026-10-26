<?php

namespace App\Providers;

use App\Services\GitHub\GitHubClient;
use App\Services\Revival\EvidenceManifestBuilder;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
