<?php

namespace App\Services\Receptionist;

use App\Enums\NoteCategory;
use App\Enums\RecordSource;
use App\Enums\TaskType;
use App\Models\AiAction;
use App\Models\Call;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Services\AiActionService;

/**
 * Applies an approved "receptionist_intake" draft: creates (or links) the
 * Company, then the Contact / Note / Task, and links the call — all in the one
 * transaction {@see AiActionService::apply()} wraps. Every created record is
 * tagged source=Ai + ai_action_id, so the UI badges it as AI-generated and it
 * traces back to this approval (principles #2/#3). Called from the review queue.
 */
class ReceptionistActionApplier
{
    public function __construct(private AiActionService $actions) {}

    public function approve(AiAction $action, User $reviewer): AiAction
    {
        return $this->actions->approveAndApply($action, $reviewer, fn (AiAction $a): ?Company => $this->build($a));
    }

    public function reject(AiAction $action, User $reviewer, string $reason): AiAction
    {
        return $this->actions->reject($action, $reviewer, $reason);
    }

    private function build(AiAction $action): ?Company
    {
        $payload = $action->proposed_payload;
        $companyData = $payload['company'] ?? [];

        $company = ! empty($companyData['match_id'])
            ? Company::find($companyData['match_id'])
            : null;

        $company ??= Company::create([
            'name' => $companyData['name'] ?: 'Unknown (from inbound call)',
            'phone' => $companyData['phone'] ?? null,
            'source' => RecordSource::Ai,
            'ai_action_id' => $action->id,
        ]);

        $contact = null;
        if (! empty($payload['contact'])) {
            $contact = Contact::create([
                'company_id' => $company->id,
                'first_name' => $payload['contact']['first_name'] ?: 'Unknown',
                'last_name' => $payload['contact']['last_name'] ?? null,
                'phone' => $payload['contact']['phone'] ?? null,
                'source' => RecordSource::Ai,
                'ai_action_id' => $action->id,
            ]);
        }

        if (! empty($payload['note'])) {
            Note::create([
                'company_id' => $company->id,
                'category' => NoteCategory::tryFrom($payload['note']['category'] ?? '') ?? NoteCategory::FollowUp,
                'body' => $payload['note']['body'] ?? '',
                'source' => RecordSource::Ai,
                'ai_action_id' => $action->id,
            ]);
        }

        if (! empty($payload['task'])) {
            Task::create([
                'company_id' => $company->id,
                'type' => TaskType::tryFrom($payload['task']['type'] ?? '') ?? TaskType::FollowUp,
                'title' => $payload['task']['title'] ?? 'Follow up on inbound call',
                'source' => RecordSource::Ai,
                'ai_action_id' => $action->id,
            ]);
        }

        // Link the (factual) call to the resolved company/contact.
        if (! empty($payload['call_id']) && ($call = Call::find($payload['call_id']))) {
            $call->forceFill([
                'company_id' => $company->id,
                'contact_id' => $contact?->id,
            ])->saveQuietly();
        }

        return $company;
    }
}
