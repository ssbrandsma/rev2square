<?php

namespace Tests\Unit;

use App\Services\GitHub\GitHubUrl;
use App\Services\GitHub\InvalidGitHubUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubUrlTest extends TestCase
{
    public function test_parses_plain_https_url(): void
    {
        $this->assertSame(
            ['owner' => 'ssbrandsma', 'name' => 'bublcam-revive'],
            GitHubUrl::parse('https://github.com/ssbrandsma/bublcam-revive')
        );
    }

    public function test_parses_url_variants(): void
    {
        $expected = ['owner' => 'octo', 'name' => 'widgets'];

        $this->assertSame($expected, GitHubUrl::parse('https://github.com/octo/widgets/'));
        $this->assertSame($expected, GitHubUrl::parse('https://github.com/octo/widgets.git'));
        $this->assertSame($expected, GitHubUrl::parse('https://github.com/octo/widgets/tree/main/docs'));
        $this->assertSame($expected, GitHubUrl::parse('http://github.com/octo/widgets'));
        $this->assertSame($expected, GitHubUrl::parse('  https://github.com/Octo/Widgets  '));
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_invalid_urls(string $url): void
    {
        $this->expectException(InvalidGitHubUrlException::class);

        GitHubUrl::parse($url);
    }

    /** @return array<string, list<string>> */
    public static function invalidUrls(): array
    {
        return [
            'wrong host' => ['https://gitlab.com/octo/widgets'],
            'lookalike host' => ['https://github.com.evil.test/octo/widgets'],
            'missing repo' => ['https://github.com/octo'],
            'empty' => [''],
            'not a url' => ['octo/widgets'],
            'bad owner chars' => ['https://github.com/oct o/widgets'],
            'bad name chars' => ['https://github.com/octo/wid gets'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    public function test_canonical_form(): void
    {
        $this->assertSame(
            'https://github.com/octo/widgets',
            GitHubUrl::canonical('Octo', 'Widgets')
        );
    }
}
