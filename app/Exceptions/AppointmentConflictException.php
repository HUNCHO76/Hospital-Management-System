<?php

namespace App\Exceptions;

use App\Models\Appointment;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown when a practitioner is already booked during the requested time
 * window. Extending ConflictHttpException lets the global API exception
 * handler (bootstrap/app.php) render it as a 409 automatically; the Inertia
 * web controller catches it explicitly and converts it into a
 * ValidationException instead, so the message surfaces as a normal inline
 * form error rather than a generic error page.
 */
class AppointmentConflictException extends ConflictHttpException
{
    public function __construct(public readonly Appointment $conflictingAppointment, string $message = 'This practitioner is already booked during the selected time.')
    {
        parent::__construct($message);
    }
}
