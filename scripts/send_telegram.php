#!/usr/bin/env php
<?php
/**
 * send_telegram.php
 *
 * Flow:
 *  1. Read raw news data from Horizon's output (data/summaries/)
 *  2. Send it to Manus API (task.create) for AI summarization
 *  3. Poll until Manus finishes
 *  4. Send the structured result to Telegram
 */

define('MANUS_BASE',    'https://api.manus.ai/v2');
define('TELEGRAM_API',  'https://api.telegram.org/bot%s/%s');
define('MAX_CHUNK',     4000);   // Telegram max is 4096
define('POLL_INTERVAL', 5);       // seconds between polls      // seconds between polls
define('POLL_MAX',      120);  // ~10 min

// ── Helpers ──────────────────────────────────────────────────────────────────

function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        echo "❌ Missing environment variable: {$name}\n";
        exit(1);
    }
    return $value;
}

function curlRequest(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo "❌ cURL error: {$curlError}\n";
        exit(1);
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        echo "❌ Failed to decode response (HTTP {$httpCode}): {$response}\n";
        exit(1);
    }

    return ['code' => $httpCode, 'body' => $decoded];
}

function manusHeaders(string $apiKey): array
{
    return [
        'Content-Type: application/json',
        "x-manus-api-key: {$apiKey}",
    ];
}

// ── Step 1: Read Horizon's raw summary ───────────────────────────────────────

function getLatestSummary(): string
{
    $files = glob('data/summaries/*.md');
    if (empty($files)) {
        echo "❌ No summary files found in data/summaries/\n";
        exit(1);
    }
    sort($files);
    $latest = end($files);
    echo "📄 Using summary: {$latest}\n";

    $content = file_get_contents($latest);
    if ($content === false || trim($content) === '') {
        echo "❌ Summary file is empty or unreadable.\n";
        exit(1);
    }
    return $content;
}

// ── Step 2: Create Manus task ─────────────────────────────────────────────────

function createManusTask(string $apiKey, string $newsContent): string
{
    echo "🤖 Sending news to Manus for summarization...\n";

    $prompt = <<<PROMPT
You are an AI industry analyst preparing a daily executive briefing. Below is today's raw news digest collected from sources including OpenAI, Google AI, Anthropic, Hugging Face, MIT Technology Review, The Verge, Ars Technica, MarkTechPost, and Hacker News.

Please process it and return a polished, well-structured AI news briefing suitable for a Telegram message. Follow these rules:

1. Group stories into these sections (use these exact headers, skip empty ones):
   🚀 NEW MODELS & RELEASES — new AI models, product launches, major updates
   🔬 RESEARCH & BREAKTHROUGHS — papers, benchmarks, novel techniques
   🏢 INDUSTRY & BUSINESS — funding, acquisitions, partnerships, strategy moves
   ⚖️ REGULATION & ETHICS — policy, safety, governance, legal developments
   🛠️ TOOLS & DEVELOPER — frameworks, APIs, open source, tutorials

2. Under each section, list 3-5 of the most important stories with a one-line bold headline and a 2-3 sentence summary.
3. Skip any low-quality, duplicate, or non-AI items.
4. Keep the tone professional but readable — like a morning briefing for a busy executive who invests in AI.
5. End with a "🔮 One to Watch" — a single emerging trend or under-the-radar story worth keeping an eye on.
6. If a section has no relevant stories, skip it entirely.

RAW NEWS DIGEST:
{$newsContent}
PROMPT;

    $payload = [
        'message' => [
            'content' => $prompt,
        ],
        'title'                   => 'Executive Daily Briefing - ' . date('Y-m-d'),
        'agent_profile'           => 'manus-1.6',
        'interactive_mode'        => false,
        'hide_in_task_list'       => true,
        'structured_output_schema' => [
            'type'       => 'object',
            'properties' => [
                'summary' => [
                    'type'        => 'string',
                    'description' => 'The full formatted AI news briefing ready to send to Telegram, with sections for Models, Research, Industry, Regulation, and Tools',
                ],
                'headline' => [
                    'type'        => 'string',
                    'description' => 'The single most important AI headline of the day',
                ],
                'article_count' => [
                    'type'        => 'integer',
                    'description' => 'Total number of news items included across all sections',
                ],
            ],
            'required'             => ['summary', 'headline', 'article_count'],
            'additionalProperties' => false,
        ],
    ];

    $result = curlRequest('POST', MANUS_BASE . '/task.create', manusHeaders($apiKey), $payload);

    if ($result['code'] !== 200 || empty($result['body']['ok'])) {
        $error = $result['body']['error']['message'] ?? json_encode($result['body']);
        echo "❌ Manus task creation failed: {$error}\n";
        exit(1);
    }

    $taskId = $result['body']['task_id'];
    echo "✅ Manus task created: {$taskId}\n";
    return $taskId;
}

