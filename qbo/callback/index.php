<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function callback_value(string $key, int $maxLength = 2048): ?string
{
    $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    if ($value === null || $value === false) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

function escape_html(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$code = callback_value('code');
$realmId = callback_value('realmId', 128);
$state = callback_value('state', 512);
$error = callback_value('error', 256);
$errorDescription = callback_value('error_description', 2048)
    ?? callback_value('description', 2048);

$realmIdIsValid = $realmId === null || preg_match('/^\d+$/', $realmId) === 1;
$hasOAuthError = $error !== null;
$isAuthorized = !$hasOAuthError && $code !== null && $realmId !== null && $realmIdIsValid;

$statusCode = $isAuthorized ? 200 : 400;
http_response_code($statusCode);

$pageTitle = $isAuthorized
    ? 'QuickBooks Authorization Received'
    : 'QuickBooks Authorization Not Completed';

$statusHeading = $isAuthorized ? 'Authorization succeeded' : 'Authorization failed';
$statusText = $isAuthorized
    ? 'The Intuit callback was received successfully. Copy the values below for the initial production rollout.'
    : 'The callback did not include a complete successful authorization response.';

if (!$hasOAuthError && $realmId !== null && !$realmIdIsValid) {
    $error = 'invalid_realmId';
    $errorDescription = 'The returned realmId was not in the expected numeric format.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo escape_html($pageTitle); ?></title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f7fb;
            --panel: #ffffff;
            --border: #d6e0ea;
            --text: #1f2933;
            --muted: #52606d;
            --success: #17603a;
            --success-bg: #e8f7ee;
            --error: #9b1c1c;
            --error-bg: #fdecec;
            --code-bg: #f7fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
            color: var(--text);
        }

        main {
            max-width: 760px;
            margin: 48px auto;
            padding: 0 20px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }

        .status {
            margin: 0 0 18px;
            padding: 14px 16px;
            border-radius: 10px;
            font-weight: 700;
        }

        .status.success {
            color: var(--success);
            background: var(--success-bg);
        }

        .status.error {
            color: var(--error);
            background: var(--error-bg);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 1.9rem;
            line-height: 1.2;
        }

        p {
            margin: 0 0 16px;
            color: var(--muted);
            line-height: 1.6;
        }

        dl {
            margin: 24px 0 0;
            display: grid;
            grid-template-columns: minmax(140px, 180px) 1fr;
            gap: 12px 16px;
            align-items: start;
        }

        dt {
            font-weight: 700;
        }

        dd {
            margin: 0;
        }

        code {
            display: inline-block;
            max-width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--code-bg);
            color: var(--text);
            font-family: Consolas, Monaco, monospace;
            overflow-wrap: anywhere;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .note {
            margin-top: 24px;
            font-size: 0.95rem;
        }

        @media (max-width: 640px) {
            main {
                margin: 24px auto;
            }

            .panel {
                padding: 22px;
            }

            dl {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <div class="status <?php echo $isAuthorized ? 'success' : 'error'; ?>">
            <?php echo escape_html($statusHeading); ?>
        </div>

        <h1><?php echo escape_html($pageTitle); ?></h1>
        <p><?php echo escape_html($statusText); ?></p>

        <?php if ($isAuthorized): ?>
            <dl>
                <dt>Authorization code</dt>
                <dd><code><?php echo escape_html($code); ?></code></dd>

                <dt>Realm ID</dt>
                <dd><code><?php echo escape_html($realmId); ?></code></dd>

                <dt>State</dt>
                <dd><code><?php echo escape_html($state ?? 'Not provided'); ?></code></dd>
            </dl>
        <?php else: ?>
            <dl>
                <dt>Error</dt>
                <dd><code><?php echo escape_html($error ?? 'Authorization response was incomplete.'); ?></code></dd>

                <dt>Description</dt>
                <dd><code><?php echo escape_html($errorDescription ?? 'No additional error description was provided.'); ?></code></dd>

                <dt>State</dt>
                <dd><code><?php echo escape_html($state ?? 'Not provided'); ?></code></dd>

                <dt>Code</dt>
                <dd><code><?php echo escape_html($code ?? 'Not provided'); ?></code></dd>

                <dt>Realm ID</dt>
                <dd><code><?php echo escape_html($realmId ?? 'Not provided'); ?></code></dd>
            </dl>
        <?php endif; ?>

        <p class="note">This endpoint displays callback values only. It does not exchange tokens, store credentials, or persist any authorization data.</p>
    </section>
</main>
</body>
</html>