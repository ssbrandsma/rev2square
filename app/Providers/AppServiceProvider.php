<?php

namespace App\Providers;

use App\Services\AI\AIProviderInterface;
use App\Services\AI\MetaAIProvider;
use App\Services\GitHub\GitHubClient;
use App\Services\Revival\EvidenceManifestBuilder;
use App\Services\Revival\GitHubRepositoryImporter;
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
        $this->app->singleton(MetaAIProvider::class, fn () => MetaAIProvider::fromConfig());
        $this->app->bind(AIProviderInterface::class, function () {
            if (config('ai.default') === 'mock') {
                if (! class_exists(MockAIProvider::class)) {
                    throw new \RuntimeException('The mock AI provider is available for automated tests only.');
                }

                return new MockAIProvider;
            }

            return app(MetaAIProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
