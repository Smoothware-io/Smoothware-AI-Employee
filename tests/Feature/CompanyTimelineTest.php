<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Note;
use App\Models\Task;

it('anchors the company and its child-record events to one timeline', function () {
    $company = Company::factory()->create();
    Contact::factory()->for($company)->create();
    Note::factory()->for($company)->create();
    Task::factory()->for($company)->create()->start();

    $actions = Event::forCompanyTimeline($company->id)->pluck('action');

    expect($actions)->toContain('company.created')
        ->and($actions)->toContain('contact.created')
        ->and($actions)->toContain('note.created')
        ->and($actions)->toContain('task.created')
        ->and($actions)->toContain('task.status_changed');
});

it('does not leak one company\'s events into another\'s timeline', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    Note::factory()->for($b)->create();

    $anchors = Event::forCompanyTimeline($a->id)->pluck('company_id')->unique()->values();

    expect($anchors->all())->toBe([$a->id]);
});