// ── Step 3: Poll until Manus is done ─────────────────────────────────────────

function pollManusTask(string $apiKey, string $taskId): array
{
    echo "⏳ Polling Manus for results";

    for ($attempt = 1; $attempt <= POLL_MAX; $attempt++) {
        sleep(POLL_INTERVAL);
        echo '.';

        // Check task status first
        $detail = curlRequest(
            'GET',
            MANUS_BASE . "/task.detail?task_id={$taskId}",
            manusHeaders($apiKey)
        );

        $status = $detail['body']['task']['status'] ?? $detail['body']['status'] ?? 'unknown';
        echo " [{$status}]";

        if ($status === 'failed' || $status === 'error') {
            echo "\n❌ Manus task failed with status: {$status}\n";
            echo "Full response: " . json_encode($detail['body']) . "\n";
            exit(1);
        }

        if (in_array($status, ['completed', 'finished', 'done', 'stopped'])) {
            echo "\n✅ Manus task completed!\n";

            // Fetch messages to get the structured output
            $messages = curlRequest(
                'GET',
                MANUS_BASE . "/task.listMessages?task_id={$taskId}&order=desc&limit=10",
                manusHeaders($apiKey)
            );

            return $messages['body'];
        }
    }

    echo "\n❌ Timed out waiting for Manus (tried " . POLL_MAX . " times)\n";
    exit(1);
}

// ── Step 4: Extract the summary from Manus response ──────────────────────────

function extractSummary(array $messages, string $fallbackContent): string
{
    // Look for structured output or assistant message in the response
    $items = $messages['messages'] ?? $messages['data'] ?? $messages;

    foreach ($items as $item) {
        // Try structured output first
        if (!empty($item['structured_output']['summary'])) {
            $data = $item['structured_output'];
            $headline = $data['headline'] ?? '';
            $count    = $data['article_count'] ?? '?';
            $summary  = $data['summary'];

            return "📰 *{$headline}*\n_{$count} stories today_\n\n{$summary}";
        }

        // Fall back to plain assistant message content
        if (($item['role'] ?? '') === 'assistant' && !empty($item['content'])) {
            return $item['content'];
        }
    }

    // Last resort: send the raw Horizon summary
    echo "⚠️  Could not extract Manus output, sending raw summary.\n";
    return $fallbackContent;
}

// ── Step 5: Send to Telegram ──────────────────────────────────────────────────

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

function sendToTelegram(string $token, string $chatId, string $text): void
{
    $chunks = chunkText($text);
    $total  = count($chunks);
    echo "📨 Sending {$total} message(s) to Telegram...\n";

    foreach ($chunks as $i => $chunk) {
        if ($i === 0) {
            $chunk = "☀️ *Good Morning — AI Daily Briefing*\n\n" . $chunk;
        }

        $url     = sprintf(TELEGRAM_API, $token, 'sendMessage');
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $chunk,
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => false,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
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

        echo '   ✅ Sent chunk ' . ($i + 1) . "/{$total}\n";
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

$manusToken    = requireEnv('MANUS_API_KEY');
$telegramToken = requireEnv('TELEGRAM_BOT_TOKEN');
$chatIds       = array_map('trim', explode(',', requireEnv('TELEGRAM_CHAT_IDS')));

echo "👥 Recipients: " . count($chatIds) . " chat(s)\n";

$rawSummary = getLatestSummary();
$taskId     = createManusTask($manusToken, $rawSummary);
$messages   = pollManusTask($manusToken, $taskId);
$finalText  = extractSummary($messages, $rawSummary);

foreach ($chatIds as $chatId) {
    echo "📤 Sending to chat ID: {$chatId}\n";
    sendToTelegram($telegramToken, $chatId, $finalText);
}

echo "🎉 Done! Manus-powered summary delivered to " . count($chatIds) . " recipient(s).\n";