<?php
// =====================================================================
// Ferien-CMS für Physiotherapie Conny Balmer
// - Single-File Admin: Login, Liste, Hinzufügen, Löschen
// - Schreibt vacations.json im Webroot (../vacations.json)
// - Erstes Aufrufen: Setup-Screen (legt config.php mit Passwort-Hash an)
// =====================================================================

declare(strict_types=1);

session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

const CONFIG_FILE = __DIR__ . '/config.php';
const JSON_FILE   = __DIR__ . '/../vacations.json';
const MAX_MSG_LEN = 500;

// ---- Helpers --------------------------------------------------------

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}

function csrf_check(): void {
  $t = $_POST['csrf'] ?? '';
  if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
    http_response_code(400);
    exit('Ungültiger CSRF-Token. Bitte Seite neu laden.');
  }
}

function redirect(string $path): void {
  header('Location: ' . $path, true, 303);
  exit;
}

function is_logged_in(): bool {
  return !empty($_SESSION['auth']);
}

function load_vacations(): array {
  if (!is_file(JSON_FILE)) return [];
  $raw = @file_get_contents(JSON_FILE);
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  return array_values(array_filter($data, fn($v) => is_array($v) && isset($v['from'], $v['to'])));
}

function save_vacations(array $items): bool {
  usort($items, fn($a, $b) => strcmp($a['from'], $b['from']));
  $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $tmp = JSON_FILE . '.tmp';
  if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) return false;
  return rename($tmp, JSON_FILE);
}

function valid_iso_date(string $s): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  return checkdate($m, $d, $y);
}

function format_de(string $iso): string {
  $months = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
  [$y, $m, $d] = array_map('intval', explode('-', $iso));
  return $d . '. ' . $months[$m - 1] . ' ' . $y;
}

// ---- First-run setup ------------------------------------------------

if (!is_file(CONFIG_FILE)) {
  $error = null;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
    csrf_check();
    $user = trim((string)($_POST['username'] ?? ''));
    $pw1  = (string)($_POST['password'] ?? '');
    $pw2  = (string)($_POST['password2'] ?? '');
    if ($user === '' || strlen($user) > 60) {
      $error = 'Bitte Benutzername angeben (max. 60 Zeichen).';
    } elseif (strlen($pw1) < 10) {
      $error = 'Passwort muss mindestens 10 Zeichen lang sein.';
    } elseif ($pw1 !== $pw2) {
      $error = 'Die Passwörter stimmen nicht überein.';
    } else {
      $hash = password_hash($pw1, PASSWORD_DEFAULT);
      $content = "<?php\n// Auto-generated. NICHT manuell editieren.\nreturn [\n"
               . "  'user' => " . var_export($user, true) . ",\n"
               . "  'hash' => " . var_export($hash, true) . ",\n"
               . "];\n";
      if (@file_put_contents(CONFIG_FILE, $content, LOCK_EX) === false) {
        $error = 'config.php konnte nicht geschrieben werden. Prüfe Schreibrechte im /admin/ Verzeichnis.';
      } else {
        @chmod(CONFIG_FILE, 0640);
        $_SESSION['auth'] = true;
        $_SESSION['user'] = $user;
        redirect('index.php');
      }
    }
  }
  render_setup($error ?? null);
  exit;
}

$config = require CONFIG_FILE;

// ---- Logout ---------------------------------------------------------

if (($_GET['action'] ?? '') === 'logout') {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
  }
  session_destroy();
  redirect('index.php');
}

// ---- Login ----------------------------------------------------------

if (!is_logged_in()) {
  $error = null;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    csrf_check();
    $user = (string)($_POST['username'] ?? '');
    $pw   = (string)($_POST['password'] ?? '');
    if (hash_equals($config['user'], $user) && password_verify($pw, $config['hash'])) {
      session_regenerate_id(true);
      $_SESSION['auth'] = true;
      $_SESSION['user'] = $user;
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
      redirect('index.php');
    } else {
      usleep(400000); // light rate-limit
      $error = 'Benutzername oder Passwort falsch.';
    }
  }
  render_login($error);
  exit;
}

// ---- Actions (logged in) -------------------------------------------

$flash = null;
$flash_kind = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $from = trim((string)($_POST['from'] ?? ''));
    $to   = trim((string)($_POST['to'] ?? ''));
    $msg  = trim((string)($_POST['message'] ?? ''));
    if (!valid_iso_date($from) || !valid_iso_date($to)) {
      $flash = 'Bitte beide Daten im Format JJJJ-MM-TT angeben.';
      $flash_kind = 'err';
    } elseif ($from > $to) {
      $flash = 'Das Startdatum muss vor oder gleich dem Enddatum sein.';
      $flash_kind = 'err';
    } elseif (mb_strlen($msg) > MAX_MSG_LEN) {
      $flash = 'Die Nachricht ist zu lang (max. ' . MAX_MSG_LEN . ' Zeichen).';
      $flash_kind = 'err';
    } else {
      $items = load_vacations();
      $items[] = [
        'from'    => $from,
        'to'      => $to,
        'message' => $msg,
      ];
      if (save_vacations($items)) {
        $flash = 'Ferieneintrag gespeichert.';
      } else {
        $flash = 'Speichern fehlgeschlagen. Schreibrechte auf vacations.json prüfen.';
        $flash_kind = 'err';
      }
    }
  } elseif ($action === 'delete') {
    $idx = (int)($_POST['index'] ?? -1);
    $items = load_vacations();
    if ($idx >= 0 && $idx < count($items)) {
      array_splice($items, $idx, 1);
      if (save_vacations($items)) {
        $flash = 'Eintrag gelöscht.';
      } else {
        $flash = 'Löschen fehlgeschlagen.';
        $flash_kind = 'err';
      }
    }
  }
}

