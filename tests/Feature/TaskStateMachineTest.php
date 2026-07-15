<?php

use App\Enums\TaskStatus;
use App\Exceptions\InvalidTaskTransition;
use App\Models\Event;
use App\Models\Task;

function statusChangedCount(Task $task): int
{
    return Event::query()
        ->where('entity_type', $task->getMorphClass())
        ->where('entity_id', $task->id)
        ->where('action', 'task.status_changed')
        ->count();
}

it('walks the happy path open -> in_progress -> completed', function () {
    $task = Task::factory()->create();
    expect($task->status)->toBe(TaskStatus::Open);

    $task->start();
    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);

    $task->complete();
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->completed_at)->not->toBeNull();
});

it('clears completed_at when a completed task is reopened', function () {
    $task = Task::factory()->create();
    $task->complete();
    expect($task->fresh()->completed_at)->not->toBeNull();

    $task->reopen();
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Open)
        ->and($task->completed_at)->toBeNull();
});

it('rejects an illegal transition (open cannot jump to blocked)', function () {
    $task = Task::factory()->create();

    expect(fn () => $task->transitionTo(TaskStatus::Blocked))
        ->toThrow(InvalidTaskTransition::class);
});

it('will not restart a completed task (only reopen is allowed)', function () {
    $task = Task::factory()->create();
    $task->complete();

    expect(fn () => $task->start())->toThrow(InvalidTaskTransition::class);
});

it('is a no-op (and logs nothing) when the target equals the current status', function () {
    $task = Task::factory()->create();

    $task->transitionTo(TaskStatus::Open);

    expect(statusChangedCount($task))->toBe(0);
});

it('emits exactly one status_changed event and no generic update event', function () {
    $task = Task::factory()->create();

    $task->start();

    expect(statusChangedCount($task))->toBe(1)
        ->and(Event::where('entity_id', $task->id)->where('action', 'task.updated')->count())->toBe(0);
});

it('records a reason on block and cancel', function () {
    $task = Task::factory()->create();
    $task->start();

    $task->block('waiting on client budget approval');
    expect($task->fresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->fresh()->status_reason)->toBe('waiting on client budget approval');

    $task->unblock();
    $task->cancel('deal lost to competitor');
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});
