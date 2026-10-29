<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleCalendar\Models;

/**
 * Google Calendar configuration: the service account's email and private key (from its JSON key file) and the calendar
 * id. File-based (app/config/services/google-calendar.json.php), like the other services.
 */
class CalendarConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/google-calendar.json.php';

    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) return self::$config = self::defaults();

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) return self::$config = self::defaults();

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $saved = (array) ($decoded->googleCalendar ?? []);

        return self::$config = (object) \array_merge((array) self::defaults(), $saved);
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /** Can events be sent right now: a key, its email and a calendar are all set */
    public static function isReady(): bool
    {
        $config = self::get();
        $hasKey = $config->privateKey !== '' && $config->clientEmail !== '';

        return $hasKey && $config->calendarId !== '';
    }

    private static function defaults(): object
    {
        return (object) [
            'clientEmail' => '',
            'privateKey' => '',
            'calendarId' => '',
        ];
    }
}