$items = load_vacations();
$today = date('Y-m-d');

render_dashboard($items, $today, $flash, $flash_kind, $config['user'] ?? '');
exit;

// =====================================================================
// Views
// =====================================================================

function page_header(string $title): void {
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= h($title) ?> · Ferien-CMS</title>
    <style>
      :root {
        --teal-400: #5AC0D1; --teal-500: #3aa6b8; --teal-600: #2a8a9b; --teal-700: #226d7c;
        --ink: #0f1f24; --slate-700: #2a3e46; --slate-500: #56707a; --slate-400: #7e9098;
        --slate-200: #d8e1e4; --slate-100: #eef2f4; --paper: #fafaf6; --white: #ffffff;
        --err: #b53b3b; --err-bg: #fdecec; --ok-bg: #e8f7ec; --ok: #2f7a3c;
      }
      * { box-sizing: border-box; }
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, system-ui, sans-serif;
        background: var(--paper); color: var(--ink); margin: 0; line-height: 1.5;
      }
      .wrap { max-width: 720px; margin: 0 auto; padding: 40px 24px 80px; }
      .topbar {
        display: flex; justify-content: space-between; align-items: center;
        padding: 14px 24px; background: var(--white); border-bottom: 1px solid var(--slate-100);
      }
      .topbar__brand { font-weight: 600; color: var(--ink); }
      .topbar__brand span { color: var(--teal-600); }
      .topbar a { color: var(--slate-500); text-decoration: none; font-size: 0.92rem; }
      .topbar a:hover { color: var(--ink); }
      h1 { font-size: 1.6rem; margin: 0 0 8px; letter-spacing: -0.01em; }
      .lead { color: var(--slate-500); margin: 0 0 32px; }
      .card {
        background: var(--white); border-radius: 14px; padding: 24px;
        border: 1px solid var(--slate-100); box-shadow: 0 1px 2px rgba(15,31,36,0.04);
        margin-bottom: 20px;
      }
      .card h2 { font-size: 1.05rem; margin: 0 0 16px; letter-spacing: 0.02em; text-transform: uppercase; color: var(--slate-500); }
      label { display: block; font-size: 0.9rem; color: var(--slate-700); margin-bottom: 6px; font-weight: 500; }
      input[type=text], input[type=password], input[type=date], textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--slate-200);
        border-radius: 8px; font-size: 0.98rem; font-family: inherit; background: var(--white);
      }
      input:focus, textarea:focus { outline: 2px solid var(--teal-400); outline-offset: 1px; border-color: var(--teal-400); }
      textarea { resize: vertical; min-height: 80px; }
      .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
      .field { margin-bottom: 14px; }
      .btn {
        display: inline-block; padding: 10px 18px; border-radius: 999px; border: 0;
        font-weight: 600; font-size: 0.95rem; cursor: pointer; font-family: inherit;
        transition: background 160ms ease, color 160ms ease;
      }
      .btn--primary { background: var(--teal-400); color: var(--ink); }
      .btn--primary:hover { background: var(--teal-500); color: var(--white); }
      .btn--ghost { background: transparent; color: var(--slate-500); padding: 6px 10px; font-size: 0.85rem; }
      .btn--ghost:hover { color: var(--err); }
      .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.94rem; }
      .alert--ok { background: var(--ok-bg); color: var(--ok); }
      .alert--err { background: var(--err-bg); color: var(--err); }
      .list { list-style: none; padding: 0; margin: 0; }
      .list li {
        display: flex; justify-content: space-between; align-items: flex-start;
        padding: 14px 0; border-top: 1px solid var(--slate-100); gap: 16px;
      }
      .list li:first-child { border-top: 0; }
      .list__dates { font-weight: 600; color: var(--ink); }
      .list__msg { color: var(--slate-500); font-size: 0.92rem; margin-top: 4px; white-space: pre-line; }
      .pill {
        display: inline-block; font-size: 0.74rem; padding: 2px 8px; border-radius: 999px;
        background: var(--teal-400); color: var(--ink); margin-left: 8px; font-weight: 600; letter-spacing: 0.02em;
      }
      .pill--past { background: var(--slate-100); color: var(--slate-500); }
      .pill--future { background: var(--slate-100); color: var(--slate-700); }
      .empty { color: var(--slate-400); font-style: italic; padding: 12px 0; }
      .hint { font-size: 0.85rem; color: var(--slate-400); margin-top: 6px; }
      .auth-wrap { max-width: 380px; margin: 80px auto; padding: 0 24px; }
      .auth-wrap h1 { text-align: center; margin-bottom: 24px; }
      @media (max-width: 540px) {
        .row { grid-template-columns: 1fr; }
        .list li { flex-direction: column; }
      }
    </style>
  </head>
  <body>
  <?php
}

