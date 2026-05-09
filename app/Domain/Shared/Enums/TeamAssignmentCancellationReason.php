<?php

namespace App\Domain\Shared\Enums;

enum TeamAssignmentCancellationReason: string
{
    case MechanicalIssue = 'mechanical_issue';
    case ReroutedHigherPriority = 'rerouted_higher_priority';
    case SafetyRisk = 'safety_risk';
    case NoContact = 'no_contact';
    case ResourceUnavailable = 'resource_unavailable';
    case IncorrectDispatch = 'incorrect_dispatch';
    case Other = 'other';
}
