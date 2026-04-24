<?php
/**
 * reminders.php — Google Calendar API + MySQL Database
 */

date_default_timezone_set('Asia/Kolkata'); // IST GMT+5:30

session_start();
require_once 'db.php';
require_once 'auth_helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

ensureValidToken();
$token  = $_SESSION['access_token'] ?? '';
$userId = $_SESSION['user']['id']   ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    if ($action === 'list') listEvents($token, $userId);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
    if      ($action === 'create') createEvent($token, $userId, $body);
    elseif  ($action === 'delete') deleteEvent($token, $userId, $body['event_id'] ?? '');
    else    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}

// ─── LIST EVENTS ─────────────────────────────────────────
function listEvents(string $token, int $userId): void {
    // Fetch from Google Calendar
    $timeMin = urlencode(date('c'));
    $timeMax = urlencode(date('c', strtotime('+90 days')));
    $url = "https://www.googleapis.com/calendar/v3/calendars/primary/events?"
         . "timeMin=$timeMin&timeMax=$timeMax&singleEvents=true&orderBy=startTime&maxResults=50";
    $resp = calendarGet($url, $token);

    if (isset($resp['error'])) {
        echo json_encode(['success' => false, 'error' => $resp['error']['message'] ?? 'API error']);
        return;
    }

    // Also get meet links from our DB
    $db   = getDB();
    $stmt = $db->prepare("SELECT google_event_id, meet_link FROM reminders WHERE user_id = ?");
    $stmt->execute([$userId]);
    $dbMeetLinks = [];
    foreach ($stmt->fetchAll() as $row) {
        $dbMeetLinks[$row['google_event_id']] = $row['meet_link'];
    }

    $items = $resp['items'] ?? [];
    $reminders = array_map(function($item) use ($dbMeetLinks) {
        $startRaw = $item['start']['dateTime'] ?? $item['start']['date'] ?? '';
        $endRaw   = $item['end']['dateTime']   ?? $item['end']['date']   ?? '';

        // Google already returns time with timezone offset — just convert to IST for display
        if ($startRaw) {
            $dt = new DateTime($startRaw); // has offset like +05:30 already
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $startRaw = $dt->format(DateTime::RFC3339); // e.g. 2026-03-30T13:00:00+05:30
        }
        if ($endRaw) {
            $dt = new DateTime($endRaw);
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $endRaw = $dt->format(DateTime::RFC3339);
        }

        // Get meet link from Calendar API response OR from DB
        $meetLink = '';
        if (!empty($item['conferenceData']['entryPoints'])) {
            foreach ($item['conferenceData']['entryPoints'] as $ep) {
                if (($ep['entryPointType'] ?? '') === 'video') {
                    $meetLink = $ep['uri'] ?? '';
                    break;
                }
            }
        }
        if (!$meetLink && isset($dbMeetLinks[$item['id']])) {
            $meetLink = $dbMeetLinks[$item['id']];
        }

        return [
            'id'          => $item['id'],
            'title'       => $item['summary'] ?? '(No title)',
            'description' => $item['description'] ?? '',
            'start'       => $startRaw,
            'end'         => $endRaw,
            'htmlLink'    => $item['htmlLink'] ?? '',
            'meet_link'   => $meetLink,
        ];
    }, $items);

    echo json_encode(['success' => true, 'reminders' => $reminders]);
}

// ─── CREATE EVENT ─────────────────────────────────────────
function createEvent(string $token, int $userId, array $body): void {
    $title       = trim($body['title']            ?? '');
    $description = trim($body['description']      ?? '');
    $date        = $body['date']                  ?? '';
    $time        = $body['time']                  ?? '';
    $duration    = (int)($body['duration']        ?? 60);
    $reminderMin = (int)($body['reminder_minutes']?? 15);
    $colorId     = $body['color_id']              ?? '7';
    $addMeet     = (bool)($body['add_meet']       ?? false);

    if (!$title || !$date || !$time) {
        echo json_encode(['success' => false, 'error' => 'Title, date and time are required.']);
        return;
    }

    $tz      = 'Asia/Kolkata';
    $startDt = new DateTime("$date $time", new DateTimeZone($tz));
    $endDt   = clone $startDt;
    $endDt->modify("+{$duration} minutes");

    $event = [
        'summary'     => $title,
        'description' => $description,
        'colorId'     => $colorId,
        'start'       => ['dateTime' => $startDt->format(DateTime::RFC3339), 'timeZone' => $tz],
        'end'         => ['dateTime' => $endDt->format(DateTime::RFC3339),   'timeZone' => $tz],
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'popup', 'minutes' => $reminderMin],
                ['method' => 'email', 'minutes' => $reminderMin],
            ]
        ],
    ];

    if ($addMeet) {
        $event['conferenceData'] = [
            'createRequest' => [
                'requestId'             => uniqid('meet_'),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]
        ];
    }

    $url  = 'https://www.googleapis.com/calendar/v3/calendars/primary/events'
          . ($addMeet ? '?conferenceDataVersion=1' : '');
    $resp = calendarPost($url, $token, $event);

    if (isset($resp['error'])) {
        echo json_encode(['success' => false, 'error' => $resp['error']['message'] ?? 'Failed to create event']);
        return;
    }

    // Extract Meet link
    $meetLink = '';
    if ($addMeet && !empty($resp['conferenceData']['entryPoints'])) {
        foreach ($resp['conferenceData']['entryPoints'] as $ep) {
            if (($ep['entryPointType'] ?? '') === 'video') {
                $meetLink = $ep['uri'] ?? '';
                break;
            }
        }
    }

    // ── Save to Database ──
    $db = getDB();
    $db->prepare("
        INSERT INTO reminders
            (user_id, google_event_id, title, description, event_date, event_time, duration, reminder_minutes, color_id, meet_link, html_link)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $userId,
        $resp['id'],
        $title,
        $description,
        $date,
        $time,
        $duration,
        $reminderMin,
        $colorId,
        $meetLink,
        $resp['htmlLink'] ?? '',
    ]);

    echo json_encode([
        'success'   => true,
        'event_id'  => $resp['id'],
        'htmlLink'  => $resp['htmlLink'] ?? '',
        'meet_link' => $meetLink,
        'message'   => $meetLink ? 'Event + Meet link created!' : 'Event added to Google Calendar!',
    ]);
}

// ─── DELETE EVENT ─────────────────────────────────────────
function deleteEvent(string $token, int $userId, string $eventId): void {
    if (!$eventId) {
        echo json_encode(['success' => false, 'error' => 'No event ID provided.']);
        return;
    }

    $url  = 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . urlencode($eventId);
    $resp = calendarDelete($url, $token);

    if ($resp['http_code'] === 204) {
        // Delete from DB too
        $db = getDB();
        $db->prepare("DELETE FROM reminders WHERE google_event_id = ? AND user_id = ?")
           ->execute([$eventId, $userId]);

        echo json_encode(['success' => true, 'message' => 'Event deleted.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not delete. Code: ' . $resp['http_code']]);
    }
}

// ─── HTTP HELPERS ─────────────────────────────────────────
function calendarGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true) ?? [];
}

function calendarPost(string $url, string $token, array $data): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($data),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token, 'Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true) ?? [];
}

function calendarDelete(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST=>'DELETE', CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['http_code' => $code];
}