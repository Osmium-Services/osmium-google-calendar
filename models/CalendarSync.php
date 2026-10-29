<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleCalendar\Models;

/**
 * Hook handlers for the booking services. Each confirmed or changed booking becomes (or replaces) one Google event; a
 * declined or cancelled one removes it. The event id is derived from the booking ("appointment:7"), so the same booking
 * always lands on the same event and nothing has to be remembered. Nothing here calls the booking services' PHP; it
 * reads only the booking row in the hook payload.
 */
class CalendarSync
{
    public static function appointmentSaved(array $payload): void
    {
        self::save(kind: 'appointment', row: $payload['appointment'], id: (int) $payload['appointmentId']);
    }

    public static function appointmentRemoved(array $payload): void
    {
        self::remove(kind: 'appointment', id: (int) $payload['appointmentId']);
    }

    public static function hireSaved(array $payload): void
    {
        self::save(kind: 'hire', row: $payload['hire'], id: (int) $payload['hireId']);
    }

    public static function hireRemoved(array $payload): void
    {
        self::remove(kind: 'hire', id: (int) $payload['hireId']);
    }

    public static function reservationSaved(array $payload): void
    {
        self::save(kind: 'reservation', row: $payload['reservation'], id: (int) $payload['reservationId']);
    }

    public static function reservationRemoved(array $payload): void
    {
        self::remove(kind: 'reservation', id: (int) $payload['reservationId']);
    }

    /** 32 hex characters: Google accepts a caller-chosen id made of 0-9 and a-v */
    public static function eventId(string $kind, int $id): string
    {
        return \md5($kind . ':' . $id);
    }

    /**
     * The Google event for a booking row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function buildEvent(string $kind, array $row, string $timeZone): array
    {
        $event = match ($kind) {
            'hire' => self::hireEvent($row),
            'reservation' => self::reservationEvent($row, $timeZone),
            default => self::appointmentEvent($row, $timeZone),
        };
        $event['description'] = self::description($row);

        return $event;
    }

    private static function save(string $kind, array $row, int $id): void
    {
        $client = self::client();
        if ($client === null) return;

        $event = self::buildEvent(kind: $kind, row: $row, timeZone: \date_default_timezone_get());
        $client->upsert(eventId: self::eventId(kind: $kind, id: $id), event: $event);
    }

    private static function remove(string $kind, int $id): void
    {
        $client = self::client();
        if ($client === null) return;

        $client->remove(self::eventId(kind: $kind, id: $id));
    }

    public static function client(): ?CalendarClient
    {
        $notReady = !CalendarConfig::isReady();
        if ($notReady) return null; // Installed but not set up yet: bookings carry on as normal

        $config = CalendarConfig::get();

        return new CalendarClient(
            clientEmail: $config->clientEmail,
            privateKey: $config->privateKey,
            calendarId: $config->calendarId,
        );
    }

    private static function appointmentEvent(array $row, string $timeZone): array
    {
        $what = (string) ($row['type_name'] ?? 'Appointment');

        return [
            'summary' => $what . ' - ' . $row['customer_name'],
            'start' => self::timed($row['starts_at'], $timeZone),
            'end' => self::timed($row['ends_at'], $timeZone),
        ];
    }

    private static function reservationEvent(array $row, string $timeZone): array
    {
        return [
            'summary' => 'Table for ' . (int) $row['party_size'] . ' - ' . $row['customer_name'],
            'start' => self::timed($row['starts_at'], $timeZone),
            'end' => self::timed($row['ends_at'], $timeZone),
        ];
    }

    /** A hire is whole days: an all-day event, and Google's end date is the day after the last day */
    private static function hireEvent(array $row): array
    {
        $lastDay = new \DateTimeImmutable($row['ends_on']);
        $what = (string) ($row['group_name'] ?? 'Hire');

        return [
            'summary' => 'Hire: ' . $what . ' - ' . $row['customer_name'],
            'start' => ['date' => $row['starts_on']],
            'end' => ['date' => $lastDay->modify('+1 day')->format('Y-m-d')],
        ];
    }

    /** @return array{dateTime: string, timeZone: string} */
    private static function timed(string $local, string $timeZone): array
    {
        $time = new \DateTimeImmutable($local);

        return ['dateTime' => $time->format('Y-m-d\TH:i:s'), 'timeZone' => $timeZone];
    }

    private static function description(array $row): string
    {
        $lines = ['Reference ' . ($row['reference'] ?? '')];
        $lines[] = 'Email: ' . ($row['customer_email'] ?? '');
        $phone = (string) ($row['customer_phone'] ?? '');
        if ($phone !== '') $lines[] = 'Phone: ' . $phone;
        $item = (string) ($row['item_name'] ?? '');
        if ($item !== '') $lines[] = 'Item: ' . $item;
        $pickup = (string) ($row['pickup_details'] ?? '');
        if ($pickup !== '') $lines[] = 'Collection and return: ' . $pickup;
        $notes = (string) ($row['customer_notes'] ?? '');
        if ($notes !== '') $lines[] = 'Notes: ' . $notes;

        return \implode("\n", $lines);
    }
}