function page_footer(): void {
  ?>
  </body></html>
  <?php
}

function render_setup(?string $error): void {
  page_header('Erstkonfiguration');
  ?>
  <div class="auth-wrap">
    <h1>Erstkonfiguration</h1>
    <p class="lead" style="text-align:center;">Lege Benutzername und Passwort fest.</p>
    <div class="card">
      <?php if ($error): ?><div class="alert alert--err"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="setup">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field">
          <label for="username">Benutzername</label>
          <input id="username" type="text" name="username" required autocomplete="username">
        </div>
        <div class="field">
          <label for="password">Passwort (mind. 10 Zeichen)</label>
          <input id="password" type="password" name="password" required autocomplete="new-password" minlength="10">
        </div>
        <div class="field">
          <label for="password2">Passwort wiederholen</label>
          <input id="password2" type="password" name="password2" required autocomplete="new-password" minlength="10">
        </div>
        <button class="btn btn--primary" type="submit">Speichern und einloggen</button>
        <p class="hint">Diese Seite erscheint nur beim ersten Aufruf. Danach Anmeldung mit den hinterlegten Daten.</p>
      </form>
    </div>
  </div>
  <?php
  page_footer();
}

function render_login(?string $error): void {
  page_header('Anmeldung');
  ?>
  <div class="auth-wrap">
    <h1>Ferien-CMS</h1>
    <div class="card">
      <?php if ($error): ?><div class="alert alert--err"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field">
          <label for="username">Benutzername</label>
          <input id="username" type="text" name="username" required autocomplete="username" autofocus>
        </div>
        <div class="field">
          <label for="password">Passwort</label>
          <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn--primary" type="submit">Anmelden</button>
      </form>
    </div>
  </div>
  <?php
  page_footer();
}

function render_dashboard(array $items, string $today, ?string $flash, string $flash_kind, string $user): void {
  page_header('Ferien verwalten');
  ?>
  <div class="topbar">
    <div class="topbar__brand">Ferien-<span>CMS</span></div>
    <div>
      <span style="color: var(--slate-400); font-size: 0.88rem; margin-right: 12px;">angemeldet als <?= h($user) ?></span>
      <a href="?action=logout">Abmelden</a>
    </div>
  </div>
  <div class="wrap">
    <h1>Ferienabwesenheiten</h1>
    <p class="lead">Während der eingetragenen Zeiträume wird auf der Website ein Hinweis-Overlay angezeigt.</p>

    <?php if ($flash): ?>
      <div class="alert alert--<?= $flash_kind === 'err' ? 'err' : 'ok' ?>"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="card">
      <h2>Neuer Eintrag</h2>
      <form method="post">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="row">
          <div>
            <label for="from">Von</label>
            <input id="from" type="date" name="from" required>
          </div>
          <div>
            <label for="to">Bis</label>
            <input id="to" type="date" name="to" required>
          </div>
        </div>
        <div class="field">
          <label for="message">Nachricht (optional)</label>
          <textarea id="message" name="message" maxlength="<?= MAX_MSG_LEN ?>" placeholder="z.B. In dringenden Fällen wenden Sie sich an…"></textarea>
          <p class="hint">Erscheint im Overlay unter dem Datum. Zeilenumbrüche werden übernommen.</p>
        </div>
        <button class="btn btn--primary" type="submit">Speichern</button>
      </form>
    </div>

    <div class="card">
      <h2>Eingetragene Zeiträume</h2>
      <?php if (empty($items)): ?>
        <p class="empty">Noch keine Einträge vorhanden.</p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($items as $i => $v):
            $status = '';
            $pill_class = '';
            if ($v['to'] < $today) { $status = 'vorbei'; $pill_class = 'pill--past'; }
            elseif ($v['from'] > $today) { $status = 'geplant'; $pill_class = 'pill--future'; }
            else { $status = 'aktiv'; $pill_class = ''; }
          ?>
            <li>
              <div>
                <div class="list__dates">
                  <?= h(format_de($v['from'])) ?> – <?= h(format_de($v['to'])) ?>
                  <span class="pill <?= $pill_class ?>"><?= $status ?></span>
                </div>
                <?php if (!empty($v['message'])): ?>
                  <div class="list__msg"><?= h($v['message']) ?></div>
                <?php endif; ?>
              </div>
              <form method="post" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="index" value="<?= (int)$i ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="btn btn--ghost" type="submit">Löschen</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
  <?php
  page_footer();
}
