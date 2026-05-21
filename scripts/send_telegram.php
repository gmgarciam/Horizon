#!/usr/bin/env php
<?php
/**
 * send_telegram.php
 * Reads the latest Horizon summary and pushes it to Telegram.
 * Run after `uv run horizon` in the GitHub Actions workflow.
 */

define('TELEGRAM_API', 'https://api.telegram.org/bot%s/%s');
define('MAX_CHUNK', 4000); // Telegram max is 4096; keep a small buffer

// ── Helpers ──────────────────────────────────────────────────────────────────

function getEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        echo "❌ Missing environment variable: {$name}\n";
        exit(1);
    }
    return $value;
}

/**
 * Split text into chunks without cutting in the middle of a line.
 *
 * @return string[]
 */
function chunkText(string $text, int $size = MAX_CHUNK): array
{
    $chunks  = [];
    $current = '';

    foreach (explode("\n", $text) as $line) {
        $lineWithNewline = $line . "\n";

        if (strlen($current) + strlen($lineWithNewline) > $size && $current !== '') {
            $chunks[]  = $current;
            $current   = '';
        }

        $current .= $lineWithNewline;
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

/**
 * Send a single message to Telegram via the Bot API.
 */
function sendMessage(string $token, string $chatId, string $text): void
{
    $url     = sprintf(TELEGRAM_API, $token, 'sendMessage');
    $payload = json_encode([
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'Markdown',
        'disable_web_page_preview' => false,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response   = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo "❌ cURL error: {$curlError}\n";
        exit(1);
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        echo "⚠️  Telegram API error [{$httpStatus}]: {$response}\n";
        exit(1);
    }
}

// ── Main ─────────────────────────────────────────────────────────────────────

$token  = getEnv('TELEGRAM_BOT_TOKEN');
$chatId = getEnv('TELEGRAM_CHAT_ID');

// Find the most recent summary file
$files = glob('data/summaries/*.md');
if (empty($files)) {
    echo "❌ No summary files found in data/summaries/\n";
    exit(1);
}

// Sort ascending; pick the last one
sort($files);
$latest = end($files);
echo "📄 Sending summary: {$latest}\n";

$content = file_get_contents($latest);
if ($content === false || trim($content) === '') {
    echo "❌ Summary file is empty or unreadable.\n";
    exit(1);
}

$chunks = chunkText($content);
$total  = count($chunks);
echo "📨 Sending {$total} message(s) to Telegram...\n";

foreach ($chunks as $i => $chunk) {
    // Prefix the first chunk with a header
    if ($i === 0) {
        $chunk = "🌅 *Horizon Daily Briefing*\n\n" . $chunk;
    }

    sendMessage($token, $chatId, $chunk);
    echo '   ✅ Sent chunk ' . ($i + 1) . "/{$total}\n";
}

echo "🎉 Done! Summary delivered to Telegram.\n";
