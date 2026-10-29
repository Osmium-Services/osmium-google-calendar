<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleCalendar\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\GoogleCalendar\Models\CalendarConfig;
use Osmium\Services\GoogleCalendar\Models\CalendarSync;

/**
 * Google Calendar settings: paste the service account's JSON key, set the calendar id, send a test event.
 *
 * Routes:
 *   - index() → /admin/settings/google-calendar/  (GET shows the form, POST saves it or sends the test event)
 */
class GoogleCalendarController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/google-calendar.json.php';

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $config = CalendarConfig::get();
        $this->data['admin']['calendar'] = [
            'clientEmail' => $config->clientEmail,
            'hasKey' => $config->privateKey !== '',
            'calendarId' => $config->calendarId,
        ];
        $this->data['admin']['settingsSaved'] = $_SESSION['gcal_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['gcal_error'] ?? false;
        $this->data['admin']['settingsNote'] = $_SESSION['gcal_note'] ?? false;
        unset($_SESSION['gcal_saved'], $_SESSION['gcal_error'], $_SESSION['gcal_note']);

        $this->setView('google-calendar/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->fail('Invalid form submission. Please try again.');

        $isTest = ($_POST['action'] ?? '') === 'test';
        if ($isTest) $this->sendTest();

        $this->save();
    }

    private function save(): void
    {
        $current = CalendarConfig::get();
        $clientEmail = $current->clientEmail;
        $privateKey = $current->privateKey;

        $pasted = \trim($_POST['key_json'] ?? '');
        $hasPasted = $pasted !== '';
        if ($hasPasted) [$clientEmail, $privateKey] = $this->readKeyFile($pasted); // Blank keeps the stored key

        $calendarId = \trim($_POST['calendar_id'] ?? '');
        $this->writeConfig(clientEmail: $clientEmail, privateKey: $privateKey, calendarId: $calendarId);

        $this->admin->model->changelog->log(description: 'Updated Google Calendar settings', recordType: 'settings');
        CalendarConfig::clearCache();

        $_SESSION['gcal_saved'] = true;
        $this->redirect('settings/google-calendar/');
    }

    /** @return array{0: string, 1: string} */
    private function readKeyFile(string $json): array
    {
        $decoded = \json_decode($json, associative: true);
        $email = \is_array($decoded) ? (string) ($decoded['client_email'] ?? '') : '';
        $key = \is_array($decoded) ? (string) ($decoded['private_key'] ?? '') : '';
        $unusable = $email === '' || $key === '';
        if ($unusable) $this->fail('That is not a service account JSON key (it needs client_email and private_key).');

        return [$email, $key];
    }

    /** Make a test event, then take it away again; the result is shown on the page */
    private function sendTest(): void
    {
        $client = CalendarSync::client();
        $notReady = $client === null;
        if ($notReady) $this->fail('Save the key and calendar id first.');

        $row = [
            'reference' => 'TEST',
            'customer_name' => 'Osmium test',
            'customer_email' => '',
            'starts_at' => \date('Y-m-d H:00:00', \strtotime('+1 hour')),
            'ends_at' => \date('Y-m-d H:00:00', \strtotime('+2 hours')),
            'type_name' => 'Test event',
        ];
        $event = CalendarSync::buildEvent(kind: 'appointment', row: $row, timeZone: \date_default_timezone_get());
        $eventId = CalendarSync::eventId(kind: 'test', id: 0);

        try {
            $client->upsert(eventId: $eventId, event: $event);
            $client->remove($eventId);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }

        $_SESSION['gcal_note'] = 'A test event was created on the calendar and removed again. Google Calendar is working.';
        $this->redirect('settings/google-calendar/');
    }

    private function fail(string $message): void
    {
        $_SESSION['gcal_error'] = $message;
        $this->redirect('settings/google-calendar/');
    }

    private function writeConfig(string $clientEmail, string $privateKey, string $calendarId): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['googleCalendar' => [
                'clientEmail' => $clientEmail,
                'privateKey' => $privateKey,
                'calendarId' => $calendarId,
            ]],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, "<?php exit(); ?>\n" . $json . "\n");
    }
}
