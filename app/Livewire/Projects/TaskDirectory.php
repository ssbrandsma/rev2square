<?php

namespace App\Livewire\Projects;

use App\Enums\TaskStatus;
use App\Models\ContributorTask;
use App\Models\Project;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class TaskDirectory extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'skill')]
    public string $skill = '';

    #[Url(as: 'hardware')]
    public string $hardware = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'project')]
    public string $project = '';

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    public function mount(): void
    {
        $this->sanitize();
    }

    public function updatedSearch(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedSkill(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedHardware(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    public function updatedProject(): void
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
        $this->skill = '';
        $this->hardware = '';
        $this->status = '';
        $this->project = '';
        $this->sort = 'newest';
        $this->resetPage();
    }

    public function getHasActiveFiltersProperty(): bool
    {
        return $this->search !== '' || $this->skill !== '' || $this->hardware !== ''
            || $this->status !== '' || $this->project !== '' || $this->sort !== 'newest';
    }

    private function sanitize(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 100);
        $this->skill = mb_substr(trim($this->skill), 0, 50);

        if (! in_array($this->hardware, ['', 'yes', 'no'], true)) {
            $this->hardware = '';
        }

        if ($this->status !== '' && TaskStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }

        if ($this->project !== '' && ! Project::published()->where('slug', $this->project)->exists()) {
            $this->project = '';
        }

        if (! in_array($this->sort, ['newest', 'oldest', 'title'], true)) {
            $this->sort = 'newest';
        }
    }

    public function render()
    {
        // Only active tasks on published projects. Draft projects and
        // retired tasks are never listed here.
        $tasks = ContributorTask::query()
            ->with(['project', 'milestone'])
            ->whereHas('project', fn ($query) => $query->published())
            ->active()
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('description', 'like', '%'.$this->search.'%');
            }))
            ->when($this->skill !== '', function ($query) {
                $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($this->skill));
                $query->whereRaw('LOWER(skills) LIKE ?', ['%"'.$needle.'"%']);
            })
            ->when($this->hardware === 'yes', fn ($query) => $query->where('requires_hardware', true))
            ->when($this->hardware === 'no', fn ($query) => $query->where('requires_hardware', false))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->project !== '', fn ($query) => $query->whereHas(
                'project',
                fn ($query) => $query->where('slug', $this->project)
            ))
            ->when($this->sort === 'oldest', fn ($query) => $query->oldest(), fn ($query) => $query->when(
                $this->sort === 'title',
                fn ($query) => $query->orderBy('title'),
                fn ($query) => $query->latest(),
            ))
            ->paginate(15);

        return view('livewire.projects.task-directory', [
            'tasks' => $tasks,
            'statuses' => collect(TaskStatus::cases())->reject(fn (TaskStatus $status): bool => $status === TaskStatus::Retired),
            'projects' => Project::published()->orderBy('title')->get(['slug', 'title']),
        ]);
    }
}
