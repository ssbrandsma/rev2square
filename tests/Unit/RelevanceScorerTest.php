<?php

namespace Tests\Unit;

use App\Services\Revival\RelevanceScorer;
use PHPUnit\Framework\TestCase;

class RelevanceScorerTest extends TestCase
{
    private function entry(string $path, array $overrides = []): array
    {
        return array_merge([
            'path' => $path,
            'extension' => pathinfo($path, PATHINFO_EXTENSION) ?: null,
            'language' => null,
            'kind' => 'source',
            'size_bytes' => 5000,
            'subsystem' => str_contains($path, '/') ? explode('/', $path)[0] : '(root)',
            'overview' => false,
        ], $overrides);
    }

    private function profile(array $entries): array
    {
        return RelevanceScorer::profile($entries);
    }

    public function test_prioritizes_readme_hardware_protocol_and_entry_points(): void
    {
        $entries = [
            $this->entry('README.md', ['kind' => 'readme', 'language' => null]),
            $this->entry('tools/libconcord/src/protocol.h', ['language' => 'C/C++']),
            $this->entry('tools/libconcord/src/remote.cpp', ['language' => 'C++']),
            $this->entry('app/main.py', ['language' => 'Python']),
            $this->entry('app/utils.py', ['language' => 'Python']),
            $this->entry('package-lock.json', ['kind' => 'lockfile', 'language' => 'JSON']),
        ];
        $profile = $this->profile($entries);
        $scores = [];

        foreach ($entries as $entry) {
            $scores[$entry['path']] = RelevanceScorer::score($entry, $profile)['score'];
        }

        $this->assertGreaterThan($scores['app/utils.py'], $scores['README.md']);
        $this->assertGreaterThan($scores['app/utils.py'], $scores['tools/libconcord/src/protocol.h']);
        $this->assertGreaterThan($scores['app/utils.py'], $scores['tools/libconcord/src/remote.cpp']);
        $this->assertGreaterThan($scores['app/utils.py'], $scores['app/main.py']);
        $this->assertGreaterThan($scores['package-lock.json'], $scores['app/utils.py']);
    }

    public function test_adapts_to_repository_language_mix(): void
    {
        // C-majority repository: C entry points must not lose to Python utils.
        $cEntries = [
            $this->entry('src/main.c', ['language' => 'C', 'size_bytes' => 200000]),
            $this->entry('src/hal.c', ['language' => 'C', 'size_bytes' => 200000]),
            $this->entry('ui/app.js', ['language' => 'JavaScript', 'size_bytes' => 50000]),
            $this->entry('scripts/util.py', ['language' => 'Python', 'size_bytes' => 1000]),
        ];
        $cProfile = $this->profile($cEntries);

        $main = RelevanceScorer::score($cEntries[0], $cProfile);
        $util = RelevanceScorer::score($cEntries[3], $cProfile);

        $this->assertGreaterThan($util['score'], $main['score']);
        $this->assertContains('core repository language', $main['reasons']);
        $this->assertNotContains('core repository language', $util['reasons']);

        // Python-majority repository: the preference flips symmetrically.
        $pyEntries = [
            $this->entry('app/main.py', ['language' => 'Python', 'size_bytes' => 200000]),
            $this->entry('app/util.py', ['language' => 'Python', 'size_bytes' => 200000]),
            $this->entry('native/helper.c', ['language' => 'C', 'size_bytes' => 1000]),
        ];
        $pyProfile = $this->profile($pyEntries);

        $pyMain = RelevanceScorer::score($pyEntries[0], $pyProfile);
        $cHelper = RelevanceScorer::score($pyEntries[2], $pyProfile);

        $this->assertGreaterThan($cHelper['score'], $pyMain['score']);
    }

    public function test_rewards_readme_references_tests_and_clusters(): void
    {
        $entries = [
            $this->entry('tools/libconcord/src/libconcord.cpp', ['language' => 'C++']),
            $this->entry('tools/libconcord/src/libconcord.h', ['language' => 'C/C++']),
            $this->entry('tests/test_remote.py', ['language' => 'Python']),
            $this->entry('docs/notes.md', ['kind' => 'doc', 'language' => 'Markdown']),
        ];
        $profile = $this->profile($entries);

        $cluster = RelevanceScorer::score($entries[0], $profile);
        $this->assertContains('implementation cluster (sources + headers)', $cluster['reasons']);

        $test = RelevanceScorer::score($entries[2], $profile);
        $this->assertContains('test revealing device behavior', $test['reasons']);

        $unreferenced = RelevanceScorer::score($entries[3], $profile)['score'];
        $referenced = RelevanceScorer::score($entries[3], $profile, ['docs/notes.md'])['score'];
        $this->assertGreaterThan($unreferenced, $referenced);
    }

    public function test_scoring_is_deterministic(): void
    {
        $entries = [
            $this->entry('b.py', ['language' => 'Python']),
            $this->entry('a.py', ['language' => 'Python']),
        ];

        $first = RelevanceScorer::score($entries[0], $this->profile($entries));
        $second = RelevanceScorer::score($entries[0], $this->profile(array_reverse($entries)));

        $this->assertSame($first, $second);
    }
}
