<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Models\Project;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Explorer extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'phase')]
    public string $phase = '';

    #[Url(as: 'activity')]
    public string $activity = '';

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    public function mount(): void
    {
        // Query parameters are user input: coerce everything to known-good
        // values so shared/bookmarked URLs cannot break the listing.
        $this->sanitize();
    }

    public function updatedSearch(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedPhase(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedActivity(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->phase = '';
        $this->activity = '';
        $this->sort = 'newest';
        $this->resetPage();
    }

    public function getHasActiveFiltersProperty(): bool
    {
        return $this->search !== '' || $this->phase !== '' || $this->activity !== '' || $this->sort !== 'newest';
    }

    private function sanitize(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 100);

        if ($this->phase !== '' && ProjectPhase::tryFrom($this->phase) === null) {
            $this->phase = '';
        }

        if ($this->activity !== '' && ProjectActivity::tryFrom($this->activity) === null) {
            $this->activity = '';
        }

        if (! in_array($this->sort, ['newest', 'oldest', 'title'], true)) {
            $this->sort = 'newest';
        }
    }

    public function render()
    {
        $projects = Project::published()
            ->with(['devices', 'tasks'])
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('pitch', 'like', '%'.$this->search.'%')
                    ->orWhereHas('devices', fn ($query) => $query->where('model', 'like', '%'.$this->search.'%'));
            }))
            ->when($this->phase !== '', fn ($query) => $query->where('phase', $this->phase))
            ->when($this->activity !== '', fn ($query) => $query->where('activity', $this->activity))
            ->when($this->sort === 'oldest', fn ($query) => $query->oldest(), fn ($query) => $query->when(
                $this->sort === 'title',
                fn ($query) => $query->orderBy('title'),
                fn ($query) => $query->latest(),
            ))
            ->paginate(12);

        return view('livewire.projects.explorer', [
            'projects' => $projects,
            'phases' => ProjectPhase::cases(),
            'activities' => ProjectActivity::cases(),
        ]);
    }
}
