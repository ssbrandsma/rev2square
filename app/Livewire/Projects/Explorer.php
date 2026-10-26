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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPhase(): void
    {
        $this->resetPage();
    }

    public function updatedActivity(): void
    {
        $this->resetPage();
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
            ->latest()
            ->paginate(12);

        return view('livewire.projects.explorer', [
            'projects' => $projects,
            'phases' => ProjectPhase::cases(),
            'activities' => ProjectActivity::cases(),
        ]);
    }
}
