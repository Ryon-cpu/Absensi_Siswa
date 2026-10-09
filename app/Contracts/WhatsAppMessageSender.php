<?php

namespace App\Contracts;

use App\Models\AttendanceNotification;

interface WhatsAppMessageSender
{
    /**
     * Return the provider-confirmed message ID, or throw if delivery failed.
     */
    public function send(AttendanceNotification $notification): string;
}
