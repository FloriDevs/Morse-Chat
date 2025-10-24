<?php
/*
Morse Messenger - Single-file PHP demo application
- Speicherung: SQLite (file: morse_messenger.sqlite)
- Auth: Registrierung mit name + password (password_hash)
- Chat-Anfrage: Nachrichten werden nur ermöglicht, wenn Empfänger Chat akzeptiert
- Sonderregel: Der Benutzer mit der ID 1 (z.B. Admin) kann immer alle anderen Benutzer anschreiben und von allen angeschrieben werden.
- Nachrichten werden in Morse (./-) gespeichert. Oben ist die Morse-Tabelle zum Schreiben/Lesen

Installation:
1. PHP 8+ (mit PDO_SQLITE) benötigt.
2. Datei in einen Web-Ordner legen (z.B. ~/www/morse_messenger.php) oder lokalen Server starten:
   php -S localhost:8000
3. Beim ersten Aufruf wird die SQLite DB automatisch angelegt: morse_messenger.sqlite

Hinweis: Dies ist ein einfaches Demo. Für Produktion: HTTPS, CSRF-Token, Sessions härten, Input-Validierung erweitern.
*/

session_start();
$dbfile = __DIR__ . '/morse_messenger.sqlite';
$first = !file_exists($dbfile);
$pdo = new PDO('sqlite:' . $dbfile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($first) {
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, password TEXT);");
    $pdo->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY AUTOINCREMENT, from_id INTEGER, to_id INTEGER, status TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, from_id INTEGER, to_id INTEGER, morse TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
}

// Morse mapping
$morse_map = [
    'A' => '.-', 'B' => '-...', 'C' => '-.-.', 'D' => '-..', 'E' => '.', 'F' => '..-.',
    'G' => '--.', 'H' => '....', 'I' => '..', 'J' => '.---', 'K' => '-.-', 'L' => '.-..',
    'M' => '--', 'N' => '-.', 'O' => '---', 'P' => '.--.', 'Q' => '--.-', 'R' => '.-.',
    'S' => '...', 'T' => '-', 'U' => '..-', 'V' => '...-', 'W' => '.--', 'X' => '-..-',
    'Y' => '-.--', 'Z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---',
    '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....',
    '7' => '--...', '8' => '---..', '9' => '----.', ' ' => '/',
    ',' => '--..--', '.' => '.-.-.-', '?' => '..--..', '!' => '-.-.--',
    ':' => '---...', '\'' => '.----.', '"' => '.-..-.', '-' => '-....-',
    '/' => '-..-.', '(' => '-.--.', ')' => '-.--.-'
];
$rev_map = array_flip($morse_map);

function encode_morse($text, $map) {
    $text = mb_strtoupper($text);
    $out = [];
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chars as $c) {
        $out[] = $map[$c] ?? '?';
    }
    return implode(' ', $out);
}
function decode_morse($morse, $rev) {
    $parts = preg_split('/\s+/', trim($morse));
    $out = '';
    foreach ($parts as $p) {
        if ($p === '/') { $out .= ' '; continue; }
        $out .= $rev[$p] ?? '#';
    }
    return $out;
}

// routing
$action = $_GET['action'] ?? '';
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $pw = $_POST['password'] ?? '';
    if (!$name || !$pw) { $_SESSION['flash'] = 'Name und Passwort erforderlich.'; header('Location: ?'); exit; }
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (name,password) VALUES (?,?)');
    try { $stmt->execute([$name,$hash]); $_SESSION['flash'] = 'Registrierung erfolgreich. Bitte einloggen.'; }
    catch (Exception $e) { $_SESSION['flash'] = 'Fehler: Name schon vergeben?'; }
    header('Location: ?'); exit;
}
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $pw = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM users WHERE name=?'); $stmt->execute([$name]); $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($pw, $user['password'])) { $_SESSION['user_id'] = $user['id']; $_SESSION['user_name'] = $user['name']; header('Location: ?'); exit; }
    $_SESSION['flash'] = 'Login fehlgeschlagen.'; header('Location: ?'); exit;
}
if ($action === 'logout') { session_destroy(); header('Location: ?'); exit; }

function require_login() { if (empty($_SESSION['user_id'])) { header('Location: ?'); exit; } }

