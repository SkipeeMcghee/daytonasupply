<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function callbackParam(string $key, int $maxLength): ?string
{
    $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    if ($value === null || $value === false) {
        return null;
    }

    if (is_array($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (strlen($value) > $maxLength) {
        return substr($value, 0, $maxLength);
    }

    return $value;
}

function escapeHtml(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$code = callbackParam('code', 2048);
$realmId = callbackParam('realmId', 64);
$state = callbackParam('state', 512);
$error = callbackParam('error', 255);
$errorDescription = callbackParam('error_description', 2048);

$hasError = $error !== null;
$realmIdIsValid = $realmId === null || preg_match('/^\d{1,32}$/', $realmId) === 1;
$isSuccess = !$hasError && $code !== null && $realmId !== null && $realmIdIsValid;

if ($hasError || (!$isSuccess && ($code !== null || $realmId !== null || $state !== null))) {
    http_response_code(400);
}

$statusHeading = $isSuccess ? 'QuickBooks authorization received' : 'QuickBooks authorization not completed';
$statusClass = $isSuccess ? 'status-success' : 'status-error';
$statusMessage = $isSuccess
    ? 'The production OAuth callback was received successfully. Copy the values below for the manual rollout steps.'
    : 'The callback did not include a complete successful authorization response.';

if ($hasError && $errorDescription !== null) {
    $statusMessage = 'Intuit returned an error for the authorization request.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickBooks OAuth Callback</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f1ea;
            --panel: #fffdf8;
            --ink: #1c2330;
            --muted: #596273;
            --border: #d7d0c5;
            --success: #1d6b3c;
            --success-bg: #e8f4eb;
            --error: #9f2d2d;
            --error-bg: #fdecec;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background: linear-gradient(180deg, #f7f3ec 0%, var(--bg) 100%);
            color: var(--ink);
        }

        .wrap {
            max-width: 760px;
            margin: 0 auto;
            padding: 48px 20px 72px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 18px 48px rgba(28, 35, 48, 0.08);
            padding: 32px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(1.9rem, 4vw, 2.5rem);
            line-height: 1.1;
        }

        p {
            margin: 0 0 16px;
            color: var(--muted);
            line-height: 1.6;
        }

        .status {
            display: inline-block;
            margin-bottom: 20px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .status-success {
            color: var(--success);
            background: var(--success-bg);
        }

        .status-error {
            color: var(--error);
            background: var(--error-bg);
        }

        dl {
            margin: 24px 0 0;
            display: grid;
            grid-template-columns: minmax(140px, 180px) 1fr;
            gap: 12px 16px;
        }

        dt {
            font-weight: 700;
        }

        dd {
            margin: 0;
        }

        .value {
            display: block;
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfaf5;
            color: var(--ink);
            font-family: Consolas, "Courier New", monospace;
            font-size: 0.95rem;
            line-height: 1.5;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .note {
            margin-top: 24px;
            font-size: 0.95rem;
        }

        @media (max-width: 640px) {
            .panel {
                padding: 24px;
            }

            dl {
                grid-template-columns: 1fr;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="panel">
            <span class="status <?php echo escapeHtml($statusClass); ?>">
                <?php echo $isSuccess ? 'Authorization Succeeded' : 'Authorization Incomplete'; ?>
            </span>
            <h1><?php echo escapeHtml($statusHeading); ?></h1>
            <p><?php echo escapeHtml($statusMessage); ?></p>

            <dl>
                <?php if ($isSuccess): ?>
                    <dt>Authorization Code</dt>
                    <dd><span class="value"><?php echo escapeHtml($code); ?></span></dd>

                    <dt>Realm ID</dt>
                    <dd><span class="value"><?php echo escapeHtml($realmId); ?></span></dd>

                    <?php if ($state !== null): ?>
                        <dt>State</dt>
                        <dd><span class="value"><?php echo escapeHtml($state); ?></span></dd>
                    <?php endif; ?>
                <?php else: ?>
                    <dt>Error</dt>
                    <dd><span class="value"><?php echo escapeHtml($error ?? 'Missing or invalid callback parameters'); ?></span></dd>

                    <?php if ($errorDescription !== null): ?>
                        <dt>Error Description</dt>
                        <dd><span class="value"><?php echo escapeHtml($errorDescription); ?></span></dd>
                    <?php endif; ?>

                    <?php if ($realmId !== null && !$realmIdIsValid): ?>
                        <dt>Realm ID</dt>
                        <dd><span class="value"><?php echo escapeHtml($realmId); ?></span></dd>
                    <?php endif; ?>

                    <?php if ($state !== null): ?>
                        <dt>State</dt>
                        <dd><span class="value"><?php echo escapeHtml($state); ?></span></dd>
                    <?php endif; ?>
                <?php endif; ?>
            </dl>

            <p class="note">This endpoint only displays callback values for manual handling. It does not exchange tokens or store OAuth secrets.</p>
        </section>
    </div>
</body>
</html>