<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CompanyStatus;
use App\Enums\NoteCategory;
use App\Enums\TaskType;
use App\Models\Appointment;
use App\Models\Call;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Optional demo data so the panel isn't empty on first run:
 *   php artisan db:seed --class=DemoSeeder
 * Not part of DatabaseSeeder — safe to skip in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'admin@smoothware.test')->first();

        $company = Company::create([
            'name' => 'De Vries Interieur BV',
            'domain' => 'devriesinterieur.nl',
            'email' => 'info@devriesinterieur.nl',
            'phone' => '+31201234567',
            'city' => 'Amsterdam',
            'country' => 'NL',
            'industry' => 'Retail',
            'status' => CompanyStatus::Qualified,
            'owner_id' => $owner?->id,
            'created_by' => $owner?->id,
        ]);

        Contact::create([
            'company_id' => $company->id,
            'first_name' => 'Sanne',
            'last_name' => 'de Vries',
            'job_title' => 'Managing Director',
            'is_decision_maker' => true,
            'email' => 'sanne@devriesinterieur.nl',
            'phone' => '+31612345678',
            'created_by' => $owner?->id,
        ]);

        Note::create([
            'company_id' => $company->id,
            'category' => NoteCategory::Meeting,
            'body' => '<p>Intro call: interested in a new website + basic SEO. Budget not yet confirmed.</p>',
            'created_by' => $owner?->id,
        ]);

        Task::create([
            'company_id' => $company->id,
            'type' => TaskType::SendProposal,
            'title' => 'Send website + SEO proposal',
            'due_at' => now()->addDays(3),
            'assigned_to' => $owner?->id,
            'created_by' => $owner?->id,
        ]);

        // A task walked through the state machine, to populate the timeline.
        Task::create([
            'company_id' => $company->id,
            'type' => TaskType::CallBack,
            'title' => 'Follow up after intro call',
            'assigned_to' => $owner?->id,
            'created_by' => $owner?->id,
        ])->start()->complete();

        Appointment::create([
            'company_id' => $company->id,
            'title' => 'Proposal review meeting',
            'starts_at' => now()->addDays(5)->setTime(10, 0),
            'ends_at' => now()->addDays(5)->setTime(11, 0),
            'location' => 'Google Meet',
            'status' => AppointmentStatus::Scheduled,
            'organizer_id' => $owner?->id,
            'created_by' => $owner?->id,
        ]);

        Call::create([
            'company_id' => $company->id,
            'direction' => CallDirection::Inbound,
            'status' => CallStatus::Completed,
            'from_number' => '+31612345678',
            'to_number' => '+31201234567',
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addMinutes(6),
            'duration_seconds' => 360,
            'handled_by' => $owner?->id,
            'created_by' => $owner?->id,
        ]);
    }
}