if ($action === 'send_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    $to = (int)$_POST['to_id']; $from = $_SESSION['user_id'];
    if ($to === $from) { $_SESSION['flash']='Du kannst dir selbst keinen Chat anfragen.'; header('Location: ?'); exit; }
    $stmt = $GLOBALS['pdo']->prepare('INSERT INTO requests (from_id,to_id,status) VALUES (?,?,"pending")');
    try { $stmt->execute([$from,$to]); $_SESSION['flash']='Anfrage gesendet.'; } catch (Exception $e) { $_SESSION['flash']='Fehler oder Anfrage schon vorhanden.'; }
    header('Location: ?'); exit;
}
if ($action === 'respond_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    $rid = (int)$_POST['rid']; $resp = $_POST['resp'] === 'accept' ? 'accepted' : 'rejected';
    $stmt = $pdo->prepare('UPDATE requests SET status=? WHERE id=? AND to_id=?'); $stmt->execute([$resp,$rid,$_SESSION['user_id']]);
    $_SESSION['flash']='Antwort gespeichert.'; header('Location: ?'); exit;
}
if ($action === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    $to = (int)$_POST['to_id']; $morse = trim($_POST['morse'] ?? '');
    if ($morse === '') { $_SESSION['flash']='Nachricht leer.'; header('Location: ?'); exit; }

    // --- BERECHTIGUNGSPRÜFUNG: Darf der Nutzer die Nachricht senden? ---
    $is_allowed = false;
    $user_id = $_SESSION['user_id'];

    // 1. Sonderregel (Admin-Modus): Senden ist erlaubt, wenn der SENDER (user_id) die ID 1 ist,
    //    ODER der Empfänger (to) die ID 1 ist.
    if ($user_id === 1 || $to === 1) {
        $is_allowed = true;
    }

    // 2. Reguläre Regel: Prüfen, ob eine beidseitig akzeptierte Chat-Anfrage existiert.
    if (!$is_allowed) {
        $stmt = $pdo->prepare('SELECT status FROM requests WHERE ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?)) AND status="accepted"');
        $stmt->execute([$user_id, $to, $to, $user_id]);
        if ($stmt->fetch()) {
            $is_allowed = true;
        }
    }

    if (!$is_allowed) {
        $_SESSION['flash']='Chat nicht freigegeben.';
        header('Location: ?');
        exit;
    }
    // --- Ende BERECHTIGUNGSPRÜFUNG ---
    
    // Nachricht speichern
    $stmt = $pdo->prepare('INSERT INTO messages (from_id,to_id,morse) VALUES (?,?,?)'); $stmt->execute([$_SESSION['user_id'],$to,$morse]);
    header('Location: ?action=chat&with=' . $to); exit;
}

