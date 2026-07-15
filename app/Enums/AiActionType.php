<?php

namespace App\Enums;

/**
 * Known AI action types. This is a convenience/type-safety aid for callers;
 * the database column is a plain string so future phases can introduce new
 * action types without a schema change. Add cases here as phases land.
 */
enum AiActionType: string
{
    // Phase 3 — receptionist
    case CreateCompany = 'create_company';
    case CreateContact = 'create_contact';
    case CreateNote = 'create_note';
    case CreateTask = 'create_task';
    case ScheduleAppointment = 'schedule_appointment';

    // Phase 4 — company analysis
    case CompanyAnalysis = 'company_analysis';

    // Phase 6 — outbound
    case OutboundCallDraft = 'outbound_call_draft';
}