// Helper: get users
$users = $pdo->query('SELECT id,name FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Flash
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// HTML output
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Morse Messenger</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:20px;background:#f7f7f9}
.container{max-width:900px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,0.06)}
.header{display:flex;justify-content:space-between;align-items:center}
.morse-table{background:#fffbe6;padding:10px;border-radius:6px;margin-bottom:12px}
.user-list{display:flex;gap:8px;flex-wrap:wrap}
.card{border:1px solid #eee;padding:10px;border-radius:6px}
.message{padding:6px;border-radius:6px;margin-bottom:6px;border:1px solid #eee}
.form-inline{display:flex;gap:8px}
.small{font-size:0.9em;color:#666}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Morse Messenger</h1>
    <div>
      <?php if(!empty($_SESSION['user_id'])): ?>
        Angemeldet als <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong> | <a href="?action=logout">Logout</a>
      <?php else: ?>
        <a href="#register">Registrieren</a> • <a href="#login">Login</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if($flash): ?><p class="card small"><?php echo htmlspecialchars($flash); ?></p><?php endif; ?>

  <!-- Morse Tabelle oben -->
  <div class="morse-table">
    <strong>Morse-Tabelle (zum Schreiben / Lesen)</strong>
    <div style="margin-top:8px;font-family:monospace;white-space:pre-wrap">
<?php
foreach ($morse_map as $k=>$v) echo sprintf("%s: %s\t", $k, $v);
?>
    </div>
  </div>

  <?php if(empty($_SESSION['user_id'])): ?>
    <div style="display:flex;gap:20px">
      <div class="card" style="flex:1">
        <h3 id="register">Registrieren</h3>
        <form method="post" action="?action=register">
          <label>Name<br><input name="name"></label><br>
          <label>Passwort<br><input name="password" type="password"></label><br><br>
          <button>Registrieren</button>
        </form>
      </div>
      <div class="card" style="flex:1">
        <h3 id="login">Login</h3>
        <form method="post" action="?action=login">
          <label>Name<br><input name="name"></label><br>
          <label>Passwort<br><input name="password" type="password"></label><br><br>
          <button>Login</button>
        </form>
      </div>
    </div>
  <?php else: ?>

    <!-- Benutzerliste + Anfragen -->
    <h3>Alle registrierten Benutzer</h3>
    <div class="user-list">
      <?php foreach($users as $u): if ($u['id']==$_SESSION['user_id']) continue; ?>
        <div class="card">
          <strong><?php echo htmlspecialchars($u['name']); ?></strong><br>
          <form method="post" action="?action=send_request" style="margin-top:8px">
            <input type="hidden" name="to_id" value="<?php echo $u['id']; ?>">
            <button>Chat anfragen</button>
          </form>
          <div class="small">ID: <?php echo $u['id']; ?></div>
          <div style="margin-top:6px"><a href="?action=chat&with=<?php echo $u['id']; ?>">Zum Chat</a></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- eingehende Anfragen -->
    <h3 style="margin-top:18px">Eingehende Chat-Anfragen</h3>
    <div>
      <?php
        $stmt = $pdo->prepare('SELECT r.*, u.name as from_name FROM requests r JOIN users u ON u.id=r.from_id WHERE r.to_id=? ORDER BY r.created_at DESC');
        $stmt->execute([$_SESSION['user_id']]); $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$reqs) echo '<div class="small">Keine Anfragen.</div>';
        foreach($reqs as $r): ?>
          <div class="card">
            <div><strong><?php echo htmlspecialchars($r['from_name']); ?></strong> hat angefragt — Status: <em><?php echo $r['status']; ?></em></div>
            <?php if($r['status']=='pending'): ?>
              <form method="post" action="?action=respond_request" class="form-inline" style="margin-top:8px">
                <input type="hidden" name="rid" value="<?php echo $r['id']; ?>">
                <button name="resp" value="accept">Akzeptieren</button>
                <button name="resp" value="reject">Ablehnen</button>
              </form>
            <?php endif; ?>
          </div>
      <?php endforeach; ?>
    </div>

    <!-- Chat view -->
    <?php if(isset($_GET['action']) && $_GET['action']==='chat' && isset($_GET['with'])):
      $with = (int)$_GET['with'];
      // lade benutzer
      $stmt = $pdo->prepare('SELECT id,name FROM users WHERE id=?'); $stmt->execute([$with]); $other = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$other) { echo "<p>Benutzer nicht gefunden.</p>"; }
      else {
        echo "<h3>Chat mit " . htmlspecialchars($other['name']) . "</h3>";
        // lade nachrichten beider richtungen
        $stmt = $pdo->prepare('SELECT m.*, u.name as from_name FROM messages m JOIN users u ON u.id=m.from_id WHERE (from_id=? AND to_id=?) OR (from_id=? AND to_id=?) ORDER BY created_at');
        $stmt->execute([$_SESSION['user_id'],$with,$with,$_SESSION['user_id']]); $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<div style="max-height:300px;overflow:auto;padding:8px;border:1px solid #eee;margin-bottom:8px">';
        foreach($msgs as $m) {
            $decoded = decode_morse($m['morse'], $rev_map);
            echo '<div class="message"><strong>' . htmlspecialchars($m['from_name']) . '</strong> <span class="small">(' . $m['created_at'] . ')</span><br>';
            echo '<div style="font-family:monospace">Morse: ' . htmlspecialchars($m['morse']) . '</div>';
            echo '<div>Text: ' . htmlspecialchars($decoded) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        // Nachricht senden
        ?>
        <div class="card">
          <form method="post" action="?action=send_message">
            <input type="hidden" name="to_id" value="<?php echo $with; ?>">
            <label>Schreibe in Morse (Punkte . Bindestriche -; Worttrennung mit /; Buchstaben durch Leerzeichen)</label><br>
            <textarea name="morse" id="morse_input" rows="4" style="width:100%"></textarea><br>
            <div style="margin-top:6px;display:flex;gap:6px">
              <button type="button" onclick="appendM('.')">.</button>
              <button type="button" onclick="appendM('-')">-</button>
              <button type="button" onclick="appendM(' ')">Leer</button>
              <button type="button" onclick="appendM('/')">/</button>
              <button type="button" onclick="backspace()">←</button>
            </div>
            <div style="margin-top:8px"><button>Senden</button></div>
          </form>
        </div>
        <script>
          function appendM(s){ let el=document.getElementById('morse_input'); el.value = (el.value + s).replace(/\s+/g,' ').trimStart(); el.focus(); }
          function backspace(){ let el=document.getElementById('morse_input'); el.value = el.value.slice(0,-1); }
        </script>
    <?php }
    endif; ?>

  <?php endif; ?>

  <hr>
  <div class="small">Hinweis: Dies ist ein Demo-Projekt. Für Sicherheit im Einsatz erweitern (HTTPS, Input-Sanitizing, CSRF).</div>
</div>
</body>
</html>
